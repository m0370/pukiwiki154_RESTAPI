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
        string $ip = ''
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
            // 強制する。API にはログインユーザーがいないため、$edit_auth_pages に該当する
            // ページは一律拒否 = fail-closed。$read_auth の read 側拒否と対称）
            if (function_exists('is_page_writable') && !is_page_writable($page)) {
                $this->denied($page, $actor, $ip, 'edit_forbidden');
                throw new ApiException(
                    403,
                    "Page '{$page}' is protected by edit authentication (\$edit_auth).",
                    'edit_forbidden'
                );
            }

            // 書き込み前スナップショット（既存内容の退避。同一 sha1 は自動スキップ）
            if ($exists) {
                $this->snapshots->saveIfNew($page, $old_content);
            }

            // 書き込み本体
            if (function_exists('page_write')) {
                // #author 行に API 操作者を記録する（Web UI のログインユーザーに相当）
                $saved_user     = $GLOBALS['auth_user'] ?? null;
                $saved_fullname = $GLOBALS['auth_user_fullname'] ?? null;
                $GLOBALS['auth_user']          = $actor;
                $GLOBALS['auth_user_fullname'] = $actor . ' (API)';
                try {
                    page_write($page, $new_body);
                } finally {
                    $GLOBALS['auth_user']          = $saved_user;
                    $GLOBALS['auth_user_fullname'] = $saved_fullname;
                }
            } else {
                self::atomicWrite($file, $new_body);
            }

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
