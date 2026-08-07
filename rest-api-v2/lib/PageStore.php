<?php
declare(strict_types=1);

require_once __DIR__ . '/ApiException.php';
require_once __DIR__ . '/SnapshotStore.php';
require_once __DIR__ . '/Audit.php';

/**
 * ページの読み書き（ファイルのみ・正本は wiki/*.txt）。
 *
 * 書き込みは必ず次の順序で行う:
 *   1. 入力検証（ページ名・空本文・サイズ上限・保護ページ・READONLY）
 *   2. flock によるロック取得（data/locks/、リトライ付き）
 *   3. CAS: 現在ファイルの sha1 == base_sha1 でなければ 409
 *   4. is_freeze() / is_editable() / is_page_writable() チェック（PukiWiki ロード時）
 *   5. 書き込み前スナップショット（既存内容の退避）
 *   6. page_write()（PukiWiki ロード時）/ 原子的書き込み（スタンドアロン時）
 *   7. ファイル再読込 → new_sha1 を実内容から計算
 *      ※ page_write() は make_str_rules()/add_author_info() で本文を変形し、
 *        実質無変更なら黙って return する。送信本文の sha1 は信用しない。
 *   8. 書き込み後スナップショット + 監査ログ
 *
 * 空本文は 400 で拒否する（page_write() の空本文＝ページ削除挙動を防ぐ）。
 * 削除 API は提供しない（削除・凍結・リネームは Web UI の管理操作で行う）。
 *
 * 読み取りにも PukiWiki 本体の閲覧認可を適用する:
 *   - ':' 始まりのシステムページは read も 403（write と対称）
 *   - $read_auth による閲覧制限ページは read/revisions が 403、検索からは除外
 * @version v2.0
 */
final class PageStore
{
    /** sha1('') — 新規ページ作成時に base_sha1 として渡すセンチネル */
    public const EMPTY_SHA1 = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';

    /** ページ本文の既定上限（bytes）。PKWK_REST_MAX_BODY_BYTES で上書き可 */
    public const DEFAULT_MAX_BODY_BYTES = 1_048_576;

    /** ロック取得のリトライ（200ms × 10 = 最大約 2 秒） */
    private const LOCK_RETRIES  = 10;
    private const LOCK_WAIT_US  = 200_000;

    public function __construct(
        private string        $wiki_dir,
        private string        $lock_dir,
        private array         $protected_pages,
        private SnapshotStore $snapshots,
        private Audit         $audit,
    ) {}

    // -------------------------------------------------------------------------
    // 読み取り
    // -------------------------------------------------------------------------

    /**
     * ページの内容とメタデータを返す。存在しなければ 404。
     *
     * @return array{page: string, sha1: string, content: string, size: int,
     *               mtime: int, updated_at: string,
     *               is_frozen: bool|null, is_editable: bool|null}
     */
    public function read(string $page): array
    {
        $this->validatePageName($page);
        $this->assertReadable($page);
        $file = $this->filePath($page);
        if (!is_file($file)) {
            throw new ApiException(404, "Page '{$page}' not found", 'page_not_found');
        }
        $content = file_get_contents($file);
        if ($content === false) {
            throw new ApiException(500, 'Cannot read page file', 'read_failed');
        }
        $mtime = (int)filemtime($file);

        return [
            'page'        => $page,
            'sha1'        => sha1($content),
            'content'     => $content,
            'size'        => strlen($content),
            'mtime'       => $mtime,
            'updated_at'  => date('c', $mtime),
            'is_frozen'   => function_exists('is_freeze')   ? (bool)is_freeze($page)   : null,
            'is_editable' => function_exists('is_editable') ? (bool)is_editable($page) : null,
        ];
    }

    /**
     * 全ページの一覧（名前順・ページネーション）。
     * @return array{pages: array<array{name: string, mtime: int, updated_at: string}>, total: int}
     */
    public function listPages(int $limit = 100, int $offset = 0): array
    {
        $all = [];
        foreach ($this->scanPages() as $page => $file) {
            $mtime = (int)filemtime($file);
            $all[] = ['name' => $page, 'mtime' => $mtime, 'updated_at' => date('c', $mtime)];
        }
        usort($all, static fn($a, $b) => strcmp($a['name'], $b['name']));

        return [
            'pages' => array_slice($all, $offset, $limit),
            'total' => count($all),
        ];
    }

