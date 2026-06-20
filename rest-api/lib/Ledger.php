<?php
declare(strict_types=1);

/**
 * SQLite 台帳（制御プレーン）。
 *
 * 本文の正本はファイル。このクラスは「版・ハッシュ・ロック・
 * 下書き状態・監査」という派生メタデータだけを管理する。
 *
 * 最悪 DB を削除してもファイルと blob から再構築できる設計を維持する。
 * @version v0.1
 */
final class Ledger
{
    private PDO $db;

    public function __construct(string $db_path)
    {
        $this->db = new PDO("sqlite:{$db_path}", null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->db->exec('PRAGMA journal_mode = WAL');
        $this->db->exec('PRAGMA foreign_keys = ON');
        $this->db->exec('PRAGMA synchronous = NORMAL');
        $this->db->exec('PRAGMA busy_timeout = 5000');
    }

    /** スキーマを適用してインスタンスを返す */
    public static function open(string $db_path, string $schema_sql_path): self
    {
        $sql = file_get_contents($schema_sql_path);
        if ($sql === false) {
            throw new \RuntimeException("Cannot read schema: {$schema_sql_path}");
        }
        // SQLite3::exec() は BEGIN...END トリガー内のセミコロンを正しく扱える。
        // PDO::exec() は単文しか安全に処理できないため、スキーマ初期化だけ SQLite3 を使う。
        $sqlite3 = new \SQLite3($db_path);
        if (!$sqlite3->exec($sql)) {
            $err = $sqlite3->lastErrorMsg();
            $sqlite3->close();
            throw new \RuntimeException("Schema init failed: {$err}");
        }
        $sqlite3->close();
        return new self($db_path);
    }

    // -------------------------------------------------------------------------
    // ページ台帳
    // -------------------------------------------------------------------------

    /** ページのメタデータを取得 */
    public function getPage(string $name): ?array
    {
        $s = $this->db->prepare('SELECT * FROM pages WHERE name = ?');
        $s->execute([$name]);
        $row = $s->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * コンペア・アンド・スワップ更新。
     * current_rev が expect_rev と一致する場合のみ rev を 1 上げる。
     * 一致しなければ false（競合 → 呼び出し元は 409 を返す）。
     */
    public function casUpdate(string $name, int $expect_rev, string $new_sha1, int $now): int|false
    {
        $new_rev = $expect_rev + 1;
        $s = $this->db->prepare(
            'UPDATE pages SET current_rev=?, content_sha1=?, updated_at=?
             WHERE name=? AND current_rev=?'
        );
        $s->execute([$new_rev, $new_sha1, $now, $name, $expect_rev]);
        return $s->rowCount() > 0 ? $new_rev : false;
    }

    /** ページメタを upsert（初回書き込み・自己修復の両方で使う） */
    public function upsertPage(string $name, int $rev, string $sha1, int $now): void
    {
        $this->db->prepare(
            'INSERT INTO pages(name, current_rev, content_sha1, updated_at, status)
             VALUES(?,?,?,?,\'active\')
             ON CONFLICT(name) DO UPDATE
             SET current_rev=excluded.current_rev,
                 content_sha1=excluded.content_sha1,
                 updated_at=excluded.updated_at,
                 status=\'active\''
        )->execute([$name, $rev, $sha1, $now]);
    }

    /** ソフト削除（ファイルが消えた場合に状態を反映する） */
    public function markDeleted(string $name, int $now): void
    {
        $this->db->prepare(
            'UPDATE pages SET status=\'deleted\', updated_at=? WHERE name=?'
        )->execute([$now, $name]);
    }

    /**
     * 自己修復: ファイルの sha1 と DB の sha1 が食い違う場合に DB を追従させる。
     * 外部編集（Web UI・プラグインがDBを知らずにファイルを書き換えた）の検出・追従。
     * @return bool 修復を行った場合 true、すでに同期済みなら false
     */
    public function heal(string $name, string $file_sha1, int $now): bool
    {
        $page = $this->getPage($name);
        if ($page !== null && $page['content_sha1'] === $file_sha1) {
            return false;
        }

        $this->db->beginTransaction();
        try {
            $new_rev = ($page !== null ? (int)$page['current_rev'] : 0) + 1;
            $this->upsertPage($name, $new_rev, $file_sha1, $now);
            $this->appendRevision(
                $name, $new_rev, $file_sha1, 'external-edit',
                ['prev_sha1' => $page['content_sha1'] ?? null],
                $now
            );
            $this->log('external_edit_healed', $name, 'system', [
                'file_sha1' => $file_sha1,
                'was'       => $page['content_sha1'] ?? null,
                'new_rev'   => $new_rev,
            ]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return true;
    }

    // -------------------------------------------------------------------------
    // 版履歴
    // -------------------------------------------------------------------------

    /** 版履歴に追記（同じ page+rev が既存なら無視） */
    public function appendRevision(
        string $page, int $rev, string $sha1,
        string $actor, array $meta, int $now
    ): void {
        $this->db->prepare(
            'INSERT OR IGNORE INTO revisions(page,rev,content_sha1,actor,meta,committed_at)
             VALUES(?,?,?,?,?,?)'
        )->execute([$page, $rev, $sha1, $actor, json_encode($meta), $now]);
    }

    /** ページの版履歴一覧（新しい順） */
    public function listRevisions(string $page, int $limit = 50): array
    {
        $s = $this->db->prepare(
            'SELECT * FROM revisions WHERE page=? ORDER BY rev DESC LIMIT ?'
        );
        $s->execute([$page, $limit]);
        return $s->fetchAll();
    }

    // -------------------------------------------------------------------------
    // ロック（リース方式）
    // -------------------------------------------------------------------------

    /**
     * ロックを取得する。期限切れの孤児ロックは自動回収してから試みる。
     * @return bool 取得成功なら true、別の holder が保持中なら false
     */
    public function acquireLock(string $page, string $holder, int $ttl_sec, int $now): bool
    {
        // 期限切れ孤児ロックを先に回収
        $this->db->prepare('DELETE FROM locks WHERE page=? AND expires_at < ?')
                 ->execute([$page, $now]);
        try {
            $this->db->prepare(
                'INSERT INTO locks(page, holder, expires_at) VALUES(?,?,?)'
            )->execute([$page, $holder, $now + $ttl_sec]);
            return true;
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                return false; // 別の holder が保持中
            }
            throw $e;
        }
    }

    /** ロックを解放する。自分が保持している場合のみ成功 */
    public function releaseLock(string $page, string $holder): bool
    {
        $s = $this->db->prepare('DELETE FROM locks WHERE page=? AND holder=?');
        $s->execute([$page, $holder]);
        return $s->rowCount() > 0;
    }

    /** 全期限切れロックを回収。回収件数を返す */
    public function reclaimExpiredLocks(int $now): int
    {
        $s = $this->db->prepare('DELETE FROM locks WHERE expires_at < ?');
        $s->execute([$now]);
        return $s->rowCount();
    }

    /** ページの現在のロック情報（期限切れ自動回収後） */
    public function getLock(string $page, int $now): ?array
    {
        $this->reclaimExpiredLocks($now);
        $s = $this->db->prepare('SELECT * FROM locks WHERE page=?');
        $s->execute([$page]);
        $row = $s->fetch();
        return $row !== false ? $row : null;
    }

    // -------------------------------------------------------------------------
    // 下書き
    // -------------------------------------------------------------------------

    /** 下書きを作成してIDを返す */
    public function createDraft(
        string $page, string $base_sha1, string $body,
        string $owner, int $now,
        ?int $expires_at = null, array $meta = []
    ): int {
        $this->db->prepare(
            "INSERT INTO drafts(page,base_sha1,body,owner,status,created_at,updated_at,expires_at,meta)
             VALUES(?,?,?,?,'open',?,?,?,?)"
        )->execute([$page, $base_sha1, $body, $owner, $now, $now, $expires_at, json_encode($meta)]);
        return (int)$this->db->lastInsertId();
    }

    /** 下書きのステータスを更新 */
    public function updateDraftStatus(int $id, string $status, int $now): bool
    {
        $s = $this->db->prepare('UPDATE drafts SET status=?, updated_at=? WHERE id=?');
        $s->execute([$status, $now, $id]);
        return $s->rowCount() > 0;
    }

    /** 下書きを1件取得 */
    public function getDraft(int $id): ?array
    {
        $s = $this->db->prepare('SELECT * FROM drafts WHERE id=?');
        $s->execute([$id]);
        $row = $s->fetch();
        return $row !== false ? $row : null;
    }

    /** 期限切れ下書きを一括で expired に変更 */
    public function expireDrafts(int $now): int
    {
        $s = $this->db->prepare(
            "UPDATE drafts SET status='expired', updated_at=?
             WHERE status='open' AND expires_at IS NOT NULL AND expires_at < ?"
        );
        $s->execute([$now, $now]);
        return $s->rowCount();
    }

    /**
     * 下書き一覧（フィルタリング・ページネーション対応）。
     * body は省略して基本メタデータのみ返す（一覧表示用）。
     * body が必要な場合は getDraft($id) を使う。
     */
    public function listDrafts(
        ?string $page   = null,
        ?string $status = null,
        ?string $owner  = null,
        int     $limit  = 50,
        int     $offset = 0
    ): array {
        $where  = [];
        $params = [];

        if ($page !== null) {
            $where[]  = 'page = ?';
            $params[] = $page;
        }
        if ($status !== null) {
            $where[]  = 'status = ?';
            $params[] = $status;
        }
        if ($owner !== null) {
            $where[]  = 'owner = ?';
            $params[] = $owner;
        }

        $sql = 'SELECT id, page, base_sha1, owner, status, created_at, updated_at, expires_at, meta
                FROM drafts';
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY created_at DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;

        $s = $this->db->prepare($sql);
        $s->execute($params);
        return $s->fetchAll();
    }

    // -------------------------------------------------------------------------
    // 検索インデックス
    // -------------------------------------------------------------------------

    /** ページ内容をインデックスに登録/更新 */
    public function indexPage(string $name, string $content, int $now): void
    {
        $this->db->prepare(
            'INSERT INTO page_index(name, content, updated_at) VALUES(?,?,?)
             ON CONFLICT(name) DO UPDATE
             SET content=excluded.content, updated_at=excluded.updated_at'
        )->execute([$name, $content, $now]);
    }

    /** インデックスからページを削除 */
    public function deindexPage(string $name): void
    {
        $this->db->prepare('DELETE FROM page_index WHERE name=?')->execute([$name]);
    }

    /**
     * FTS5 全文検索。
     * @return array [['name' => ..., 'excerpt' => ...], ...]
     */
    public function search(string $query, int $limit = 20): array
    {
        $s = $this->db->prepare(
            "SELECT pi.name,
                    snippet(page_fts, 1, '<b>', '</b>', '...', 20) AS excerpt
             FROM page_fts
             JOIN page_index pi ON pi.rowid = page_fts.rowid
             WHERE page_fts MATCH ?
             ORDER BY rank
             LIMIT ?"
        );
        $s->execute([$query, $limit]);
        return $s->fetchAll();
    }

    /** ページ一覧（名前順） */
    public function listPages(int $limit = 1000, int $offset = 0): array
    {
        $s = $this->db->prepare(
            'SELECT name, updated_at FROM page_index ORDER BY name LIMIT ? OFFSET ?'
        );
        $s->execute([$limit, $offset]);
        return $s->fetchAll();
    }

    /**
     * page_index を全削除してから再構築する（DB 破損からの復旧用）。
     * $pages_iter は [name, content, updated_at] タプルの iterable。
     */
    public function rebuildIndex(iterable $pages_iter): void
    {
        $this->db->exec('DELETE FROM page_index');
        $s = $this->db->prepare(
            'INSERT INTO page_index(name, content, updated_at) VALUES(?,?,?)'
        );
        foreach ($pages_iter as [$name, $content, $updated_at]) {
            $s->execute([$name, $content, $updated_at]);
        }
        // FTS5 コンテンツテーブルと同期
        $this->db->exec("INSERT INTO page_fts(page_fts) VALUES('rebuild')");
    }

    // -------------------------------------------------------------------------
    // 監査ログ
    // -------------------------------------------------------------------------

    /** 監査ログに追記（削除禁止） */
    public function log(string $action, ?string $page, string $actor, array $detail = []): void
    {
        $this->db->prepare(
            'INSERT INTO audit(action, page, actor, detail, logged_at) VALUES(?,?,?,?,?)'
        )->execute([$action, $page, $actor, json_encode($detail), time()]);
    }

    /**
     * 監査ログ一覧（フィルタリング・ページネーション対応）。
     *
     * @param string|null $page    ページ名フィルタ
     * @param string|null $action  アクションフィルタ（例: 'page_committed', 'draft_approved'）
     * @param string|null $actor   操作者フィルタ
     * @param int         $since   この UNIX タイムスタンプ以降のみ（0 = 制限なし）
     * @param int         $limit   最大件数
     * @param int         $offset  オフセット
     */
    public function listAudit(
        ?string $page   = null,
        ?string $action = null,
        ?string $actor  = null,
        int     $since  = 0,
        int     $limit  = 50,
        int     $offset = 0
    ): array {
        $where  = [];
        $params = [];

        if ($page !== null) {
            $where[]  = 'page = ?';
            $params[] = $page;
        }
        if ($action !== null) {
            $where[]  = 'action = ?';
            $params[] = $action;
        }
        if ($actor !== null) {
            $where[]  = 'actor = ?';
            $params[] = $actor;
        }
        if ($since > 0) {
            $where[]  = 'logged_at >= ?';
            $params[] = $since;
        }

        $sql = 'SELECT id, action, page, actor, detail, logged_at FROM audit';
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY logged_at DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;

        $s = $this->db->prepare($sql);
        $s->execute($params);
        return $s->fetchAll();
    }

    /**
     * APIキーを失効させる（ソフト無効化）。
     */
    public function revokeApiKey(int $key_id, int $now): bool
    {
        $s = $this->db->prepare("UPDATE api_keys SET status='revoked', last_used=? WHERE id=?");
        $s->execute([$now, $key_id]);
        return $s->rowCount() > 0;
    }

    // -------------------------------------------------------------------------
    // APIキー
    // -------------------------------------------------------------------------

    /** APIキーを登録（raw_key の SHA-256 ハッシュを保存） */
    public function registerApiKey(
        string $raw_key, string $label, string $scopes,
        int $now, ?int $expires_at = null, ?string $ip_allow = null
    ): int {
        $key_hash = hash('sha256', $raw_key);
        $this->db->prepare(
            'INSERT INTO api_keys(key_hash,label,scopes,ip_allow,created_at,expires_at)
             VALUES(?,?,?,?,?,?)'
        )->execute([$key_hash, $label, $scopes, $ip_allow, $now, $expires_at]);
        return (int)$this->db->lastInsertId();
    }

    /** Bearer トークンを照合してキー情報を返す（存在しない/失効/期限切れは null） */
    public function authenticateKey(string $raw_key, int $now): ?array
    {
        $key_hash = hash('sha256', $raw_key);
        $s = $this->db->prepare(
            "SELECT * FROM api_keys
             WHERE key_hash=? AND status='active'
               AND (expires_at IS NULL OR expires_at > ?)"
        );
        $s->execute([$key_hash, $now]);
        $row = $s->fetch();
        if ($row === false) {
            return null;
        }
        // last_used を更新
        $this->db->prepare('UPDATE api_keys SET last_used=? WHERE id=?')
                 ->execute([$now, $row['id']]);
        return $row;
    }

    // -------------------------------------------------------------------------
    // ユーティリティ
    // -------------------------------------------------------------------------

    public function getPdo(): PDO
    {
        return $this->db;
    }

}
