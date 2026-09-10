<?php
declare(strict_types=1);

require_once __DIR__ . '/ApiException.php';
require_once __DIR__ . '/Identity.php';
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

    /**
     * このリクエストの identity。null は「まだ認証していない」= 未ログイン扱い。
     * 生成後は差し替えない（withIdentity() が clone を返す）ため、read と write が
     * 同じ 1 つの identity を見る。
     */
    private ?Identity $identity = null;

    /**
     * 指定の identity を持つ PageStore を返す（自身は変更しない）。
     * 認証が済んだ直後に 1 回呼び、以降はその戻り値を使う。
     */
    public function withIdentity(Identity $identity): static
    {
        $clone = clone $this;
        $clone->identity = $identity;
        return $clone;
    }

    /** 現在の identity（未設定なら匿名）。 */
    private function identity(): Identity
    {
        return $this->identity ?? Identity::anonymous();
    }

    /**
     * identity を PukiWiki のグローバルに反映した状態で $fn を実行し、必ず元に戻す。
     *
     * 認可判定（is_editable / is_page_writable / _is_page_accessible）と #author 行の
     * 生成が同じ $auth_user を見るため、差し替えと復元は 1 本にまとめる。
     * **公開メソッドの入口でだけ呼ぶこと。** 内部からは *Inner() 系を呼び、
     * 入れ子にしない（入れ子でも復元自体は成立するが、内側が外側の identity を
     * 上書きしないという保証を型で持てないため）。
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function applying(callable $fn): mixed
    {
        $id = $this->identity();
        // 未定義と null を区別して保存する（復元時に元の状態を正確に戻すため）
        $had  = [
            array_key_exists('auth_user', $GLOBALS),
            array_key_exists('auth_user_fullname', $GLOBALS),
            array_key_exists('auth_user_groups', $GLOBALS),
        ];
        $saved = [
            $GLOBALS['auth_user']          ?? null,
            $GLOBALS['auth_user_fullname'] ?? null,
            $GLOBALS['auth_user_groups']   ?? null,
        ];

        // 差し替えは try の内側で行う。get_groups_from_username() が投げた場合でも
        // 「ユーザー名だけ新しく、グループは元のまま」という中途半端な状態を残さない。
        try {
            if (!$id->isAnonymous()) {
                // 実在の PukiWiki ユーザーとして振る舞い、$read_auth / $edit_auth を
                // 正規に満たす（バイパスではない。該当しなければ従来どおり拒否される）
                $GLOBALS['auth_user']          = $id->wiki_user;
                // ラベルを fullname に残し「どのキーで触ったか」の追跡性を保つ
                $GLOBALS['auth_user_fullname'] = $id->actor !== ''
                    ? $id->wiki_user . ' (API: ' . $id->actor . ')'
                    : $id->wiki_user;
                $GLOBALS['auth_user_groups']   = function_exists('get_groups_from_username')
                    ? get_groups_from_username($id->wiki_user)
                    : [$id->wiki_user];
            } else {
                // 未ログイン扱い。#author 行には actor だけを記録する。
                //   _is_page_accessible()（lib/auth.php:289-295）は $auth_user が
                //   非空だと「!$auth_user」の早期 FALSE を通り抜け、そのまま
                //   array_intersect($auth_user_groups, ...) に進む。null のままだと
                //   TypeError になり、[] なら交差が空になって FALSE = fail-closed。
                $GLOBALS['auth_user']          = $id->actor;
                $GLOBALS['auth_user_fullname'] = $id->actor !== '' ? $id->actor . ' (API)' : '';
                $GLOBALS['auth_user_groups']   = [];
            }

            return $fn();
        } finally {
            foreach (['auth_user', 'auth_user_fullname', 'auth_user_groups'] as $i => $k) {
                if ($had[$i]) {
                    $GLOBALS[$k] = $saved[$i];
                } else {
                    unset($GLOBALS[$k]);
                }
            }
        }
    }

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
        return $this->applying(function () use ($page) {
        $this->validatePageName($page);
        $this->assertReadableInner($page);
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
        });
    }

    /**
     * 全ページの一覧（名前順・ページネーション）。
     * @return array{pages: array<array{name: string, mtime: int, updated_at: string}>, total: int}
     */
    public function listPages(int $limit = 100, int $offset = 0): array
    {
        return $this->applying(function () use ($limit, $offset) {
        $all = [];
        foreach ($this->scanPages() as $page => $file) {
            // PHP は "2022" のような数値文字列キーを int に変換する。
            // 以降の strcmp()/canRead() は string を要求するため必ず戻す。
            $page  = (string)$page;
            // 閲覧不可ページは一覧からも除外する（search() と同じ扱い。
            // 歯科wiki のようにページ名自体が個人情報の場合、名前の漏洩を防ぐ）
            if (!$this->canRead($page)) {
                continue;
            }
            $mtime = (int)filemtime($file);
            $all[] = ['name' => $page, 'mtime' => $mtime, 'updated_at' => date('c', $mtime)];
        }
        usort($all, static fn($a, $b) => strcmp($a['name'], $b['name']));

        return [
            'pages' => array_slice($all, $offset, $limit),
            'total' => count($all),
        ];
        });
    }

    /**
     * 素朴な全文検索（ファイル走査・大文字小文字無視・部分一致）。
     * ページ名と本文の両方を対象にする。
     *
     * @return array<array{page: string, snippet: string, name_match: bool}>
     */
    public function search(string $query, int $limit = 20, string $mode = 'PHRASE'): array
    {
        $mode = strtoupper($mode);
        if (!in_array($mode, ['PHRASE', 'AND', 'OR'], true)) {
            throw new ApiException(400, 'mode must be PHRASE, AND or OR', 'invalid_search_mode');
        }
        $query = trim($query);
        if ($query === '' || mb_strlen($query, 'UTF-8') > 500) {
            throw new ApiException(400, 'Query must contain 1 to 500 characters', 'invalid_query');
        }
        $terms = $mode === 'PHRASE' ? [$query] : preg_split('/[\s\x{3000}]+/u', $query, -1, PREG_SPLIT_NO_EMPTY);
        if (!$terms || count($terms) > 20) {
            throw new ApiException(400, 'Use 1 to 20 search terms', 'invalid_query');
        }
        return $this->applying(function () use ($terms, $limit, $mode) {
            $results = [];
            foreach ($this->scanPages() as $page => $file) {
                $page = (string)$page;
                if (!$this->canRead($page)) continue;
                $content = file_get_contents($file);
                if ($content === false) continue;
                $hits = 0; $name_hit = false; $first = false;
                foreach ($terms as $term) {
                    $in_name = mb_stripos($page, $term, 0, 'UTF-8') !== false;
                    $pos = mb_stripos($content, $term, 0, 'UTF-8');
                    if ($in_name || $pos !== false) ++$hits;
                    $name_hit = $name_hit || $in_name;
                    if ($pos !== false && ($first === false || $pos < $first)) $first = $pos;
                }
                if ($mode === 'OR' ? $hits === 0 : $hits !== count($terms)) continue;
                $snippet = $first === false ? '' : mb_substr($content, max(0, $first - 40), 160, 'UTF-8');
                $results[] = ['page' => $page, 'snippet' => str_replace(["\r", "\n"], ' ', $snippet), 'name_match' => $name_hit];
                if (count($results) >= max(1, min(100, $limit))) break;
            }
            return $results;
        });
    }

    /** 添付操作もページ保存と同じ identity・保護・ロックを通す。 */
    public function withWritablePage(string $page, callable $operation): mixed
    {
        $this->validatePageName($page);
        $lock = $this->acquireLock($page);
        try {
            return $this->applying(function () use ($page, $operation) {
                $this->assertWritable($page, '');
                if ((defined('PKWK_READONLY') && PKWK_READONLY) || in_array($page, $this->protected_pages, true)) {
                    throw new ApiException(403, 'Page is protected', 'page_protected');
                }
                if (!is_file($this->filePath($page))) throw new ApiException(404, 'Page not found', 'page_not_found');
                return $operation();
            });
        } finally { $this->releaseLock($lock); }
    }

    // -------------------------------------------------------------------------
    // identity と書き込み認可
    // -------------------------------------------------------------------------

    /**
     * 書き込み系の認可をまとめて判定する。**必ず applying() の内側で呼ぶこと。**
     * 拒否は監査ログに残したうえで 403 を投げる。
     */
    private function assertWritable(string $page, string $ip): void
    {
        $actor     = $this->identity()->actor;
        $wiki_user = $this->identity()->wiki_user;

        // 閲覧できないページは書き込めない（Web UI の edit と同じ前提）。
        // $read_auth と $edit_auth は同じ _is_page_accessible() を使うため、
        // applying() の内側で判定して wiki_user の閲覧権限を正しく反映させる。
        // ここを飛ばすと、write スコープのキーが「読めないページ」を作成・上書きでき、
        // さらに書き込み前スナップショットとして旧内容が退避される（閲覧制限の迂回）。
        $this->assertReadableInner($page);

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
    private static function writeThroughPukiWiki(string $page, string $body, bool $notimestamp = false): void
    {
        // md.inc.php は通常ロードされていない（bootstrap はプラグインを読まない）。
        // exist_plugin() が require_once してくれる。md.inc.php のトップレベルは
        // 定数と関数定義のみで副作用がなく、Markdown パーサーの読み込みは変換時まで
        // 遅延されるため、ロードのコストは無視できる。
        if (function_exists('exist_plugin')) {
            exist_plugin('md');
        }
        if (function_exists('md_page_write')) {
            md_page_write($page, $body, $notimestamp);
            return;
        }
        page_write($page, $body, $notimestamp);
    }

    // -------------------------------------------------------------------------
    // 閲覧認可
    // -------------------------------------------------------------------------

    /** 閲覧不可なら 403 を投げる（read・revisions 用。検索のフィルタには canRead() を使う） */
    public function assertReadable(string $page): void
    {
        $this->applying(fn() => $this->assertReadableInner($page));
    }

    /** assertReadable() の実体。**applying() の内側からのみ呼ぶこと。** */
    private function assertReadableInner(string $page): void
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
        // identity 済みインスタンスでは無視される（identity が唯一の情報源）。
        // CLI・テストからの直呼び用に残してある互換引数。
        string $actor = '',
        string $ip = '',
        string $wiki_user = '',
        bool $notimestamp = false
    ): array {
        // identity 未設定のインスタンス（CLI・テスト・MCP の直呼び）では、引数から
        // 1 度だけ identity を組み立てて自分自身に委譲する。HTTP 経路は認証直後に
        // withIdentity() 済みなのでここは通らず、$actor / $wiki_user は無視される
        // （identity が唯一の情報源。read と write で二重管理しない）。
        if ($this->identity === null) {
            return $this->withIdentity(new Identity($actor, $wiki_user))
                        ->write($page, $new_body, $base_sha1, $actor, $ip, $wiki_user, $notimestamp);
        }
        $actor     = $this->identity->actor;
        $wiki_user = $this->identity->wiki_user;

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
            // identity を反映した状態で「認可 → CAS → 書き込み」を通す。
            //
            // ⚠ 認可は CAS より**前**に行うこと。逆にすると、閲覧できないページに対して
            // 409 が現在の sha1 を含んで返るため、write キーだけでページの存在と本文の
            // ハッシュを引き出せてしまう（認可前の情報漏えい）。
            // 認可・CAS・書き込みは 1 つの applying() の内側で完結させる。
            // 後段（changed 判定・監査ログ）で要る値はここから受け取る。
            [$is_new, $old_sha1] = $this->applying(function () use (
                $page, $new_body, $base_sha1, $ip, $file, $notimestamp
            ): array {
                $this->assertWritable($page, $ip);

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

                // 書き込み前スナップショット（既存内容の退避。同一 sha1 は自動スキップ）
                if ($exists) {
                    $this->snapshots->saveIfNew($page, $old_content);
                }

                // 書き込み本体
                if (function_exists('page_write')) {
                    self::writeThroughPukiWiki($page, $new_body, $notimestamp && $exists);
                } else {
                    $old_mtime = $exists ? filemtime($file) : false;
                    self::atomicWrite($file, $new_body);
                    if ($notimestamp && $old_mtime !== false && !touch($file, $old_mtime)) {
                        throw new ApiException(500, 'Cannot preserve page timestamp', 'write_failed');
                    }
                }
                return [!$exists, $old_sha1];
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
                'notimestamp' => $notimestamp,
            ]);

            return [
                'page'     => $page,
                'is_new'   => $is_new,
                'changed'  => $changed,
                'new_sha1' => $new_sha1,
                'size'     => strlen($stored),
                'mtime'    => $mtime,
                'notimestamp' => $notimestamp,
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