    /**
     * 素朴な全文検索（ファイル走査・大文字小文字無視・部分一致）。
     * ページ名と本文の両方を対象にする。
     *
     * @return array<array{page: string, snippet: string, name_match: bool}>
     */
    public function search(string $query, int $limit = 20): array
    {
        $results = [];
        foreach ($this->scanPages() as $page => $file) {
            // 閲覧不可ページは本文を読む前に黙って除外する（本体 search プラグインと同じ挙動。
            // ここで assertReadable() を使うと1ページの閲覧不可で検索全体が 403 になるため使わない）
            if (!$this->canRead($page)) {
                continue;
            }
            $name_hit = mb_stripos($page, $query, 0, 'UTF-8') !== false;

            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            $pos = mb_stripos($content, $query, 0, 'UTF-8');

            if (!$name_hit && $pos === false) {
                continue;
            }

            $snippet = '';
            if ($pos !== false) {
                $start   = max(0, $pos - 40);
                $snippet = mb_substr($content, $start, 80 + mb_strlen($query, 'UTF-8'), 'UTF-8');
                $snippet = str_replace(["\r", "\n"], ' ', $snippet);
                if ($start > 0) {
                    $snippet = '…' . $snippet;
                }
                $snippet .= '…';
            }

            $results[] = ['page' => $page, 'snippet' => $snippet, 'name_match' => $name_hit];
            if (count($results) >= $limit) {
                break;
            }
        }
        return $results;
    }

    // -------------------------------------------------------------------------
    // 下書き（サイト独自の draft 機能。lib/draft.php があるときだけ使える）
    //
    // 公開（publish）は API から行わない。下書きを本ページへ反映するのは Web UI の
    // 役目で、API から公開できないこと自体が安全装置になっている。
    // 下書きは page_write() を通らない（draft_write() が本文をそのまま書く）ため、
    // $str_rules による &now; 等の破壊は原理的に起きない。
    // -------------------------------------------------------------------------

    /** 下書き機能が使えるか（PukiWiki 本体 + サイト独自の lib/draft.php が必要） */
    public static function draftAvailable(): bool
    {
        return defined('DRAFT_DIR')
            && function_exists('has_draft')
            && function_exists('draft_write')
            && function_exists('draft_delete')
            && function_exists('get_draft_with_meta')
            && function_exists('get_draft_list');
    }

    /** 使えない環境なら 501 を投げる */
    private static function assertDraftAvailable(): void
    {
        if (!self::draftAvailable()) {
            throw new ApiException(
                501,
                'Draft support is not available on this wiki (lib/draft.php not found).',
                'draft_unsupported'
            );
        }
    }

    /**
     * ページ名を下書き側の正規形に揃える。
     *
     * draft_write() だけが strip_bracket() を掛け（lib/draft.php:168）、
     * get_draft_filename() / has_draft() / draft_delete() は掛けない（:143）。
     * この非対称のせいで [[Foo]] 形式だと書き込み先と読み出し先が食い違う。
     * REST 側で先に正規化して、全操作のパスを一致させる。
     */
    private static function draftPageName(string $page): string
    {
        return function_exists('strip_bracket') ? strip_bracket($page) : $page;
    }

    /**
     * 下書きファイルの実 mtime を返す（無ければ 0）。
     *
     * get_draft_filetime()（lib/draft.php:152-155）は PukiWiki の慣習で
     * filemtime() - LOCALZONE を返す。これは format_date() に渡して
     * LOCALZONE を足し戻す前提の値で、そのまま date('c') に渡すと
     * タイムゾーン分（JST なら 9 時間）ずれる。
     * ページ側の updated_at は生の filemtime なので、こちらも合わせる。
     */
    private static function draftMtime(string $page): int
    {
        if (!function_exists('get_draft_filename')) {
            return 0;
        }
        $file = get_draft_filename($page);
        clearstatcache(true, $file);
        return is_file($file) ? (int)filemtime($file) : 0;
    }

