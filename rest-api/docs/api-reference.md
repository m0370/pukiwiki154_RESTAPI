# PukiWiki REST API — エンドポイントリファレンス

> **ドキュメント種別**: API リファレンス  
> **ベース URL**: `https://{host}/rest-api/api/v1`  
> **認証方式**: `Authorization: Bearer <token>` ヘッダー  
> **レスポンス形式**: `application/json; charset=UTF-8`

---

## 共通仕様

### 認証

全エンドポイントで Bearer トークンが必要です。

```http
Authorization: Bearer a3f9c2e1d04b7f82...
```

### スコープ

各エンドポイントに必要なスコープを持つキーで呼び出す必要があります。

| スコープ | 説明 |
|---------|------|
| `page:read` | ページ読み取り・検索・一覧 |
| `draft:create` | 下書き作成（AI 用の基本スコープ） |
| `draft:approve` | 下書きの一覧・承認・却下 |
| `page:write` | ページの直接 PUT/PATCH（信頼済みキー専用） |
| `admin` | 監査・ロールバック・ソフト削除 |

### エラーレスポンス形式

```json
{
  "ok": false,
  "error": {
    "message": "エラーの説明",
    "code": "machine_readable_code"
  }
}
```

### 主なエラーコード

| HTTP | code | 意味 |
|------|------|------|
| 400 | `missing_base_sha1` | `base_sha1` が未指定 |
| 400 | `invalid_base_sha1` | `base_sha1` が 40 桁 hex でない |
| 400 | `missing_patches` | `patches` が空 |
| 401 | `unauthorized` | トークンなし・無効 |
| 403 | `forbidden` | スコープ不足 |
| 403 | `page_frozen` | 凍結ページへの書き込み |
| 403 | `page_protected` | 保護ページへの直接編集 |
| 404 | `page_not_found` | ページが存在しない |
| 409 | `sha1_conflict` | `base_sha1` が現在の sha1 と不一致（楽観ロック競合） |
| 409 | `block_not_found` | `block_sha1` がページ内に存在しない |
| 423 | `page_locked` | 別の操作がロックを保持中 |

---

## ページ系エンドポイント

### GET /pages

ページ一覧を返します。

**スコープ**: `page:read`

**クエリパラメータ**:

| パラメータ | 型 | 既定 | 説明 |
|-----------|-----|------|------|
| `limit` | int | 100 | 最大件数（1〜1000） |
| `offset` | int | 0 | 取得開始位置 |

**レスポンス例**:
```json
{
  "ok": true,
  "pages": [
    {
      "name": "FrontPage",
      "updated_at": 1719000000,
      "content_sha1": "a3f9c2..."
    }
  ],
  "count": 42
}
```

---

### GET /pages/{page}

ページの本文・メタデータを取得します。読み取り時に自己修復（wiki/ ファイルと DB の sha1 が食い違えば DB を追従）を行います。

**スコープ**: `page:read`

**パスパラメータ**:

| パラメータ | 説明 |
|-----------|------|
| `page` | ページ名（URL エンコード可。`/` は `%2F` で表現） |

**レスポンス例**:
```json
{
  "ok": true,
  "page": "FrontPage",
  "content": "* 最初の行\n\n段落\n",
  "sha1": "da39a3ee5e6b4b0d3255bfef95601890afd80709",
  "rev": 5,
  "updated_at": 1719000000,
  "is_frozen": false,
  "is_editable": true
}
```

> `sha1` は次の PUT/PATCH/POST で `base_sha1` として使います（楽観ロックのトークン）。

---

### PUT /pages/{page}

ページを全文上書きします（新規作成・既存更新の両方）。

**スコープ**: `page:write`  
**保護ページ**: 403 `page_protected`（FrontPage・MenuBar など）

**リクエストボディ**:
```json
{
  "base_sha1": "da39a3ee5e6b4b0d3255bfef95601890afd80709",
  "content":   "= ページタイトル =\n\n本文。\n",
  "summary":   "変更の概要（省略可）",
  "meta":      {}
}
```

