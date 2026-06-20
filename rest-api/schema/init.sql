-- PukiWiki REST API: SQLiteスキーマ  @version v0.1
-- 制御プレーン（台帳）＋ 検索インデックス
--
-- 設計原則: 本文の正本は wiki/{page}.txt ファイル。
-- このDBは派生・調整層で、ファイルから再構築可能。

PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;
PRAGMA synchronous = NORMAL;

-- ページ台帳: 楽観的競合検出(CAS)用
-- content_sha1 が常にファイルと一致するよう自己修復が維持する。
CREATE TABLE IF NOT EXISTS pages (
    name          TEXT PRIMARY KEY,
    current_rev   INTEGER NOT NULL DEFAULT 0,
    content_sha1  TEXT NOT NULL DEFAULT '',
    updated_at    INTEGER NOT NULL DEFAULT 0,
    status        TEXT NOT NULL DEFAULT 'active'
    -- status: 'active' | 'deleted'
);

-- 版履歴索引: revisions/_blobs/{sha1[0:2]}/{sha1}.gz の索引
-- 追記のみ。削除しない。
CREATE TABLE IF NOT EXISTS revisions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    page         TEXT NOT NULL,
    rev          INTEGER NOT NULL,
    content_sha1 TEXT NOT NULL,
    actor        TEXT NOT NULL DEFAULT '',
    meta         TEXT NOT NULL DEFAULT '{}',
    committed_at INTEGER NOT NULL,
    UNIQUE (page, rev)
);

-- 下書き/提案ストア（AI からの提案を保持する状態機械）
CREATE TABLE IF NOT EXISTS drafts (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    page         TEXT NOT NULL,
    base_sha1    TEXT NOT NULL,
    body         TEXT NOT NULL,
    owner        TEXT NOT NULL,
    status       TEXT NOT NULL DEFAULT 'open',
    -- status: 'open' | 'approved' | 'rejected' | 'expired'
    created_at   INTEGER NOT NULL,
    updated_at   INTEGER NOT NULL,
    expires_at   INTEGER,
    meta         TEXT NOT NULL DEFAULT '{}'
);

-- 監査ログ（追記のみ、絶対に削除しない）
CREATE TABLE IF NOT EXISTS audit (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    action     TEXT NOT NULL,
    page       TEXT,
    actor      TEXT NOT NULL,
    detail     TEXT NOT NULL DEFAULT '{}',
    logged_at  INTEGER NOT NULL
);

-- APIキー
CREATE TABLE IF NOT EXISTS api_keys (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    key_hash    TEXT NOT NULL UNIQUE,
    label       TEXT NOT NULL DEFAULT '',
    scopes      TEXT NOT NULL DEFAULT 'page:read',
    ip_allow    TEXT,
    created_at  INTEGER NOT NULL,
    expires_at  INTEGER,
    last_used   INTEGER,
    status      TEXT NOT NULL DEFAULT 'active'
    -- status: 'active' | 'revoked'
);

-- ページロック（リース方式）
-- expires_at < now のレコードは孤児ロックとして自動回収する。
CREATE TABLE IF NOT EXISTS locks (
    page        TEXT PRIMARY KEY,
    holder      TEXT NOT NULL,
    expires_at  INTEGER NOT NULL
);

-- 検索インデックス（ファイルから再構築可能な派生層）
CREATE TABLE IF NOT EXISTS page_index (
    name        TEXT PRIMARY KEY,
    content     TEXT NOT NULL DEFAULT '',
    updated_at  INTEGER NOT NULL DEFAULT 0
);

-- FTS5 外部コンテンツ仮想テーブル
-- trigram トークナイザー: 3文字 n-gram で日本語/英語の部分一致検索を実現。
-- unicode61 は日本語をスペース区切りなしで1トークンとして扱うため機能しない。
-- trigram は SQLite 3.34+ で利用可能（最低クエリ長 3 文字）。
CREATE VIRTUAL TABLE IF NOT EXISTS page_fts USING fts5(
    name,
    content,
    content='page_index',
    content_rowid='rowid',
    tokenize='trigram'
);

-- FTS5 同期トリガ
CREATE TRIGGER IF NOT EXISTS page_index_ai AFTER INSERT ON page_index BEGIN
    INSERT INTO page_fts(rowid, name, content)
    VALUES (new.rowid, new.name, new.content);
END;

CREATE TRIGGER IF NOT EXISTS page_index_ad AFTER DELETE ON page_index BEGIN
    INSERT INTO page_fts(page_fts, rowid, name, content)
    VALUES ('delete', old.rowid, old.name, old.content);
END;

CREATE TRIGGER IF NOT EXISTS page_index_au AFTER UPDATE ON page_index BEGIN
    INSERT INTO page_fts(page_fts, rowid, name, content)
    VALUES ('delete', old.rowid, old.name, old.content);
    INSERT INTO page_fts(rowid, name, content)
    VALUES (new.rowid, new.name, new.content);
END;

-- インデックス
CREATE INDEX IF NOT EXISTS idx_revisions_page    ON revisions(page, rev);
CREATE INDEX IF NOT EXISTS idx_revisions_sha1    ON revisions(content_sha1);
CREATE INDEX IF NOT EXISTS idx_drafts_page       ON drafts(page, status);
CREATE INDEX IF NOT EXISTS idx_drafts_owner      ON drafts(owner, status);
CREATE INDEX IF NOT EXISTS idx_audit_page        ON audit(page, logged_at);
CREATE INDEX IF NOT EXISTS idx_locks_expires     ON locks(expires_at);