    /**
     * 下書きを読む。無ければ 404。
     *
     * @return array{page: string, content: string, saved: ?string, digest: ?string, updated_at: ?string}
     */
    public function readDraft(string $page): array
    {
        self::assertDraftAvailable();
        $this->validatePageName($page);
        $this->assertReadable($page);

        $page = self::draftPageName($page);
        // get_draft_with_meta() は不在時に FALSE ではなく「空の正常配列」を返す
        // （lib/draft.php:64-66）ため、has_draft() で先に判定する必要がある
        if (!has_draft($page)) {
            throw new ApiException(404, "No draft for page '{$page}'.", 'draft_not_found');
        }
        $draft = get_draft_with_meta($page, true, true);
        if ($draft === false) {
            throw new ApiException(500, "Failed to read draft for '{$page}'.", 'draft_read_failed');
        }
        $mtime = self::draftMtime($page);

        return [
            'page'       => $page,
            'content'    => (string)$draft['content'],
            // 下書き保存時刻（draft_write() が #draft_saved: に記録した ATOM 文字列）
            'saved'      => $draft['meta']['saved'] ?? null,
            // 保存時点の「本ページ本文」の md5。本ページが変わったかの判定に使える
            // （REST の base_sha1 とは別物: あちらは生バイトの sha1）
            'digest'     => $draft['meta']['digest'] ?? null,
            'updated_at' => $mtime ? date('c', $mtime) : null,
        ];
    }

    /**
     * 下書きを書く（全文置換）。本ページには一切触れない。
     *
     * @return array{page: string, size: int, saved: ?string, updated_at: ?string}
     */
    public function writeDraft(
        string $page,
        string $body,
        string $actor,
        string $ip = '',
        string $wiki_user = ''
    ): array {
        self::assertDraftAvailable();
        $this->validatePageName($page);

        // 空の下書きを拒否する理由:
        // plugin_draft_publish_write()（plugin/draft.inc.php:263-265）は空または
        // '#md' だけの下書きを page_write($page, '') に渡し、これは PukiWiki の
        // ページ削除になる。API で空の下書きを作れると、あとで人間が Web UI で
        // 公開した瞬間にページが消える。
        if (trim($body) === '') {
            throw new ApiException(
                400,
                'Empty draft is not allowed: publishing it from the web UI would delete the page. '
                . 'Use DELETE to discard a draft.',
                'empty_draft'
            );
        }
        $max_body = self::maxBodyBytes();
        if (strlen($body) > $max_body) {
            throw new ApiException(
                413,
                "Draft exceeds the maximum size ({$max_body} bytes).",
                'content_too_large'
            );
        }
        if (defined('PKWK_READONLY') && PKWK_READONLY) {
            throw new ApiException(403, 'This wiki is read-only (PKWK_READONLY).', 'wiki_readonly');
        }

        $page = self::draftPageName($page);

        return $this->withIdentity($actor, $wiki_user, function () use ($page, $body, $actor, $ip, $wiki_user): array {
            // 下書きも本ページの編集権限を要求する（Web UI の draft プラグインが
            // check_editable() を要求しているのに合わせる。plugin/draft.inc.php:139,180）
            $this->assertWritable($page, $actor, $ip, $wiki_user);

            if (draft_write($page, $body) === false) {
                throw new ApiException(500, "Failed to write draft for '{$page}'.", 'draft_write_failed');
            }
            $mtime = self::draftMtime($page);
            $meta  = get_draft_with_meta($page, true, true);

            $this->audit->log('draft_written', [
                'page'  => $page,
                'actor' => $actor,
                'ip'    => $ip,
                'size'  => strlen($body),
            ]);

            return [
                'page'       => $page,
                'size'       => strlen($body),
                'saved'      => is_array($meta) ? ($meta['meta']['saved'] ?? null) : null,
                'updated_at' => $mtime ? date('c', $mtime) : null,
            ];
        });
    }