| フィールド | 必須 | 説明 |
|-----------|------|------|
| `base_sha1` | ✅ | 新規作成は `da39a3ee5e6b4b0d3255bfef95601890afd80709`（= `sha1("")`）、既存ページは GET で取得した `sha1` |
| `content` | | 新しい全文（空文字列も可） |
| `summary` | | 変更の概要。`meta.summary` に保存される |
| `meta` | | 任意の追加メタデータ（JSON オブジェクト） |

**レスポンス**:

- 新規作成: `201 Created`
- 既存更新: `200 OK`

```json
{
  "ok": true,
  "page": "SomePage",
  "new_rev": 3,
  "new_sha1": "b94e3f...",
  "committed_at": 1719000123
}
```

---

### PATCH /pages/{page}

ブロック単位でページを直接編集します。`base_sha1` が一致しない場合は 409。`block_sha1` が存在しない場合も 409。

**スコープ**: `page:write`  
**保護ページ**: 403 `page_protected`

**リクエストボディ**:
```json
{
  "base_sha1": "b94e3f...",
  "patches": [
    {
      "block_sha1": "a1b2c3d4e5f6a1b2",
      "new_content": "置き換え後のブロック内容"
    },
    {
      "block_sha1": "deadbeef00000000",
      "new_content": null
    }
  ],
  "summary": "変更の概要（省略可）",
  "meta":    {}
}
```

| フィールド | 型 | 説明 |
|-----------|-----|------|
| `base_sha1` | string | GET で取得した現在の sha1 |
| `patches[].block_sha1` | string | `GET /pages/{page}/blocks` で得た 16 桁 hex |
| `patches[].new_content` | string \| null | 新しい内容。`null` でブロック削除 |

**レスポンス** (`200 OK`):
```json
{
  "ok": true,
  "page": "SomePage",
  "new_rev": 4,
  "new_sha1": "c8f2a1...",
  "committed_at": 1719000456,
  "patches_applied": 2
}
```

---

### GET /pages/{page}/blocks

ページをブロックに分割して返します。各ブロックに `block_sha1`（16 桁 hex）が付きます。

**スコープ**: `page:read`

**レスポンス例**:
```json
{
  "ok": true,
  "page": "SomePage",
  "sha1": "b94e3f...",
  "rev": 3,
  "block_count": 4,
  "blocks": [
    {
      "index": 0,
      "type": "heading",
      "content": "= ページタイトル =",
      "block_sha1": "1a2b3c4d5e6f1a2b",
      "line_preview": "= ページタイトル ="
    },
    {
      "index": 1,
      "type": "paragraph",
      "content": "段落の内容。",
      "block_sha1": "a1b2c3d4e5f6a1b2",
      "line_preview": "段落の内容。"
    }
  ]
}
```

**ブロックタイプ**:

| type | 説明 |
|------|------|
| `heading` | `=`, `==`, `===` などの見出し |
| `hr` | 水平線 `----` |
| `empty` | 空行 |
| `plugin` | `#plugin(...)` ブロックプラグイン |
| `list` | `-` `+` `--` などのリスト行グループ |
| `table` | `\|...\|` テーブル行グループ |
| `pre` | ` ` （スペース始まり）の整形済みテキスト |
| `definition` | `:term:definition` 定義リスト |
| `paragraph` | 上記に当てはまらない通常テキスト行 |

---

### POST /pages/{page}

ページへの変更提案（下書き）を作成します。AI の基本操作。

**スコープ**: `draft:create`

**リクエストボディ**:
```json
{
  "base_sha1": "b94e3f...",
  "content":   "= タイトル =\n\n新しい本文。\n",
  "summary":   "〇〇を追加",
  "meta":      {}
}
```

**レスポンス** (`201 Created`):
```json
{
  "ok": true,
  "draft_id": 7,
  "page": "SomePage",
  "base_sha1": "b94e3f...",
  "new_sha1":  "c8f2a1...",
  "diff": "--- SomePage (current)\n+++ SomePage (draft)\n...",
  "diff_stats": {
    "added": 3,
    "removed": 1
  },
  "expires_at": 1719604800
}
```

