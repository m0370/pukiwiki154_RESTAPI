# PukiWiki REST API v2 — API リファレンス

すべてのエンドポイントは `Authorization: Bearer <key>` 必須。
ベース URL 例: `https://example.com/rest-api-v2/api/v1`
（mod_rewrite なしの場合は `.../api/v1/index.php` を前置）

ページ名はパスにそのまま書ける（URL エンコード推奨、階層ページ名 `親/子` 対応）。

---

## GET /pages — ページ一覧 [read]

| パラメータ | 既定 | 説明 |
|-----------|------|------|
| `limit`   | 100  | 1〜1000 |
| `offset`  | 0    | ページネーション |

```json
{
  "pages": [{"name": "FrontPage", "mtime": 1782946039, "updated_at": "2026-07-01T22:47:19+00:00"}],
  "count": 1, "total": 41, "limit": 100, "offset": 0
}
```

`:` 始まりのシステムページ（`:config` 等）は一覧・検索に含まれない。

## GET /pages/{page} — ページ取得 [read]

```json
{
  "page": "講演/2026年",
  "sha1": "cdd96d111d848fab8130021832d4b3b57deed7d3",
  "content": "#author(...)\n*見出し [#x1y2z3]\n本文...",
  "size": 1234,
  "mtime": 1782946039,
  "updated_at": "2026-07-01T22:47:19+00:00",
  "is_frozen": false,
  "is_editable": true
}
```

- `sha1` を控えておき、書き込み時の `base_sha1` に使う
- 存在しないページは `404 page_not_found`

## PUT /pages/{page} — 全文書き込み [write]

```http
PUT /rest-api-v2/api/v1/pages/講演/2026年
Authorization: Bearer pkw2_...
Content-Type: application/json

{
  "base_sha1": "cdd96d111d848fab8130021832d4b3b57deed7d3",
  "content": "*見出し\n新しい本文（PukiWiki 記法・全文）\n"
}
```

- **新規作成**: `base_sha1` に `da39a3ee5e6b4b0d3255bfef95601890afd80709`（sha1('')）を指定 → `201 Created`
- **更新**: 直前に GET した `sha1` を指定 → `200 OK`

```json
{
  "page": "講演/2026年",
  "is_new": false,
  "changed": true,
  "new_sha1": "1e11b9e4ba227387c04707ab44682900fc7485d3",
  "size": 131,
  "mtime": 1782946039,
  "snapshot": "1782946039.123456_1e11b9e4...",
  "note": "Content may have been normalized by PukiWiki (...)"
}
```

**重要な挙動**:

- PukiWiki が本文を正規化する（`#author` 行の付与・見出しアンカー `[#xxxx]` の自動付与）。
  このため保存内容は送信内容と一致しない。**続けて編集する場合は `new_sha1` を次の
  `base_sha1` に使う**（または GET し直す）
- 送信内容が実質同一（#author 行を除いて同じ）の場合は `changed: false` で何も起きない
- 書き込みは `page_write()` 経由のため、PukiWiki 標準の diff・backup・RecentChanges・
  リンクキャッシュがすべて更新される

**エラー**:

| status | code | 意味 |
|--------|------|------|
| 400 | `missing_base_sha1` / `invalid_base_sha1` | base_sha1 がない・形式不正 |
| 400 | `missing_content` / `empty_content` | 本文がない・空（空本文＝削除は禁止） |
| 400 | `invalid_page_name` | ページ名が不正 |
| 403 | `insufficient_scope` | read キーで書き込もうとした |
| 403 | `page_protected` / `system_page` | FrontPage・MenuBar・`:` ページ |
| 403 | `page_frozen` / `page_not_editable` | 凍結・編集不可ページ |
| 403 | `edit_forbidden` | `$edit_auth` の編集認可ページ（API からは一律拒否） |
| 409 | `sha1_conflict` | 読んだ後に誰かが更新した → 再取得してやり直す |
| 409 | `page_not_found_as_conflict` | 存在しないページに非 EMPTY の base を指定 |
| 423 | `page_locked` | 別の書き込みが進行中 → リトライ |

## GET /search?q=...&limit=N — 全文検索 [read]

大文字小文字を無視した部分一致（日本語対応）。ページ名と本文が対象。`q` は 2 文字以上。

```json
{
  "query": "カルボプラチン",
  "results": [
    {"page": "胃癌/薬物療法", "snippet": "…投与するカルボプラチンの用量は…", "name_match": false}
  ],
  "count": 1
}
```

## GET /pages/{page}/revisions — スナップショット一覧 [read]

API 書き込み時に自動保存された全版の一覧（新しい順）。
PukiWiki 標準の backup/diff とは独立している。

```json
{
  "page": "講演/2026年",
  "revisions": [
    {"id": "1782946039.123456_1e11b9e4...", "ts": 1782946039, "time": "2026-07-01T22:47:19+00:00",
     "sha1": "1e11b9e4...", "gz_size": 141}
  ],
  "count": 2
}
```

## GET /pages/{page}/revisions/{id} — 過去版の取得 [read]

```json
{"page": "...", "revision": "1782946039.123456_...", "sha1": "...", "content": "過去版の全文"}
```

**復元方法**: 過去版の `content` を、現在ページの `sha1` を `base_sha1` にして PUT し直す。
（履歴を消さず「過去版を新しい版として再適用」する方式。復元操作自体も記録に残る）

---

## 典型的なワークフロー

### 既存ページの編集

```bash
# 1. 取得（sha1 を控える）
curl -H "Authorization: Bearer $KEY" $BASE/pages/メモ/今日
# 2. content を編集して PUT
curl -X PUT -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
  -d '{"base_sha1":"<1のsha1>","content":"<編集後の全文>"}' $BASE/pages/メモ/今日
# 3. 409 が返ったら 1 からやり直す
```

### 新規ページの作成

```bash
curl -X PUT -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
  -d '{"base_sha1":"da39a3ee5e6b4b0d3255bfef95601890afd80709","content":"*新ページ\n本文\n"}' \
  $BASE/pages/新しいページ
```

### 誤編集からの復旧

```bash
curl -H "Authorization: Bearer $KEY" "$BASE/pages/メモ/今日/revisions"        # 一覧
curl -H "Authorization: Bearer $KEY" "$BASE/pages/メモ/今日/revisions/{id}"   # 過去版取得
# → その content を現在の sha1 を base に PUT
```

## 制限事項

- ページ名の末尾が `/revisions` のページは revisions エンドポイントと区別できない
  （そのようなページ名は避けること）
- 検索はファイル走査のため、数千ページ規模まで（それ以上はインデックスの導入を検討）
- 削除・リネーム・添付ファイル操作の API はない（Web UI で行う）