    /** 下書きを破棄する。無ければ 404。本ページには触れない。 */
    public function deleteDraft(
        string $page,
        string $actor,
        string $ip = '',
        string $wiki_user = ''
    ): array {
        self::assertDraftAvailable();
        $this->validatePageName($page);
        if (defined('PKWK_READONLY') && PKWK_READONLY) {
            throw new ApiException(403, 'This wiki is read-only (PKWK_READONLY).', 'wiki_readonly');
        }

        $page = self::draftPageName($page);

        return $this->withIdentity($actor, $wiki_user, function () use ($page, $actor, $ip, $wiki_user): array {
            $this->assertWritable($page, $actor, $ip, $wiki_user);

            if (!has_draft($page)) {
                throw new ApiException(404, "No draft for page '{$page}'.", 'draft_not_found');
            }
            if (draft_delete($page) === false) {
                throw new ApiException(500, "Failed to delete draft for '{$page}'.", 'draft_delete_failed');
            }

            $this->audit->log('draft_deleted', [
                'page'  => $page,
                'actor' => $actor,
                'ip'    => $ip,
            ]);

            return ['page' => $page, 'deleted' => true];
        });
    }

    /**
     * 下書きのあるページを新しい順に返す。閲覧不可のページは除外する。
     *
     * @return array{total: int, drafts: array<array{page: string, updated_at: ?string}>}
     */
    public function listDrafts(int $limit = 100, int $offset = 0): array
    {
        self::assertDraftAvailable();

        $all = [];
        foreach (get_draft_list() as $page) {
            // 検索と同じく、閲覧不可ページは黙って除外する（403 にはしない）
            if (!$this->canRead($page)) {
                continue;
            }
            $mtime = self::draftMtime($page);
            $all[] = ['page' => $page, 'updated_at' => $mtime ? date('c', $mtime) : null];
        }
        // get_draft_list() は既に更新時刻の降順（lib/draft.php:249-251）

        return [
            'total'  => count($all),
            'drafts' => array_slice($all, max(0, $offset), max(1, $limit)),
        ];
    }

    // -------------------------------------------------------------------------
    // identity と書き込み認可
    // -------------------------------------------------------------------------

