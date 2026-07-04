# pukiwiki-mcp

PukiWiki 1.5.4 REST API 拡張（[rest-api-v2](../rest-api-v2/)）用の
**MCP（Model Context Protocol）stdio ブリッジ**です。
Claude Desktop / Claude Code から、リモートの PukiWiki を API キー認証付きで読み書きできます。

```
Claude Desktop / Claude Code
        │  MCP (stdio / JSON-RPC 2.0)
        ▼
  pukiwiki-mcp（このブリッジ・手元の PC で動く）
        │  HTTPS + Authorization: Bearer pkw2_...
        ▼
  PukiWiki サーバーの REST API（rest-api-v2）
        │  page_write() 経由（凍結・CAS・スナップショット・監査ログが全て効く）
        ▼
  wiki/*.txt
```

同一マシンで動かす PHP 直結版（`rest-api-v2/mcp/server.php`・API キー不要）とは
別物です。使い分けは[本体 README のセクション 5](../README.md#5-mcp-サーバーclaude-連携) を参照してください。

## 必要な環境

- Node.js 18 以上（依存パッケージなし・`npm install` 不要）
- PukiWiki サーバー側に [rest-api-v2](../rest-api-v2/) がインストール済みであること
- API キー（サーバー管理者が `php rest-api-v2/bin/make-key.php` で発行。
  閲覧のみなら `--scope read`、編集もするなら `--scope write`）

## セットアップ

### Claude Desktop

`claude_desktop_config.json` に追記:

```json
{
  "mcpServers": {
    "pukiwiki": {
      "command": "node",
      "args": ["/path/to/pukiwiki-mcp/server.mjs"],
      "env": {
        "PUKIWIKI_API_URL": "https://example.com/rest-api-v2/api/v1",
        "PUKIWIKI_API_KEY": "pkw2_..."
      }
    }
  }
}
```

### Claude Code

```bash
claude mcp add pukiwiki \
  --env PUKIWIKI_API_URL=https://example.com/rest-api-v2/api/v1 \
  --env PUKIWIKI_API_KEY=pkw2_... \
  -- node /path/to/pukiwiki-mcp/server.mjs
```

プロジェクト共有の `.mcp.json` にキーを直書きしたくない場合は、
Claude Code の環境変数展開（`"PUKIWIKI_API_KEY": "${PUKIWIKI_API_KEY}"`）が使えます。

### 環境変数

| 変数 | 必須 | 説明 |
|------|------|------|
| `PUKIWIKI_API_URL` | ✓ | REST API のベース URL（例 `https://host/rest-api-v2/api/v1`。mod_rewrite 無し環境は `.../api/v1/index.php`） |
| `PUKIWIKI_API_KEY` | ✓ | API キー（`pkw2_...`。発行時に一度だけ表示される生キー） |
| `PUKIWIKI_TIMEOUT_MS` | — | HTTP タイムアウト（既定 30000） |

## ツール一覧

| ツール | 機能 | 必要スコープ |
|--------|------|------|
| `wiki_read_page` | ページ取得（sha1・凍結状態付き） | read |
| `wiki_list_pages` | ページ一覧 | read |
| `wiki_search` | 全文検索（日本語対応・2 文字以上） | read |
| `wiki_write_page` | 全文書き込み（`base_sha1` 楽観ロック必須） | write |
| `wiki_page_revisions` | API 書き込みスナップショットの一覧 | read |
| `wiki_read_revision` | 過去スナップショットの本文取得 | read |

書き込みの安全装置（凍結ページ拒否・CAS・保護ページ・スナップショット・監査ログ・
`#author` 記録）はすべて REST API サーバー側で強制されます。ブリッジ側からは
迂回できません。

## テスト

```bash
cd pukiwiki-mcp
node --test
```

モック REST サーバーを内部で立ち上げ、ブリッジ本体を子プロセスとして起動して
stdio 越しに全ツール・エラー系（409 競合 / 凍結 403 / スコープ不足 / 401 等）を検証します。

## ライセンス

GPL v2 or (at your option) any later version（PukiWiki 1.5.4 本体に準拠）
