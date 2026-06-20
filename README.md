# PukiWiki 1.5.4 REST API Extension

> **Version**: v0.1  
> **対象**: PukiWiki 1.5.4  
> **ライセンス**: MIT

PukiWiki 1.5.4 に REST API と MCP（Model Context Protocol）サーバーを追加する拡張モジュールです。  
**PukiWiki 本体を一切改変せず**、`rest-api/` フォルダを置くだけで動作します。

## 設計方針

```
Files are canonical      ← wiki/*.txt が唯一の正本
API is entry point       ← 読み書きは必ず API 経由
AI is contributor        ← AI は下書きを作るだけ
Humans are approvers     ← 本番への反映は人間が承認
```

## 主な機能（v0.1）

| 機能 | 説明 |
|------|------|
| REST API | ページの読み取り・検索・下書き作成・承認・直接編集 |
| MCP サーバー | Claude Desktop / Claude Code から直接 Wiki を操作 |
| 楽観ロック（CAS） | SHA1 ベースの競合検出 |
| ブロック単位編集 | content-hash アンカーによる部分更新 |
| 監査ログ | 全操作をトレース可能 |
| 版管理 | content-addressed blob スナップショット |
| ソフト削除 | `unlink()` を使わず `.archive/` へ移動 |
| 自己修復 | ファイル↔DB の sha1 不一致を読み取り時に自動解消 |

## クイックスタート

```bash
# 1. PukiWiki ルートに配置
cp -r rest-api /var/www/pukiwiki/

# 2. 初回インデックス構築
cd /var/www/pukiwiki
php rest-api/bin/build-index.php

# 3. API キーを発行（詳細は docs/api-key-management.md）
# → PHP スクリプトで Ledger::registerApiKey() を呼ぶ

# 4. 疎通確認
curl -H "Authorization: Bearer <token>" https://example.com/rest-api/api/v1/pages/FrontPage
```

## エンドポイント概要

```
GET    /pages                      ページ一覧
GET    /pages/{page}               ページ取得
PUT    /pages/{page}               ページ全文上書き（page:write）
PATCH  /pages/{page}               ブロック単位直接編集（page:write）
POST   /pages/{page}               下書き作成（draft:create）
GET    /pages/{page}/blocks        ブロック分割表示
POST   /pages/{page}/blocks        ブロックパッチから下書き作成
GET    /search?q=...               全文検索（FTS5 trigram）
GET    /drafts                     下書き一覧（draft:approve）
GET    /drafts/{id}                下書き詳細
POST   /drafts/{id}/approve        下書き承認
POST   /drafts/{id}/reject         下書き却下
GET    /admin/audit                監査ログ（admin）
POST   /admin/drafts/expire        期限切れ下書きを一括失効
GET    /admin/pages/{page}/revisions   リビジョン一覧
POST   /admin/pages/{page}/rollback    ロールバック
DELETE /admin/pages/{page}         ソフト削除
```

## MCP ツール（Claude 連携）

| ツール | 機能 |
|--------|------|
| `wiki_read_page` | ページ取得 |
| `wiki_search` | 全文検索 |
| `wiki_list_pages` | ページ一覧 |
| `wiki_read_blocks` | ブロック分割 |
| `wiki_create_draft` | 下書き作成 |
| `wiki_get_draft` | 下書き確認 |
| `wiki_patch_blocks` | ブロック差分から下書き作成 |

> MCP サーバーは `page:write` を公開しない。AI がページを直接書き換える手段は存在しない。

## テスト

```bash
# 全フェーズ（Phase 0〜8）のテストを実行
for i in 0 1 2 3 4 5 6 7 8; do
  php rest-api/test/phase${i}_test.php
done
# → 728 テスト全合格
```

## ドキュメント

- [docs/setup.md](rest-api/docs/setup.md) — インストール・環境設定
- [docs/api-reference.md](rest-api/docs/api-reference.md) — 全エンドポイントリファレンス
- [docs/api-key-management.md](rest-api/docs/api-key-management.md) — API キー管理

## 必要環境

- PHP 8.1 以上（`pdo_sqlite`, `zlib` 拡張）
- Apache（`mod_rewrite`）
- PukiWiki 1.5.4

## アーキテクチャ（3層）

```
データプレーン（正本）  wiki/*.txt, revisions/_blobs/
制御プレーン（台帳）   SQLite: pages, revisions, drafts, audit, api_keys, locks
検索インデックス（派生）SQLite: page_index + FTS5 (trigram)
```