    /**
     * PukiWiki のユーザー identity を差し替えた状態で $fn を実行し、必ず元に戻す。
     *
     * 認可判定（is_editable / is_page_writable / _is_page_accessible）と
     * #author 行の生成が同じ $auth_user を見るため、save/restore は 1 本にまとめる
     * （入れ子にすると例外パスで復元順が狂う）。
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function withIdentity(string $actor, string $wiki_user, callable $fn): mixed
    {
        $saved = [
            $GLOBALS['auth_user']          ?? null,
            $GLOBALS['auth_user_fullname'] ?? null,
            $GLOBALS['auth_user_groups']   ?? null,
        ];
        if ($wiki_user !== '') {
            // 実在の PukiWiki ユーザーとして振る舞い、$edit_auth を正規に満たす
            // （バイパスではない。$edit_auth_pages に該当しなければ従来どおり拒否される）
            $GLOBALS['auth_user'] = $wiki_user;
            // ラベルを fullname に残し「どのキーで書いたか」の追跡性を保つ
            $GLOBALS['auth_user_fullname'] = $wiki_user . ' (API: ' . $actor . ')';
            $GLOBALS['auth_user_groups']   = function_exists('get_groups_from_username')
                ? get_groups_from_username($wiki_user)
                : [$wiki_user];
        } else {
            // 未ログイン扱い。#author 行にはキーのラベルだけを記録する。
            // ここで auth_user_groups を空配列にしておくことが重要:
            //   _is_page_accessible()（lib/auth.php:289-295）は $auth_user が
            //   非空だと「!$auth_user」の早期 FALSE を通り抜け、そのまま
            //   array_intersect($auth_user_groups, ...) に進む。null のままだと
            //   TypeError になり、[] なら交差が空になって FALSE = fail-closed。
            //   ラベルは実在の PukiWiki ユーザーではないので、これが正しい。
            $GLOBALS['auth_user']          = $actor;
            $GLOBALS['auth_user_fullname'] = $actor . ' (API)';
            $GLOBALS['auth_user_groups']   = [];
        }

        try {
            return $fn();
        } finally {
            [
                $GLOBALS['auth_user'],
                $GLOBALS['auth_user_fullname'],
                $GLOBALS['auth_user_groups'],
            ] = $saved;
        }
    }

    /**
     * 書き込み系の認可をまとめて判定する。**必ず withIdentity() の内側で呼ぶこと。**
     * 拒否は監査ログに残したうえで 403 を投げる。
     */
    private function assertWritable(string $page, string $actor, string $ip, string $wiki_user): void
    {
        // 閲覧できないページは書き込めない（Web UI の edit と同じ前提）。
        // $read_auth と $edit_auth は同じ _is_page_accessible() を使うため、
        // identity を設定した「後」に判定して wiki_user の閲覧権限を正しく反映させる。
        // ここを飛ばすと、write スコープのキーが「読めないページ」を作成・上書きでき、
        // さらに書き込み前スナップショットとして旧内容が退避される（閲覧制限の迂回）。
        $this->assertReadable($page);

        // PukiWiki の凍結・編集可否チェック
        // （page_write() 自身は PKWK_READONLY しか見ないため、ここで明示的に行う）
        if (function_exists('is_freeze') && is_freeze($page)) {
            $this->denied($page, $actor, $ip, 'page_frozen');
            throw new ApiException(403, "Page '{$page}' is frozen.", 'page_frozen');
        }
        if (function_exists('is_editable') && !is_editable($page)) {
            $this->denied($page, $actor, $ip, 'page_not_editable');
            throw new ApiException(403, "Page '{$page}' is not editable.", 'page_not_editable');
        }
        // $edit_auth による編集認可（Web UI では edit プラグインが is_page_writable() で
        // 強制する）。キーに wiki_user が無ければログインユーザー不在として
        // $edit_auth_pages 該当ページは一律拒否 = fail-closed。
        if (function_exists('is_page_writable') && !is_page_writable($page)) {
            $this->denied($page, $actor, $ip, 'edit_forbidden');
            throw new ApiException(
                403,
                "Page '{$page}' is protected by edit authentication (\$edit_auth)." .
                ($wiki_user === ''
                    ? ' Issue the API key with --wiki-user to write as a PukiWiki user.'
                    : " The wiki user '{$wiki_user}' is not permitted to edit this page."),
                'edit_forbidden'
            );
        }
    }

    // -------------------------------------------------------------------------
    // 本体経由の保存
    // -------------------------------------------------------------------------

    /**
     * PukiWiki 本体経由で保存する。
     *
     * md.inc.php（Markdown プラグイン）を入れているサイトでは page_write() を直接
     * 呼んではいけない。page_write() は make_str_rules() を通すため、
     *   - '*' 始まりの行（Markdown の箇条書き・強調）に見出しアンカー [#xxxxxxx] が混入する
     *   - &now; &date; &time; 等の $str_rules マクロが実値に置換される（★不可逆★）
     * という破壊が起きる。前者は描画側で修復できるが、後者は復元できない。
     *
     * md.inc.php v0.4+ は外部ライター向けに md_page_write() を提供している。
     * page_write() と同一シグネチャで、#md ページのときだけ $str_rules と
     * $fixed_heading_anchor を一時無効化し、非 Markdown ページは page_write() へ
     * 素通しで委譲する。よって存在すれば無条件に使ってよい。
     */
    private static function writeThroughPukiWiki(string $page, string $body): void
    {
        // md.inc.php は通常ロードされていない（bootstrap はプラグインを読まない）。
        // exist_plugin() が require_once してくれる。md.inc.php のトップレベルは
        // 定数と関数定義のみで副作用がなく、Markdown パーサーの読み込みは変換時まで
        // 遅延されるため、ロードのコストは無視できる。
        if (function_exists('exist_plugin')) {
            exist_plugin('md');
        }
        if (function_exists('md_page_write')) {
            md_page_write($page, $body);
            return;
        }
        page_write($page, $body);
    }

    // -------------------------------------------------------------------------
    // 閲覧認可
    // -------------------------------------------------------------------------

