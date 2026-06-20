# PukiWiki REST API — セットアップガイド

> **ドキュメント種別**: セットアップ・運用手順  
> **対象バージョン**: Phase 0〜8（2026-06-21 時点）  
> **想定読者**: サーバー管理者・開発者・LLM エージェント

---

## 概要

このモジュールは PukiWiki 1.5.4 に REST API と MCP（Model Context Protocol）サーバーを追加する拡張です。  
**PukiWiki 本体は一切改変しません**。`rest-api/` フォルダを置くだけで動作します。

### 設計の核

```
Files are canonical      ← wiki/*.txt が唯一の正本
API is entry point       ← 読み書きは必ず API 経由
AI is contributor        ← AI は下書きを作るだけ
Humans are approvers     ← 本番への反映は人間が承認
```

AI（MCP 経由）はページを直接書き換えられません。提案（下書き）を出し、人間が承認したときに初めてページに反映されます。

---

## 必要な環境

| 要件 | バージョン・備考 |
|------|----------------|
| PHP | 8.1 以上（8.2+ 推奨）。`fsync()` を使うため 8.1 未満は不可 |
| PHP 拡張 | `pdo_sqlite`, `zlib`（`php -m` で確認） |
| Apache | `mod_rewrite` が有効であること |
| PukiWiki | 1.5.4（他バージョン未確認） |
| SQLite | PHP の PDO_SQLite に内蔵。別途インストール不要 |

---

## ディレクトリ構成

```
/var/www/pukiwiki/               ← PukiWiki ルート（既存）
├── wiki/                        ← 本文 .txt ファイル（既存・正本）
├── pukiwiki.ini.php             ← PukiWiki 設定（既存）
│
└── rest-api/                    ← ← このフォルダを丸ごとコピー
    ├── api/
    │   ├── .htaccess            ← mod_rewrite ルール
    │   ├── review.php           ← 下書きレビュー HTML UI
    │   └── v1/
    │       └── index.php        ← REST API フロントコントローラ
    ├── mcp/
    │   ├── server.php           ← MCP stdio サーバー（Claude Desktop 用）
    │   └── McpHandler.php
    ├── lib/                     ← PHP クラス群
    ├── bin/
    │   └── build-index.php      ← インデックス再構築 CLI
    ├── schema/
    │   └── init.sql             ← SQLite スキーマ
    ├── bootstrap.php            ← 共通初期化
    └── data/                    ← 自動生成（Web 非公開）
        ├── db/
        │   └── ledger.sqlite    ← 台帳・インデックス
        └── revisions/
            └── _blobs/          ← 版スナップショット（content-addressed）
```

`data/` は初回起動時に自動作成されます。

---

## インストール手順

### ステップ 1: ファイルを配置する

```bash
cp -r rest-api /var/www/pukiwiki/
```

### ステップ 2: ファイルのパーミッションを設定する

```bash
# Web サーバー（www-data など）が data/ に書き込めるようにする
chown -R www-data:www-data /var/www/pukiwiki/rest-api/data
chmod -R 750 /var/www/pukiwiki/rest-api/data

# PHP スクリプト自体は実行不要（読み取りのみ）
chmod -R 644 /var/www/pukiwiki/rest-api/lib
chmod -R 644 /var/www/pukiwiki/rest-api/api
```

### ステップ 3: Apache の設定を確認する

`rest-api/api/.htaccess` は `mod_rewrite` を使います。  
`.htaccess` が効くように PukiWiki の `<Directory>` に `AllowOverride All` を設定してください。

```apacheconf
<Directory /var/www/pukiwiki>
    AllowOverride All
    Require all granted
</Directory>
```

`mod_rewrite` が有効か確認:

```bash
apache2ctl -M | grep rewrite
# → rewrite_module (shared) が出れば OK
```

### ステップ 4: 初回インデックスを構築する

```bash
cd /var/www/pukiwiki
php rest-api/bin/build-index.php
```

出力例:
```
Wiki dir  : /var/www/pukiwiki/wiki
DB path   : /var/www/pukiwiki/rest-api/data/db/ledger.sqlite

=== 検索インデックス再構築（page_index のみ）===
完了: 42 ページをインデックス化（0.12 秒）
```

