# PukiWiki 1.5.4 REST API Extension

> **Version**: v2.0（`rest-api-v2/`）  
> **対象**: PukiWiki 1.5.4 / PHP 8.1+  
> **ライセンス**: MIT

PukiWiki 1.5.4 に REST API と MCP（Model Context Protocol）サーバーを追加する拡張モジュールです。  
**PukiWiki 本体を一切改変せず**、`rest-api-v2/` フォルダを置くだけで動作します。

## 設計方針（v2）

```
Files are canonical      ← wiki/*.txt が唯一の正本（SQLite 等の DB は使わない）
page_write() 経由        ← Web UI と同一の副作用（diff/backup/RecentChanges/links）
2 スコープ API キー      ← read（閲覧・検索のみ） / write（直接編集可）
全版スナップショット     ← API 書き込みごとに旧版・新版を追記保存
凍結 = 編集不可マーカー  ← #freeze したページは API からも書けない
```

v0.1（SQLite 台帳＋下書き承認ワークフロー方式）は検証の結果、PukiWiki 統合層に
致命的な問題が複数見つかったため、ファイルのみ方式で再実装しました。
旧実装は `rest-api/` に**非推奨の参考実装**として残しています。

## 主な機能（v2.0）

| 機能 | 説明 |
|------|------|
| REST API | ページの読み取り・一覧・検索・全文書き込み・過去版取得 |
| MCP サーバー | Claude Desktop / Claude Code から読み書き（4 ツール） |
| 楽観ロック（CAS） | `base_sha1` 必須。古い版に基づく上書きは 409 で拒否 |
| 2 スコープキー | read / write。SHA-256 ハッシュ保存・有効期限・IP 制限 |
| 全版スナップショット | `data/snapshots/` に gzip 追記保存（PukiWiki backup の取りこぼしを補完） |
| 監査ログ | 全書き込み・拒否を JSONL に追記。`#author` 行にも操作者を記録 |
| 安全装置 | 凍結・保護・システムページ拒否 / 空本文（＝削除）拒否 / flock 直列化 |

## クイックスタート

```bash
# 1. PukiWiki ルートに配置
cp -r rest-api-v2 /var/www/pukiwiki/

# 2. API キーを発行（生キーは一度だけ表示）
php /var/www/pukiwiki/rest-api-v2/bin/make-key.php --label my-editor --scope write

# 3. 疎通確認
curl -H "Authorization: Bearer pkw2_..." \
     https://example.com/rest-api-v2/api/v1/pages/FrontPage
```

詳細は [rest-api-v2/docs/setup.md](rest-api-v2/docs/setup.md) を参照。

## エンドポイント

```
GET  /pages                        ページ一覧                 [read]
GET  /pages/{page}                 ページ取得（sha1 付き）     [read]
PUT  /pages/{page}                 全文書き込み（CAS 必須）    [write]
GET  /pages/{page}/revisions       スナップショット一覧        [read]
GET  /pages/{page}/revisions/{id}  過去版の取得               [read]
GET  /search?q=...                 全文検索（日本語対応）      [read]
```

- 階層ページ名（`親/子/孫`）はパスにそのまま書ける（`%2F` 不要）
- 削除 API は提供しない（削除・凍結・リネームは Web UI で行う）

## MCP ツール（Claude 連携）

| ツール | 機能 |
|--------|------|
| `wiki_read_page`  | ページ取得（sha1・凍結状態付き） |
| `wiki_list_pages` | ページ一覧 |
| `wiki_search`     | 全文検索 |
| `wiki_write_page` | 全文書き込み（base_sha1 必須・凍結/保護ページ拒否） |

> v2 の MCP は書き込みツールを公開します。AI に触らせたくないページは
> PukiWiki の**凍結機能**で保護してください。

## テスト

```bash
# ユニットテスト（PukiWiki 本体不要・57 件）
php rest-api-v2/test/unit_test.php

# 統合テスト（実 PukiWiki の使い捨てコピーに対して・41 件）
cp -r /path/to/pukiwiki /tmp/pkwk-test
PKWK_ROOT=/tmp/pkwk-test php rest-api-v2/test/integration_test.php
```

統合テストは本物の `page_write()` を通し、v0.1 で発見された問題
（sha1 乖離・空本文削除・凍結素通し等）の再発を検証します。

## ドキュメント

- [rest-api-v2/docs/setup.md](rest-api-v2/docs/setup.md) — インストール・Apache 設定・キー管理・セキュリティ
- [rest-api-v2/docs/api-reference.md](rest-api-v2/docs/api-reference.md) — 全エンドポイントとエラーコード

## 必要環境

- PHP 8.1 以上（`zlib` 拡張。DB 拡張は不要）
- Apache（`mod_rewrite` 推奨。なければ PATH_INFO 形式 URL で動作）
- PukiWiki 1.5.4
- **ローカルファイルシステム**（iCloud/NFS 等の同期フォルダでは運用しない）

## ディレクトリ構成

```
rest-api-v2/
├── bootstrap.php        PukiWiki 本体の正規初期化を再現してロード
├── api/v1/index.php     REST フロントコントローラ
├── lib/                 Auth / PageStore / SnapshotStore / Audit / Router / Response
├── mcp/server.php       MCP stdio サーバー
├── bin/make-key.php     API キー管理 CLI
├── data/                キー・スナップショット・監査ログ（Web 非公開）
└── test/                ユニット＋統合テスト

rest-api/                v0.1（非推奨・参考実装）
```