    /** 閲覧不可なら 403 を投げる（read・revisions 用。検索のフィルタには canRead() を使う） */
    public function assertReadable(string $page): void
    {
        if ($this->canRead($page)) {
            return;
        }
        if (str_starts_with($page, ':')) {
            throw new ApiException(
                403,
                "System pages (starting with ':') cannot be read via API.",
                'system_page'
            );
        }
        throw new ApiException(
            403,
            "Page '{$page}' is not readable (protected by read authentication).",
            'read_forbidden'
        );
    }

    /**
     * 閲覧可否の判定（例外を投げない）。$read_auth 無効のサイトでは常に true。
     *
     * check_readable() は使わないこと: 認可 NG の経路が pkwk_common_headers() →
     * pkwk_headers_sent() を通り、既に出力があると die() する（MCP の stdio や
     * レスポンス送出後の API を巻き込んで落とす）。副作用のない判定本体
     * _is_page_accessible()（lib/auth.php）を直接使う。
     */
    private function canRead(string $page): bool
    {
        if (str_starts_with($page, ':')) {
            return false;
        }
        if (!empty($GLOBALS['read_auth'])
            && function_exists('_is_page_accessible')
            && !_is_page_accessible($page, $GLOBALS['read_auth_pages'] ?? [])) {
            return false;
        }
        return true;
    }

    // -------------------------------------------------------------------------
    // 書き込み
    // -------------------------------------------------------------------------

