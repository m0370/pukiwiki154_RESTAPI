# PukiWiki REST API v2 — セットアップガイド

> **対象**: PukiWiki 1.5.4 / PHP 8.1+  
> **方式**: ファイルのみ（SQLite 等の DB は使わない）。正本は従来通り `wiki/*.txt`  
> **ライセンス**: GPL v2 or (at your option) any later version（PukiWiki 本体に準拠）

---

## 概要

PukiWiki 本体を**一切改変せず**、`rest-api-v2/` フォルダを PukiWiki ルートに置くだけで
REST API と MCP サーバーを追加する拡張です。

```
Files are canonical   ← wiki/*.txt が唯一の正本（DB なし）
page_write() 経由     ← Web UI と同一の副作用（diff/backup/RecentChanges/links）
2 スコープ API キー   ← read（閲覧・検索） / write（直接編集）
全版スナップショット  ← API 書き込みごとに旧版・新版を保存（追記専用）
```

v0.1（`rest-api/`、SQLite＋下書き承認方式）は参考実装として残していますが**非推奨**です。

## 必要な環境

| 要件 | 内容 |
|------|------|
| PHP | 8.1 以上（`zlib` 拡張。DB 拡張は不要） |
| Web サーバー | Apache（`mod_rewrite`, `AllowOverride All`）推奨 |
| PukiWiki | 1.5.4（UTF-8 版で確認） |
| ファイルシステム | **ローカル FS 必須**。iCloud/Dropbox/NFS 等の同期フォルダでは flock/rename の保証が失われるため運用不可 |

## インストール

```bash
# 1. 配置
cp -r rest-api-v2 /var/www/pukiwiki/

# 2. 書き込み権限（Web サーバーのユーザーに合わせる）
chown -R www-data:www-data /var/www/pukiwiki/rest-api-v2/data
chmod 750 /var/www/pukiwiki/rest-api-v2/data

# 3. API キーを発行（生キーは一度だけ表示される）
php /var/www/pukiwiki/rest-api-v2/bin/make-key.php --label my-editor --scope write
php /var/www/pukiwiki/rest-api-v2/bin/make-key.php --label ai-reader --scope read

# 4. 疎通確認（401 が返れば認証が効いている）
curl -i https://example.com/rest-api-v2/api/v1/pages/FrontPage
# → 401 Unauthorized

curl -H "Authorization: Bearer pkw2_..." \
     https://example.com/rest-api-v2/api/v1/pages/FrontPage
# → 200 + JSON
```

### Apache 設定

`.htaccess` を有効にしてください:

```apacheconf
<Directory /var/www/pukiwiki>
    AllowOverride All
    Require all granted
</Directory>
```

- `rest-api-v2/.htaccess` が `data/` への直接アクセスを拒否します（**必ず動作確認すること**。下記）
- `rest-api-v2/api/.htaccess` が URL 書き換えと Authorization ヘッダの引き継ぎを行います
- `RewriteBase` は使っていないため、PukiWiki がサブディレクトリ設置でも動きます
- 階層ページ名は `%2F` に依存しないため `AllowEncodedSlashes` の変更は不要です

**設置後に必ず確認**:

```bash
curl -i https://example.com/rest-api-v2/data/keys.php      # → 403 であること
curl -i https://example.com/rest-api-v2/data/audit/        # → 403 であること
```

403 にならない場合は `AllowOverride` の設定を見直すか、`data/` を DocRoot 外に移して
環境変数 `PKWK_REST_DATA` で指すようにしてください。

### mod_rewrite が使えない環境

書き換えなしでも PATH_INFO 形式で動作します:

```
https://example.com/rest-api-v2/api/v1/index.php/pages/FrontPage
```

### PHP を CGI/FastCGI で動かしている場合

Authorization ヘッダが PHP に渡らないことがあります。`api/.htaccess` の
RewriteRule（`E=HTTP_AUTHORIZATION`）で大半は解決しますが、それでも 401 になる場合は
Apache 2.4.13+ で `api/.htaccess` に `CGIPassAuth On` を追記してください。

## API キー管理

```bash
php bin/make-key.php --label <名前> --scope <read|write> \
    [--expires 2027-01-01] [--ip 203.0.113.5]   # IP は CIDR・カンマ区切り可
php bin/make-key.php --list
php bin/make-key.php --revoke <名前>
```

- キーの平文は `data/keys.php` に**保存されない**（SHA-256 ハッシュのみ）
- キーは必ず `Authorization: Bearer` ヘッダで送る。URL クエリに載せない
- `label` は監査ログとページの `#author` 行に記録される
- IP 制限は IPv4 のみ対応。IPv6 クライアントは fail-safe で拒否される