### ステップ 5: 動作確認

API キーなしで疎通確認（認証不要エンドポイントはないため 401 が返れば正常）:

```bash
curl -i https://example.com/rest-api/api/v1/pages/FrontPage
# → HTTP/1.1 401 Unauthorized
```

---

## 環境変数

`bootstrap.php` と各スクリプトは以下の環境変数を参照します。

| 変数 | 既定値 | 説明 |
|------|--------|------|
| `PKWK_ROOT` | `rest-api/` の親ディレクトリ | PukiWiki のルートパス |
| `PKWK_MCP_ACTOR` | `mcp-client` | MCP 経由操作の監査ログ名 |
| `PKWK_PROTECTED_PAGES` | `["FrontPage","MenuBar"]` | PUT/PATCH 直接編集から保護するページ（JSON 配列） |

例（`/etc/apache2/envvars` または `httpd.conf`）:

```apacheconf
SetEnv PKWK_ROOT /var/www/pukiwiki
SetEnv PKWK_PROTECTED_PAGES '["FrontPage","MenuBar","RecentChanges"]'
```

---

## インデックス管理 CLI

```bash
# 整合性チェック（書き込みなし）
php rest-api/bin/build-index.php --verify-only

# page_index（検索インデックス）だけ再構築
php rest-api/bin/build-index.php

# DB 全体を wiki/ ファイルから再構築（pages・revisions テーブルも含む）
php rest-api/bin/build-index.php --full-rebuild
```

DB を丸ごと削除しても `wiki/` と `data/revisions/_blobs/` があれば `--full-rebuild` で復元できます。

---

## MCP サーバー設定（Claude Desktop / Claude Code）

`~/Library/Application Support/Claude/claude_desktop_config.json`（macOS）に追記:

```json
{
  "mcpServers": {
    "pukiwiki": {
      "command": "php",
      "args": ["/var/www/pukiwiki/rest-api/mcp/server.php"],
      "env": {
        "PKWK_ROOT":      "/var/www/pukiwiki",
        "PKWK_MCP_ACTOR": "claude-desktop"
      }
    }
  }
}
```

Claude Desktop を再起動すると、以下の MCP ツールが利用可能になります:

| ツール名 | 機能 | スコープ |
|----------|------|---------|
| `wiki_read_page` | ページ本文・メタ取得 | 不要（MCP 接続自体が認証） |
| `wiki_search` | 全文検索（日英 trigram） | 〃 |
| `wiki_list_pages` | ページ一覧 | 〃 |
| `wiki_read_blocks` | ブロック分割表示 | 〃 |
| `wiki_create_draft` | 下書き作成 | 〃 |
| `wiki_get_draft` | 下書き確認 | 〃 |
| `wiki_patch_blocks` | ブロック差分から下書き作成 | 〃 |

> MCP サーバーは `page:write` を**一切公開しない**。AI がページを直接書き換える手段は存在しない。

---

## 保護ページ

デフォルトで `FrontPage` と `MenuBar` は `PUT`/`PATCH` による直接編集が禁止されています（`403 page_protected`）。  
これらは必ず下書きワークフロー（`POST /pages/{page}` or `wiki_create_draft`）を経由してください。

変更は `PKWK_PROTECTED_PAGES` 環境変数で行います（上記「環境変数」参照）。

---

## トラブルシューティング

| 症状 | 確認事項 |
|------|---------|
| `500 Internal Server Error` | `error_log` を確認。`data/` の書き込み権限、`pdo_sqlite`/`zlib` 拡張の有無 |
| `/pages/...` が 404 | `mod_rewrite` が有効か、`.htaccess` が読まれているか（`AllowOverride All`） |
| 検索で結果なし | `php bin/build-index.php --verify-only` でインデックス確認 |
| MCP ツールが出ない | `php -l rest-api/mcp/server.php` でシンタックスエラー確認。`PKWK_ROOT` が正しいか |
| `pdo_sqlite` エラー | `php -m \| grep sqlite` → ない場合は `php-sqlite3` パッケージをインストール |

---

## 関連ドキュメント

- [api-reference.md](api-reference.md) — 全エンドポイントのリファレンス
- [api-key-management.md](api-key-management.md) — API キーの発行・管理