    /**
     * ページ全文を書き込む（楽観ロック付き）。
     *
     * @param string $page      対象ページ名
     * @param string $new_body  新しい本文（PukiWiki 記法・全文）
     * @param string $base_sha1 読んだ時点の sha1。新規作成は self::EMPTY_SHA1
     * @param string $actor     操作者（監査ログ・#author 行に記録）
     * @param string $ip        クライアント IP（監査ログ用）
     * @param string $wiki_user このキーが「どの PukiWiki ユーザーとして書くか」。
     *                          空なら従来どおり未ログイン扱い（$edit_auth 対象ページは fail-closed）。
     *
     * @return array{page: string, is_new: bool, changed: bool, new_sha1: string,
     *               size: int, mtime: int, snapshot: ?string}
     *
     * @throws ApiException 400/403/409/423/500
     */
    public function write(
        string $page,
        string $new_body,
        string $base_sha1,
        string $actor,
        string $ip = '',
        string $wiki_user = ''
    ): array {
        $this->validatePageName($page);

        if (!preg_match('/^[0-9a-f]{40}$/i', $base_sha1)) {
            throw new ApiException(
                400,
                '"base_sha1" must be a 40-char hex SHA1. Use ' . self::EMPTY_SHA1 . ' for new pages.',
                'invalid_base_sha1'
            );
        }
        $base_sha1 = strtolower($base_sha1);

        // 空本文は page_write() がページ削除として扱うため拒否する
        if (trim($new_body) === '') {
            throw new ApiException(
                400,
                'Empty content is not allowed (it would delete the page). ' .
                'Page deletion must be done from the PukiWiki web UI.',
                'empty_content'
            );
        }

        // 本文サイズ上限（REST・MCP の両経路に効かせるためここで検査する）
        $max_body = self::maxBodyBytes();
        if (strlen($new_body) > $max_body) {
            throw new ApiException(
                413,
                "Content exceeds the maximum page size ({$max_body} bytes). " .
                'Set PKWK_REST_MAX_BODY_BYTES to change the limit.',
                'content_too_large'
            );
        }

        // 保護ページ・システムページ（':' 始まり）は API から書き込み不可
        if (in_array($page, $this->protected_pages, true)) {
            $this->denied($page, $actor, $ip, 'page_protected');
            throw new ApiException(
                403,
                "Page '{$page}' is protected from API editing.",
                'page_protected'
            );
        }
        if (str_starts_with($page, ':')) {
            $this->denied($page, $actor, $ip, 'system_page');
            throw new ApiException(
                403,
                "System pages (starting with ':') cannot be edited via API.",
                'system_page'
            );
        }

        if (defined('PKWK_READONLY') && PKWK_READONLY) {
            throw new ApiException(403, 'This wiki is read-only (PKWK_READONLY).', 'wiki_readonly');
        }

        $file = $this->filePath($page);
        $lock = $this->acquireLock($page);

        try {
            // CAS: ロック内で現在の内容を確定させてから照合する
            clearstatcache(true, $file);
            $exists      = is_file($file);
            $old_content = $exists ? (string)file_get_contents($file) : '';
            $old_sha1    = $exists ? sha1($old_content) : self::EMPTY_SHA1;

            if ($exists) {
                if ($old_sha1 !== $base_sha1) {
                    throw new ApiException(409, implode(' ', [
                        "Conflict: page '{$page}' has been modified since your base.",
                        "Expected sha1={$base_sha1}, current sha1={$old_sha1}.",
                        'Re-read the page and apply your changes to the current version.',
                    ]), 'sha1_conflict');
                }
            } elseif ($base_sha1 !== self::EMPTY_SHA1) {
                throw new ApiException(409, implode(' ', [
                    "Page '{$page}' does not exist.",
                    'For new pages use base_sha1=' . self::EMPTY_SHA1 . ' (sha1 of empty string).',
                ]), 'page_not_found_as_conflict');
            }
            $is_new = !$exists;

            // identity を差し替えた状態で認可判定と書き込みを行う（詳細は withIdentity()）
            $this->withIdentity($actor, $wiki_user, function () use (
                $page, $new_body, $actor, $ip, $wiki_user, $exists, $old_content, $file
            ): void {
                $this->assertWritable($page, $actor, $ip, $wiki_user);

                // 書き込み前スナップショット（既存内容の退避。同一 sha1 は自動スキップ）
                if ($exists) {
                    $this->snapshots->saveIfNew($page, $old_content);
                }

                // 書き込み本体
                if (function_exists('page_write')) {
                    self::writeThroughPukiWiki($page, $new_body);
                } else {
                    self::atomicWrite($file, $new_body);
                }
            });

            // 実ファイルを再読込して確定内容を得る
            // （page_write() は本文を変形し、実質無変更なら書き込まない）
            clearstatcache(true, $file);
            if (!is_file($file)) {
                throw new ApiException(500, 'Page file missing after write', 'write_failed');
            }
            $stored = (string)file_get_contents($file);
            $new_sha1 = sha1($stored);
            $changed  = ($new_sha1 !== $old_sha1) || $is_new;
            $mtime    = (int)filemtime($file);

            // 書き込み後スナップショット（無変更なら sha1 重複でスキップされる）
            $snapshot_id = $this->snapshots->saveIfNew($page, $stored);

            $this->audit->log('page_written', [
                'page'      => $page,
                'actor'     => $actor,
                'ip'        => $ip,
                'is_new'    => $is_new,
                'changed'   => $changed,
                'base_sha1' => $base_sha1,
                'new_sha1'  => $new_sha1,
                'size'      => strlen($stored),
                'snapshot'  => $snapshot_id,
            ]);

            return [
                'page'     => $page,
                'is_new'   => $is_new,
                'changed'  => $changed,
                'new_sha1' => $new_sha1,
                'size'     => strlen($stored),
                'mtime'    => $mtime,
                'snapshot' => $snapshot_id,
            ];
        } finally {
            $this->releaseLock($lock);
        }
    }

    /** ページ本文の上限（bytes）。raw JSON 上限の算出（api/v1/index.php）にも使う */
    public static function maxBodyBytes(): int
    {
        $env = getenv('PKWK_REST_MAX_BODY_BYTES');
        if ($env !== false && ctype_digit($env) && (int)$env > 0) {
            return (int)$env;
        }
        return self::DEFAULT_MAX_BODY_BYTES;
    }

    // -------------------------------------------------------------------------
    // ページ名・パス
    // -------------------------------------------------------------------------

    public static function encodePage(string $page): string
    {
        return function_exists('encode') ? encode($page) : strtoupper(bin2hex($page));
    }

    public static function decodePage(string $encoded): string
    {
        if (function_exists('decode')) {
            return decode($encoded);
        }
        if ($encoded === '' || !ctype_xdigit($encoded)) {
            return '';
        }
        $bin = @hex2bin(strtolower($encoded));
        return $bin !== false ? $bin : '';
    }