---

### POST /pages/{page}/blocks

ブロック単位のパッチから下書きを作成します（直接コミットはしない）。

**スコープ**: `draft:create`

**リクエストボディ**:
```json
{
  "base_sha1": "b94e3f...",
  "patches": [
    { "block_sha1": "a1b2c3d4e5f6a1b2", "new_content": "新しい内容" }
  ],
  "meta": {}
}
```

**レスポンス** (`201 Created`):
```json
{
  "ok": true,
  "draft_id": 8,
  "page": "SomePage",
  "base_sha1": "b94e3f...",
  "new_sha1": "c8f2a1...",
  "patches_applied": 1,
  "diff": "...",
  "diff_stats": { "added": 1, "removed": 1 }
}
```

---

## 検索・インデックス系

### GET /search

FTS5（trigram）による全文検索。日本語・英語対応。

**スコープ**: `page:read`

**クエリパラメータ**:

| パラメータ | 型 | 既定 | 説明 |
|-----------|-----|------|------|
| `q` | string | ✅ 必須 | 検索クエリ（3 文字以上） |
| `limit` | int | 20 | 最大件数（1〜100） |

**レスポンス例**:
```json
{
  "ok": true,
  "query": "サンプル",
  "results": [
    {
      "name": "SomePage",
      "snippet": "...これは<mark>サンプル</mark>のページです...",
      "updated_at": 1719000000
    }
  ],
  "count": 1,
  "limit": 20
}
```

---

### GET /index/status

インデックスの整合性状態を返します。

**スコープ**: `page:read`

**レスポンス例**:
```json
{
  "ok": true,
  "total_files": 42,
  "total_indexed": 42,
  "is_consistent": true,
  "missing_in_index": [],
  "orphan_in_index": []
}
```

---

### POST /index/rebuild

`wiki/` から page_index を再構築します。

**スコープ**: `page:read`（注: 将来 `admin` スコープに変更予定）

**レスポンス例**:
```json
{
  "ok": true,
  "rebuilt": true,
  "pages": 42
}
```

---

## 下書き（Draft）系

### GET /drafts

下書き一覧。フィルタ可能。

**スコープ**: `draft:approve`

**クエリパラメータ**:

| パラメータ | 説明 |
|-----------|------|
| `page` | ページ名でフィルタ |
| `status` | `open` / `approved` / `rejected` / `expired` |
| `owner` | 作成者でフィルタ |
| `limit` | 最大件数（既定 50、最大 200） |
| `offset` | オフセット |

---

### GET /drafts/{id}

下書き詳細 + diff プレビューを返します。

**スコープ**: `draft:approve`

**レスポンス例**:
```json
{
  "ok": true,
  "id": 7,
  "page": "SomePage",
  "status": "open",
  "owner": "claude-desktop",
  "base_sha1": "b94e3f...",
  "content": "新しい全文",
  "diff": "--- ...\n+++ ...\n...",
  "diff_stats": { "added": 3, "removed": 1 },
  "created_at": 1719000000,
  "expires_at": 1719604800
}
```

---

### POST /drafts/{id}/approve

下書きを承認してページに公開します。

**スコープ**: `draft:approve`

**レスポンス例**:
```json
{
  "ok": true,
  "published": true,
  "draft_id": 7,
  "page": "SomePage",
  "new_rev": 6,
  "new_sha1": "d4e5f6..."
}
```

---

### POST /drafts/{id}/reject

下書きを却下します。

**スコープ**: `draft:approve`

**リクエストボディ（省略可）**:
```json
{ "reason": "内容が不正確なため" }
```

---

## 管理者系（admin スコープ）

### GET /admin/audit

監査ログを取得します。

**スコープ**: `admin`

**クエリパラメータ**:

| パラメータ | 説明 |
|-----------|------|
| `page` | ページ名でフィルタ |
| `action` | アクション名でフィルタ（例: `page_committed`） |
| `actor` | 操作者名でフィルタ |
| `since` | UNIX タイムスタンプ以降 |
| `limit` | 最大件数（既定 100、最大 500） |
| `offset` | オフセット |

**主なアクション名**:

| action | 意味 |
|--------|------|
| `page_committed` | PUT/PATCH/下書き承認でページが更新された |
| `draft_created` | 下書きが作成された |
| `draft_approved` | 下書きが承認・公開された |
| `draft_rejected` | 下書きが却下された |
| `drafts_expired` | 期限切れ下書きが一括失効した |
| `page_rolled_back` | ロールバックが実行された |
| `page_soft_deleted` | ページがソフト削除された |

---

### POST /admin/drafts/expire

期限切れ（`open` かつ `expires_at < now`）の下書きを一括で `expired` に変更します。

**スコープ**: `admin`

**レスポンス例**:
```json
{
  "ok": true,
  "expired": 3,
  "expired_at": 1719000000
}
```

---

### GET /admin/pages/{page}/revisions

ページのリビジョン一覧を返します。

**スコープ**: `admin`

**クエリパラメータ**: `limit`（既定 50、最大 200）

**レスポンス例**:
```json
{
  "ok": true,
  "page": "SomePage",
  "revisions": [
    {
      "rev": 5,
      "content_sha1": "d4e5f6...",
      "actor": "claude-desktop",
      "committed_at": 1719000123,
      "meta": "{\"source\":\"put_page\",\"summary\":\"修正\"}"
    }
  ],
  "count": 5
}
```

---

### POST /admin/pages/{page}/rollback

ページを指定リビジョンの内容に巻き戻します（新しいリビジョンとして追記。履歴は消えない）。

**スコープ**: `admin`

**リクエストボディ**:
```json
{
  "target_rev": 3,
  "reason": "誤った内容が追加されたため"
}
```

**レスポンス例**:
```json
{
  "ok": true,
  "page": "SomePage",
  "new_rev": 6,
  "new_sha1": "b94e3f...",
  "rolled_back_from": 5,
  "target_rev": 3
}
```

---

### DELETE /admin/pages/{page}

ページをソフト削除します（`unlink` は使わず `wiki/.archive/` に移動）。

**スコープ**: `admin`

**レスポンス例**:
```json
{
  "ok": true,
  "page": "SomePage",
  "status": "deleted",
  "archived_to": "/var/www/pukiwiki/rest-api/data/wiki/.archive/536F6D6550616765.txt.20240621123456",
  "deleted_at": 1719000000
}
```

---

## 操作フロー例

### AI が提案 → 人間が承認する（基本フロー）

```
1. GET  /pages/技術メモ
   → sha1: "b94e3f..." を取得

2. POST /pages/技術メモ
   body: { base_sha1: "b94e3f...", content: "...", summary: "概要を追加" }
   → draft_id: 7

3. GET  /drafts/7
   → diff を確認

4. POST /drafts/7/approve
   → 本番ページに反映
```

### ブロック単位で修正を提案する

```
1. GET  /pages/技術メモ/blocks
   → sha1 と block_sha1 一覧を取得

2. POST /pages/技術メモ/blocks
   body: {
     base_sha1: "b94e3f...",
     patches: [{ block_sha1: "a1b2c3...", new_content: "新しい段落" }]
   }
   → draft_id: 8（下書き作成のみ、直接反映なし）

3. POST /drafts/8/approve
   → 承認で本番反映
```

### 管理者がロールバックする

```
1. GET  /admin/pages/技術メモ/revisions
   → リビジョン一覧を確認

2. POST /admin/pages/技術メモ/rollback
   body: { target_rev: 3, reason: "v4 に問題があったため" }
   → rev 6 として旧内容が復元される
```

---

## 関連ドキュメント

- [setup.md](setup.md) — インストール・環境設定
- [api-key-management.md](api-key-management.md) — API キーの発行・管理
