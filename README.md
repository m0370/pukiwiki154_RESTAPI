# PukiWiki 1.5.4 REST API Extension

> **Version**: v2.0（`rest-api-v2/`）  
> **対象**: PukiWiki 1.5.4（UTF-8 版）/ PHP 8.1+  
> **ライセンス**: GPL v2 or (at your option) any later version（PukiWiki 1.5.4 本体に準拠）

PukiWiki 1.5.4 に REST API と MCP（Model Context Protocol）サーバーを追加する拡張モジュールです。  
**PukiWiki 本体を一切改変せず**、`rest-api-v2/` フォルダを置くだけで動作します。

```
Files are canonical      ← wiki/*.txt が唯一の正本（SQLite 等の DB は使わない）
page_write() 経由        ← Web UI と同一の副作用（diff/backup/RecentChanges/links）
2 スコープ API キー      ← read（閲覧・検索のみ） / write（直接編集可）
全版スナップショット     ← API 書き込みごとに旧版・新版を追記保存
凍結 = 編集不可マーカー  ← #freeze したページは API からも書けない
```

---

## 目次

1. [必要な環境](#1-必要な環境)
2. [インストール](#2-インストール)
3. [API キーの発行と管理](#3-api-キーの発行と管理)
4. [REST API の使い方](#4-rest-api-の使い方)
5. [MCP サーバー（Claude 連携）](#5-mcp-サーバーclaude-連携)
6. [書き込みの安全装置](#6-書き込みの安全装置)
7. [テスト](#7-テスト)
8. [トラブルシューティング](#8-トラブルシューティング)
9. [ディレクトリ構成](#9-ディレクトリ構成)
10. [ライセンス](#10-ライセンス)

---

## 1. 必要な環境

| 要件 | 内容 |
|------|------|
| PHP | 8.1 以上（DB 拡張は不要。`zlib` 拡張は推奨 — 無い環境ではスナップショットが非圧縮 `.txt` で保存される） |
| Web サーバー | Apache（`mod_rewrite` + `AllowOverride All`）推奨。なくても PATH_INFO 形式で動作 |
| PukiWiki | 1.5.4（UTF-8 版で動作確認） |
| ファイルシステム | **ローカル FS 必須**。iCloud / Dropbox / NFS 等の同期フォルダでは flock・rename の保証が失われるため運用不可 |

## 2. インストール

```bash
# 1. PukiWiki ルート直下に配置（wiki/ や pukiwiki.ini.php と同じ階層）
cp -r rest-api-v2 /var/www/pukiwiki/

# 2. データディレクトリ（キー・監査ログ・スナップショット）を DocRoot の外に作成【標準】
mkdir -p /var/lib/pukiwiki-rest/data
chown -R www-data:www-data /var/lib/pukiwiki-rest/data
chmod 750 /var/lib/pukiwiki-rest/data
```

作成した場所を環境変数 `PKWK_REST_DATA` で API に知らせます。

Apache（mod_php / CGI）の場合:

```apacheconf
<Directory /var/www/pukiwiki>
    AllowOverride All
    Require all granted
    SetEnv PKWK_REST_DATA /var/lib/pukiwiki-rest/data
</Directory>
```

PHP-FPM の場合はプール設定に `env[PKWK_REST_DATA] = /var/lib/pukiwiki-rest/data`、
nginx の場合はあわせて `fastcgi_param PKWK_REST_DATA /var/lib/pukiwiki-rest/data;` を設定します。

> **DocRoot 内デフォルト（`rest-api-v2/data`）のまま使う場合**
> `data/` の防御は `.htaccess` 頼みになるため、nginx・`php -S`・`AllowOverride None` の
> 環境では鍵や監査ログが Web に丸見えになります。このリスクを避けるため、
> **データディレクトリが DocRoot 内にあると API は既定で 500（`insecure_data_dir`）を返して
> 起動を拒否します**。下の (b) で 403 が返ることを確認した上で、環境変数
> `PKWK_REST_ALLOW_DOCROOT_DATA=1`（Apache: `SetEnv`、FPM: `env[...]`）を設定した場合のみ
> DocRoot 内で動作します。検証用途以外では DocRoot 外を推奨します。
>
> nginx で DocRoot 内に置く場合の deny 設定例:
>
> ```nginx
> location ~ ^/rest-api-v2/data/ { deny all; }
> ```

**設置後に必ず確認する 2 点**:

```bash
# (a) 認証が効いている（401 が返る）
curl -i https://example.com/rest-api-v2/api/v1/pages/FrontPage
# → HTTP/1.1 401 Unauthorized
# → 500 で code=insecure_data_dir の場合は PKWK_REST_DATA が DocRoot 内（上記参照）

# (b) data/（キー・監査ログ・スナップショット）が外部から見えない（403 が返る）
#     ※ DocRoot 内デフォルトのまま使う場合の確認。DocRoot 外なら物理的に配信されない
curl -i https://example.com/rest-api-v2/data/keys.php
# → HTTP/1.1 403 Forbidden   ← 200 が返る場合は AllowOverride の設定を見直すこと
```

## 3. API キーの発行と管理

キー管理はすべて CLI（`bin/make-key.php`）で行います。Web からキーを発行する画面は
意図的に用意していません。

### 3.1 キーを発行する

```bash
cd /var/www/pukiwiki

# データディレクトリを DocRoot 外に置いた場合は、Web 側と同じ場所を指定する
export PKWK_REST_DATA=/var/lib/pukiwiki-rest/data

# 編集も可能なキー（自分のスクリプト・信頼するエージェント用）
php rest-api-v2/bin/make-key.php --label my-editor --scope write

# 閲覧・検索のみのキー（AI ボット・検索インデクサ・バックアップ用）
php rest-api-v2/bin/make-key.php --label ai-reader --scope read

# 有効期限と IP 制限を付ける場合
php rest-api-v2/bin/make-key.php --label cron-backup --scope read \
    --expires 2027-01-01 --ip 203.0.113.5
# --ip は CIDR（198.51.100.0/24）やカンマ区切りの複数指定も可
```

実行すると生キーが**一度だけ**表示されます:

```
APIキーを作成しました。

  label  : my-editor
  scope  : write
  expires: (無期限)
  ip     : (制限なし)

┌─────────────────────────────────────────────────────────┐
  pkw2_0123456789abcdef0123456789abcdef0123456789abcdef
└─────────────────────────────────────────────────────────┘
⚠ このキーは今回しか表示されません。安全な場所に保管してください。
```

（上記のキーはドキュメント用のダミーです。実際には `pkw2_` ＋ランダムな 48 桁の
16 進文字列が生成されます）

- サーバー側（`data/keys.php`）には **SHA-256 ハッシュだけ**が保存されます。
  生キーを紛失した場合は失効して作り直してください
- `label` は監査ログと、保存されたページの `#author` 行に記録されます
  （PukiWiki の差分画面から「誰が API で書いたか」を追跡できます）

### 3.2 キーの一覧・失効

```bash
php rest-api-v2/bin/make-key.php --list
php rest-api-v2/bin/make-key.php --revoke my-editor   # 即時失効
```

### 3.3 スコープの考え方

| スコープ | できること | 想定用途 |
|---------|-----------|---------|
| `read`  | ページ取得・一覧・検索・過去版閲覧 | AI ボット、検索、バックアップ |
| `write` | read の全機能 ＋ ページ全文の作成・更新 | 自分のスクリプト、信頼する編集エージェント |

`read` スコープにも PukiWiki 本体の閲覧制限がそのまま効きます:

- `:config` など `:` 始まりのシステムページは取得・過去版とも 403（一覧・検索にも出ない）
- `$read_auth` で閲覧制限したページは取得・過去版が 403 になり、検索結果からも除外される

キーは必ず `Authorization: Bearer <キー>` ヘッダで送ります。
**URL のクエリパラメータに載せてはいけません**（アクセスログ・履歴に残るため）。

## 4. REST API の使い方

ベース URL: `https://example.com/rest-api-v2/api/v1`
（mod_rewrite が使えない環境では `.../rest-api-v2/api/v1/index.php` を前置）

```
GET  /pages                        ページ一覧                 [read]
GET  /pages/{page}                 ページ取得（sha1 付き）     [read]
PUT  /pages/{page}                 全文書き込み（CAS 必須）    [write]
GET  /pages/{page}/revisions       スナップショット一覧        [read]
GET  /pages/{page}/revisions/{id}  過去版の取得               [read]
GET  /search?q=...                 全文検索（日本語対応）      [read]
```

階層ページ名（`親/子/孫`）はパスにそのまま書けます（`%2F` エンコード不要）。

### 4.1 ページを読む

```bash
KEY="pkw2_..."
BASE="https://example.com/rest-api-v2/api/v1"

curl -H "Authorization: Bearer $KEY" "$BASE/pages/FrontPage"
```

```json
{
  "page": "FrontPage",
  "sha1": "cdd96d111d848fab8130021832d4b3b57deed7d3",
  "content": "#freeze\n#norelated\n* FrontPage [#qb249ac2]\n...",
  "is_frozen": true,
  "is_editable": false,
  "updated_at": "2026-07-01T22:47:19+00:00"
}
```

`sha1` が編集時の**楽観ロックのトークン**になります。

### 4.2 ページを編集する（読む → 直す → 書く）

```bash
# 1. 現在の内容と sha1 を取得
curl -H "Authorization: Bearer $KEY" "$BASE/pages/メモ/今日"

# 2. content を編集し、1 の sha1 を base_sha1 に指定して PUT
curl -X PUT "$BASE/pages/メモ/今日" \
  -H "Authorization: Bearer $KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "base_sha1": "cdd96d111d848fab8130021832d4b3b57deed7d3",
    "content": "*今日のメモ\n編集後の全文をここに入れる\n"
  }'
```

成功レスポンス:

```json
{
  "page": "メモ/今日",
  "is_new": false,
  "changed": true,
  "new_sha1": "1e11b9e4ba227387c04707ab44682900fc7485d3",
  "snapshot": "1782946039.123456_1e11b9e4..."
}
```

**知っておくべき挙動**:

- PukiWiki が本文を正規化します（`#author` 行の付与・見出しアンカー `[#xxxx]` の自動付与）。
  保存内容は送信内容と完全一致しません。**続けて編集するときは `new_sha1` を
  次の `base_sha1` に使う**（または GET し直す）
- 読んだ後に誰かがページを更新していた場合は `409 sha1_conflict` が返ります。
  → もう一度 GET して、最新の内容に自分の変更を当て直して PUT

### 4.3 新規ページを作る

`base_sha1` に空文字列の sha1（固定値）を指定します:

```bash
curl -X PUT "$BASE/pages/新しいページ" \
  -H "Authorization: Bearer $KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "base_sha1": "da39a3ee5e6b4b0d3255bfef95601890afd80709",
    "content": "*新しいページ\n本文\n"
  }'
# → 201 Created
```

### 4.4 検索・一覧

```bash
curl -H "Authorization: Bearer $KEY" "$BASE/search?q=%E8%83%83%E7%99%8C&limit=10"
curl -H "Authorization: Bearer $KEY" "$BASE/pages?limit=100&offset=0"
```

### 4.5 誤編集からの復旧（過去版の取得と復元）

API 経由の書き込みは全版が自動保存されています:

```bash
# 過去版の一覧（新しい順）
curl -H "Authorization: Bearer $KEY" "$BASE/pages/メモ/今日/revisions"

# 特定の版の本文を取得
curl -H "Authorization: Bearer $KEY" "$BASE/pages/メモ/今日/revisions/1782946039.123456_1e11b..."

# 復元 = 取得した content を、現在ページの sha1 を base_sha1 にして PUT し直す
# （履歴を消さず「過去版を新しい版として再適用」する方式。復元操作も記録に残る）
```

エラーコードの一覧など詳細は [rest-api-v2/docs/api-reference.md](rest-api-v2/docs/api-reference.md) を参照してください。

## 5. MCP サーバー（Claude 連携）

Claude Desktop / Claude Code から Wiki を直接読み書きできます。2 つの方式があります。

| | 方式 A: PHP 直結 | 方式 B: Node ブリッジ |
|---|---|---|
| 実体 | `rest-api-v2/mcp/server.php` | `pukiwiki-mcp/server.mjs` |
| 動作場所 | **PukiWiki と同じマシン**のみ | どこでも（リモート可） |
| 経路 | ファイル直接アクセス | REST API（HTTPS） |
| 認証 | なし（起動できる人＝編集できる人） | **API キー必須**（read / write スコープ） |
| 向いている人 | サーバー管理者本人 | 一般利用者・チーム配布 |

### 5.1 方式 A: PHP 直結（同一マシン）

`claude_desktop_config.json`（Claude Code の場合はプロジェクトの `.mcp.json`）に追記:

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

> **注意**: 方式 A はローカルプロセスとして動くため API キー認証はありません
> （サーバーを起動できる人＝編集できる人）。`PKWK_MCP_ACTOR` が監査ログと
> `#author` 行に記録されます。AI に触らせたくないページは**凍結**してください。

### 5.2 方式 B: Node ブリッジ（リモート・API キー認証）

Node.js 18 以上が必要です（依存パッケージなし・`npm install` 不要）。
[3 節](#3-api-キーの発行と管理)で発行した API キーを使います。

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

Claude Code ならコマンド一発でも登録できます:

```bash
claude mcp add pukiwiki \
  --env PUKIWIKI_API_URL=https://example.com/rest-api-v2/api/v1 \
  --env PUKIWIKI_API_KEY=pkw2_... \
  -- node /path/to/pukiwiki-mcp/server.mjs
```

read スコープのキーなら閲覧・検索のみ、write スコープなら編集も可能です。
書き込みはキーの label が監査ログと `#author` 行に記録されます。
詳細は [pukiwiki-mcp/README.md](pukiwiki-mcp/README.md) を参照してください。

### 5.3 ツール一覧

| ツール | 機能 | A | B |
|--------|------|---|---|
| `wiki_read_page`  | ページ取得（sha1・凍結状態付き） | ✓ | ✓ |
| `wiki_list_pages` | ページ一覧 | ✓ | ✓ |
| `wiki_search`     | 全文検索 | ✓ | ✓ |
| `wiki_write_page` | 全文書き込み（base_sha1 必須・凍結/保護ページ拒否） | ✓ | ✓ |
| `wiki_page_revisions` | API 書き込みスナップショットの一覧 | — | ✓ |
| `wiki_read_revision`  | 過去スナップショットの本文取得 | — | ✓ |

## 6. 書き込みの安全装置

write キー（および MCP）でも、以下は常に強制されます:

1. **楽観ロック（CAS）** — `base_sha1` が現在の sha1 と一致しないと 409。
   古い版に基づく上書き事故を防ぐ
2. **凍結ページ拒否** — `#freeze` されたページへの書き込みは 403。
   凍結が「API 編集不可マーカー」として機能する
3. **保護ページ** — `FrontPage`・`MenuBar` は 403（環境変数 `PKWK_PROTECTED_PAGES` で変更可）
4. **システムページ** — `:config` など `:` 始まりは 403
5. **空本文の拒否** — 空の content は 400（PukiWiki は空本文をページ削除として扱うため）。
   **削除 API はありません**。削除・凍結・リネームは Web UI で行います
6. **本文サイズ上限** — 既定 1MB を超える content は 413
   （環境変数 `PKWK_REST_MAX_BODY_BYTES` で変更可。REST・MCP 両方に効く）
7. **全版スナップショット** — 書き込み前後の版を `data/snapshots/` に保存（削除しない）
8. **監査ログ** — 全書き込み・拒否を `data/audit/audit-YYYYMM.jsonl` に追記
9. **#author 記録** — 保存ページの `#author` 行にキーの label / MCP actor が入る

読み取り側にも本体の閲覧認可が適用されます（`:` システムページの read 拒否、
`$read_auth` ページの read/revisions 403 と検索除外。[3.3 節](#33-スコープの考え方)参照）。

## 7. テスト

```bash
# ユニットテスト（PukiWiki 本体不要・74 件）
php rest-api-v2/test/unit_test.php

# 統合テスト（実 PukiWiki の使い捨てコピーに対して・47 件）
cp -r /var/www/pukiwiki /tmp/pkwk-test
PKWK_ROOT=/tmp/pkwk-test php rest-api-v2/test/integration_test.php

# Node ブリッジのテスト（モック REST サーバーに対して・25 件）
cd pukiwiki-mcp && node --test
```

統合テストは本物の `page_write()` を通し、v0.1 で発見された問題
（sha1 乖離・空本文削除・凍結素通し等）の再発を検証します。

## 8. トラブルシューティング

| 症状 | 確認事項 |
|------|---------|
| 常に 401 | キー作成済みか（`--list`）、`Authorization: Bearer` の綴り、CGI/FastCGI 環境の Authorization 引き継ぎ（[setup.md](rest-api-v2/docs/setup.md) 参照） |
| `/pages/...` が 404 | `mod_rewrite` / `AllowOverride All`。または `.../api/v1/index.php/pages/...` の PATH_INFO 形式を試す |
| 409 が続く | 書き込み前に GET し直し、返ってきた `sha1` / `new_sha1` を次の `base_sha1` に使う |
| 保存内容が送信内容と違う | 正常動作（`#author` 行・見出しアンカーの付与）。`new_sha1` を信用する |
| 500 `insecure_data_dir` | データディレクトリが DocRoot 内。`PKWK_REST_DATA` で外に移す（[2 節](#2-インストール)参照） |
| 413 が返る | 本文が上限（既定 1MB）超。`PKWK_REST_MAX_BODY_BYTES` で調整 |
| 403 `read_forbidden` | `$read_auth` の閲覧制限ページ。API キーでは閲覧制限を迂回できない（仕様） |
| 500 エラー | PHP の error_log、`data/` の書き込み権限、`PKWK_ROOT` の指し先 |

環境変数（`PKWK_ROOT` / `PKWK_REST_DATA` / `PKWK_API_KEYS` / `PKWK_PROTECTED_PAGES` /
`PKWK_MCP_ACTOR` / `PKWK_REST_ALLOW_DOCROOT_DATA` / `PKWK_REST_MAX_BODY_BYTES` /
`PKWK_REST_MAX_JSON_BYTES`）の詳細は [rest-api-v2/docs/setup.md](rest-api-v2/docs/setup.md) を参照。

## 9. ディレクトリ構成

```
rest-api-v2/
├── bootstrap.php        PukiWiki 本体の正規初期化を再現してロード
├── api/v1/index.php     REST フロントコントローラ
├── lib/                 Auth / PageStore / SnapshotStore / Audit / Router / Response
├── mcp/server.php       MCP stdio サーバー・方式 A（同一マシン・PHP 直結）
├── bin/make-key.php     API キー管理 CLI
├── data/                キー・スナップショット・監査ログ（Web 非公開）
├── docs/                setup.md / api-reference.md
└── test/                ユニット＋統合テスト

pukiwiki-mcp/            MCP stdio ブリッジ・方式 B（リモート・Node.js・REST API 経由）
├── server.mjs           エントリポイント（依存パッケージなし）
├── lib/                 rest-client / tools
└── test/                モック REST サーバーに対する自動テスト

rest-api/                v0.1（非推奨・参考実装。rest-api/DEPRECATED.md 参照）
```

v0.1（SQLite 台帳＋下書き承認方式）は検証で PukiWiki 統合層に致命的な問題が
複数見つかったため、ファイルのみ方式の v2 として再実装しました。

## 10. ライセンス

本拡張は **GPL v2 or (at your option) any later version** で配布します
（PukiWiki 1.5.4 本体のライセンスに準拠）。

PukiWiki 本体: Copyright © 2001-2022 PukiWiki Development Team — GPL v2+  
本文は PukiWiki 同梱の `COPYING.txt` を参照してください。