    public function filePath(string $page): string
    {
        return $this->wiki_dir . '/' . self::encodePage($page) . '.txt';
    }

    /** ページ名の妥当性検査。不正なら 400 */
    private function validatePageName(string $page): void
    {
        if ($page === '' || trim($page) !== $page) {
            throw new ApiException(400, 'Invalid page name', 'invalid_page_name');
        }
        // 制御文字・PukiWiki が扱えない文字を拒否
        if (preg_match('/[\x00-\x1f\x7f]/', $page)) {
            throw new ApiException(400, 'Page name contains control characters', 'invalid_page_name');
        }
        $hard_limit = defined('PKWK_PAGENAME_BYTES_HARD_LIMIT')
            ? PKWK_PAGENAME_BYTES_HARD_LIMIT : 125;
        if (strlen($page) > $hard_limit) {
            throw new ApiException(400, "Page name too long (max {$hard_limit} bytes)", 'invalid_page_name');
        }
        // PukiWiki 本体の検査（ブラケット禁止文字等）
        if (function_exists('is_pagename') && !is_pagename($page)) {
            throw new ApiException(400, "Invalid page name: '{$page}'", 'invalid_page_name');
        }
    }

    // -------------------------------------------------------------------------
    // 内部ヘルパ
    // -------------------------------------------------------------------------

    /** wiki/ を走査して [ページ名 => ファイルパス] を返す */
    private function scanPages(): array
    {
        $pages = [];
        $dh = @opendir($this->wiki_dir);
        if ($dh === false) {
            return [];
        }
        while (($entry = readdir($dh)) !== false) {
            if (!str_ends_with($entry, '.txt')) {
                continue;
            }
            $page = self::decodePage(substr($entry, 0, -4));
            if ($page === '') {
                continue;
            }
            // ':' 始まりのシステムページ（:config 等）は一覧・検索に出さない
            // （PukiWiki 本体のページ一覧と同じ挙動。直接 read も assertReadable() が拒否する）
            if (str_starts_with($page, ':')) {
                continue;
            }
            $pages[$page] = $this->wiki_dir . '/' . $entry;
        }
        closedir($dh);
        return $pages;
    }

    /** @return resource flock 済みのロックファイルハンドル */
    private function acquireLock(string $page)
    {
        $lock_file = $this->lock_dir . '/' . self::encodePage($page) . '.lock';
        $fh = fopen($lock_file, 'c');
        if ($fh === false) {
            throw new ApiException(500, 'Cannot open lock file', 'lock_failed');
        }
        for ($i = 0; $i < self::LOCK_RETRIES; $i++) {
            if (flock($fh, LOCK_EX | LOCK_NB)) {
                return $fh;
            }
            usleep(self::LOCK_WAIT_US);
        }
        fclose($fh);
        throw new ApiException(
            423,
            "Page '{$page}' is locked by another writer. Please retry.",
            'page_locked'
        );
    }

    /** @param resource $fh */
    private function releaseLock($fh): void
    {
        flock($fh, LOCK_UN);
        fclose($fh);
    }

    /** スタンドアロンモード用の原子的書き込み（temp→fsync→rename） */
    private static function atomicWrite(string $dest, string $content): void
    {
        $dir = dirname($dest);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("mkdir failed: {$dir}");
        }
        $tmp = $dir . '/.rest_tmp_' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, $content, LOCK_EX) === false) {
            throw new \RuntimeException("write failed: {$tmp}");
        }
        $fh = fopen($tmp, 'r');
        if ($fh !== false) {
            fsync($fh);
            fclose($fh);
        }
        if (!rename($tmp, $dest)) {
            @unlink($tmp);
            throw new \RuntimeException("rename failed: {$tmp} → {$dest}");
        }
    }

    private function denied(string $page, string $actor, string $ip, string $reason): void
    {
        $this->audit->log('write_denied', [
            'page'   => $page,
            'actor'  => $actor,
            'ip'     => $ip,
            'reason' => $reason,
        ]);
    }
}