### スコープの考え方

| スコープ | できること | 想定用途 |
|---------|-----------|---------|
| `read`  | ページ取得・一覧・検索・スナップショット閲覧 | AI ボット、検索インデクサ、バックアップ |
| `write` | read ＋ ページ全文の書き込み（作成・更新） | 自分のスクリプト、信頼する編集エージェント |

**削除 API はありません。** ページの削除・凍結・リネームは PukiWiki の Web UI で行います。

## 書き込みの安全装置

write キーでも以下は常に強制されます:

1. **楽観ロック（CAS）**: `base_sha1`（読んだ時点の sha1）が現在と一致しないと 409。
   古い版に基づく上書き事故を防ぐ
2. **凍結ページ拒否**: `#freeze` されたページへの書き込みは 403。
   **凍結＝「API 編集不可マーカー」**として使える（重要ページは凍結しておく）
3. **保護ページ**: `FrontPage`・`MenuBar` は 403（環境変数 `PKWK_PROTECTED_PAGES` で変更可）
4. **システムページ**: `:config` など `:` 始まりは 403
5. **空本文拒否**: 空の content は 400（PukiWiki は空本文をページ削除として扱うため）
6. **全版スナップショット**: 書き込み前後の版を `data/snapshots/` に gzip 保存（削除しない）
7. **監査ログ**: 全書き込み・拒否を `data/audit/audit-YYYYMM.jsonl` に追記
8. **#author 記録**: 保存されたページの `#author` 行にキーの label が入る
   （PukiWiki の差分画面だけで「誰が API で書いたか」を追跡できる）

## MCP サーバー（Claude Desktop / Claude Code）

`claude_desktop_config.json`（または `.mcp.json`）に追記:

```json
{
  "mcpServers": {
    "pukiwiki": {
      "command": "php",
      "args": ["/var/www/pukiwiki/rest-api-v2/mcp/server.php"],
      "env": {
        "PKWK_ROOT":      "/var/www/pukiwiki",
        "PKWK_MCP_ACTOR": "claude-desktop"
      }
    }
  }
}
```

ツール: `wiki_read_page` / `wiki_list_pages` / `wiki_search` / `wiki_write_page`

> **注意**: v2 の MCP は書き込みツールを公開します（AI に直接編集を許可する設計判断）。
> 上記の安全装置（CAS・凍結・保護ページ・スナップショット・監査）はすべて効きますが、
> AI に触らせたくないページは**凍結**してください。
> MCP はローカルプロセスとして動くため API キー認証はありません
> （サーバーを起動できる人＝編集できる人）。

## 環境変数

| 変数 | 既定値 | 説明 |
|------|--------|------|
| `PKWK_ROOT` | `rest-api-v2/` の親 | PukiWiki ルート |
| `PKWK_REST_DATA` | `rest-api-v2/data` | データディレクトリ（DocRoot 外に出す場合に使用） |
| `PKWK_API_KEYS` | `{data}/keys.php` | キー設定ファイルのパス |
| `PKWK_PROTECTED_PAGES` | `["FrontPage","MenuBar"]` | 保護ページ（JSON 配列） |
| `PKWK_MCP_ACTOR` | `mcp-client` | MCP 操作の監査ログ・#author 名 |

## テスト

```bash
# ユニットテスト（PukiWiki 本体不要）
php rest-api-v2/test/unit_test.php

# 統合テスト（実 PukiWiki の使い捨てコピーに対して実行）
cp -r /var/www/pukiwiki /tmp/pkwk-test
PKWK_ROOT=/tmp/pkwk-test php rest-api-v2/test/integration_test.php
```

## トラブルシューティング

| 症状 | 確認事項 |
|------|---------|
| 常に 401 | キー作成済みか（`--list`）、`Authorization: Bearer` の綴り、CGI 環境の Authorization 引き継ぎ |
| `/pages/...` が 404 | `mod_rewrite`／`AllowOverride All`。または PATH_INFO 形式 URL を試す |
| 500 エラー | PHP の error_log。`data/` の書き込み権限。`PKWK_ROOT` の指し先 |
| 409 が続く | 書き込み前に必ずページを取得し直し、返ってきた `sha1`／`new_sha1` を次の `base_sha1` に使う |
| 保存内容が送信内容と違う | 正常動作。PukiWiki が `#author` 行・見出しアンカーを付与する。`new_sha1` を信用する |
