# 手動設置・CLI・REST APIの技術ガイド

この文書は、サーバー管理者・開発者向けの追加手順です。通常の導入は[READMEのFTP・ブラウザー手順](../README.md#install)を使ってください。ブラウザーでのキー発行やClaude Desktopの拡張設定だけで利用でき、以下のコマンド操作は必須ではありません。

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
| Web サーバー | Apache（`mod_rewrite` + `AllowOverride All`）推奨。なくても query route（index.php?route=/...）で動作 |
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

### 2.1 FTP でアップロードする場合（レンタルサーバー）

`cp -r` が使えない環境では、**隠しファイルが落ちること**が最大の落とし穴です。
本体には機能上必要なドットファイルが 4 つあります:

```
rest-api-v2/.htaccess        api/ と setup/ 以外（lib・bin・mcp・test・docs・data・*.php）を 403 にする
rest-api-v2/api/.htaccess    URL 書き換えと Authorization ヘッダの引き継ぎ
rest-api-v2/data/.htaccess   data/ の deny（DocRoot 内に置く場合の最後の砦）
rest-api-v2/.user.ini        display_errors=Off（PHP 警告が JSON に混入するのを防ぐ）
```

FTPクライアントで隠しファイルの表示・転送を有効にし、上のファイルがサーバー側にも存在することを確認してください。
- **空ディレクトリは作らなくて構いません。** `data/snapshots` `data/audit` `data/locks`
  は初回アクセス時に API が自動で作成します
- **`test/` はアップロードしないでください。** 置かれても Web からは実行できません
  （各ファイルが `PHP_SAPI !== 'cli'` で 403 を返す）が、本番に不要です
- 本番用ZIPには必要なファイルだけを収録しています。開発ツリーの `test/` やローカル設定をそのまま配布しないでください

**アップロード後に必ず確認する**（上の (a)(b) に加えて）:

```bash
# (c) rest-api-v2/.htaccess が効いている（403 が返る）
curl -i https://example.com/rest-api-v2/config.local.php
# → HTTP/1.1 403 Forbidden   ← 200 や PHP ソースが返るなら .htaccess が落ちている

# (d) api/.htaccess が効いている（401 が返る = 書き換えが動作）
curl -i https://example.com/rest-api-v2/api/v1/pages/FrontPage
# → 404 が返るなら書き換えが効いていない。PATH_INFO 形式なら書き換え無しでも動く:
curl -i https://example.com/rest-api-v2/api/v1/index.php/pages/FrontPage
```

### 2.2 既存の設置をアップデートする場合

FTPで既存のAPIフォルダをバックアップし、新版を別名でアップロードします。
既存の `config.local.php` を新版へコピーした後、APIを利用していない時間にフォルダ名を入れ替えます。
切替中は短時間利用できなくなります。接続確認に失敗したら古いフォルダへ戻してください。
この方式なら個々のPHPファイルの転送順序を考える必要がありません。

既存キー・非公開保存先はそのまま使えます。`config.local.php` や非公開データを配布物で上書きしないでください。
旧構成でDocRoot内の `rest-api-v2/data` を明示許可している場合は、そのデータも失わないよう別途バックアップ・移行します。
ブラウザー初期設定はDocRoot外への新規設置が対象で、既存データの移設は自動では行いません。

`attachments_write` は既存キーには付与されません。添付変更が必要な場合は管理者画面で専用キーを発行してください。
MCPBも同梱の最新版へ更新します。PHP CLI直結MCPは互換版で、追加機能一式はNodeブリッジ／MCPBが対象です。

## 3. API キーの発行と管理

キー管理はブラウザーの `setup/index.php`、または CLI（`bin/make-key.php`）で行えます。ブラウザーではPukiWiki管理者の認証が必須です。

### 3.1 キーを発行する

```bash
cd /var/www/pukiwiki

# データディレクトリを DocRoot 外に置いた場合は、Web 側と同じ場所を指定する
export PKWK_REST_DATA=/var/lib/pukiwiki-rest/data

# 編集も可能なキー（自分のスクリプト・信頼するエージェント用）
php rest-api-v2/bin/make-key.php --label my-editor --scope write

# $edit_auth を有効にしているサイトでは、名乗る PukiWiki ユーザーの指定が必須
# （指定しないと書き込みが全ページ 403 になる。詳しくは 3.4 節）
php rest-api-v2/bin/make-key.php --label my-editor --scope write --wiki-user tgoto

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
  pkw2_ここに発行されたキーが表示されます
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
- `$read_auth` で閲覧制限したページは取得・過去版が 403 になり、**一覧と検索からも除外される**
  （本体 `lib/html.php` の `is_page_readable()` と同じ扱い）

`$read_auth` を使っているサイトでは、**read キーにも `--wiki-user` が要ります**
（下記 3.4 節）。付けないキーは未ログイン扱いのまま、取得が 403・一覧と検索が空になります。

キーは必ず `Authorization: Bearer <キー>` ヘッダで送ります。
**URL のクエリパラメータに載せてはいけません**（アクセスログ・履歴に残るため）。

### 3.4 `$read_auth` / `$edit_auth` を有効にしているサイト

`pukiwiki.ini.php` で `$edit_auth = 1` にしている場合、**そのままだと API の
書き込みが全ページ 403 `edit_forbidden` になります**。同様に `$read_auth = 1` なら
**取得が 403 `read_forbidden`、一覧と検索は空**になります。API にはログイン
セッションが無く、本体の `is_page_writable()` / `_is_page_accessible()` が
未ログインユーザーとして判定するためです。

とくに `$edit_auth_pages` / `$read_auth_pages` に `'##' => 'someone'` のような
**空の正規表現**を使ってサイト全体をロックしている場合、API は何も返しません。

キーに「どの PukiWiki ユーザーとして振る舞うか」を持たせて解決します:

```bash
php rest-api-v2/bin/make-key.php --label my-editor --scope write --wiki-user tgoto
php rest-api-v2/bin/make-key.php --label ai-reader --scope read  --wiki-user tgoto
```

リクエストの間だけ `$auth_user` / `$auth_user_groups` をそのユーザーに設定し、
PukiWiki 本体の判定を**正規に**通します。認可の迂回ではありません:

- そのユーザーが読めない・編集できないページは API からも 403 のまま
- 凍結・保護ページ・`:` システムページの拒否はそのまま効く
- `#author` 行に wiki ユーザー名とキーのラベルの両方が残り、差分画面から
  「どのキーが誰として書いたか」を追跡できる

`--wiki-user` を付けないキーは fail-closed です（`$read_auth = 0` かつ
`$edit_auth = 0` のサイトでは不要）。
MCP 方式 A では環境変数 `PKWK_MCP_WIKI_USER` が同じ役割を持ちます。

> ブラウザーでの発行時は `$auth_users` に存在するユーザーを確認します。CLI指定と発行後のユーザー削除は別途管理が必要です。 本体の `get_groups_from_username()`
> はユーザー名自身を暗黙のグループとして返すため、`$auth_users` から削除済みの
> ユーザー名でも認可を通ります。**Wiki のアカウントを消しても API キーは失効しません。**
> キーの停止は設定画面の「失効」または `make-key.php --revoke <ラベル>` で行ってください。

追加エンドポイント（添付・標準バックアップ・capabilities）は[APIリファレンス](../rest-api-v2/docs/api-reference.md)を参照してください。

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

エラーコードの一覧など詳細は [rest-api-v2/docs/api-reference.md](../rest-api-v2/docs/api-reference.md) を参照してください。


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

Claude Codeは **ユーザースコープ（`--scope user`）での登録を推奨**します。同じPC・同じOSユーザーのすべての作業フォルダから使えます。上のJSONは構造例で、`/path/to/...` や `pkw2_...` をそのまま登録するものではありません。

[macOS / Linux・Windows別の登録手順](../pukiwiki-mcp/README.md#claude-code-user)では、実際のサーバーパスを自動取得し、API URLとキーを入力して登録します。既存のlocal/project設定からの移行手順も同じページにあります。`run.sh` やmacOSキーチェーンは必須ではありません。

read スコープのキーなら閲覧・検索のみ、write スコープなら編集も可能です。
書き込みはキーの label が監査ログと `#author` 行に記録されます。
詳細は [pukiwiki-mcp/README.md](../pukiwiki-mcp/README.md) を参照してください。

### 5.3 ツール一覧

以下は両方式で使う基本操作の比較です。Node版／MCPBの全14ツールは[MCPのツール一覧](../pukiwiki-mcp/README.md#利用できる14ツール)を参照してください。

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
   古い版に基づく上書き事故を防ぐ。**認可は CAS より先に判定する**ため、
   閲覧できないページに対して 409 が現在の sha1 を返すことはない
   （認可前に存在とハッシュを漏らさない）
2. **凍結ページ拒否** — `#freeze` されたページへの書き込みは 403。
   凍結が「API 編集不可マーカー」として機能する
3. **保護ページ** — `FrontPage`・`MenuBar` は 403（環境変数 `PKWK_PROTECTED_PAGES` で変更可）
4. **システムページ** — `:config` など `:` 始まりは 403
5. **閲覧・編集認可（`$read_auth` / `$edit_auth`）** — 該当ページへの読み書きは、
   キーに `wiki_user` が無ければ 403（fail-closed）。`wiki_user` 付きキーは
   そのユーザーとして本体の判定を受ける
   （[3.4 節](#34-read_auth--edit_auth-を有効にしているサイト)）。
   書き込みでは「読めないページは書けない」も強制される
6. **空本文の拒否** — 空の content は 400（PukiWiki は空本文をページ削除として扱うため）。
   **ページ削除APIはありません**。ページの削除・凍結・リネームはWeb UIで行います。添付の削除保管は別APIで対応します
7. **本文サイズ上限** — 既定 1MB を超える content は 413
   （環境変数 `PKWK_REST_MAX_BODY_BYTES` で変更可。REST・MCP 両方に効く）
8. **全版スナップショット** — 書き込み前後の版を `data/snapshots/` に保存（削除しない）
9. **監査ログ** — 全書き込み・拒否を `data/audit/audit-YYYYMM.jsonl` に追記
10. **#author 記録** — 保存ページの `#author` 行にキーの label / MCP actor が入る
    （`wiki_user` 付きキーでは wiki ユーザー名とラベルの両方）
11. **Markdown 互換** — `md.inc.php`（v0.4+）が入っているサイトでは、保存を
    `md_page_write()` 経由に切り替えて `#md` ページの本文破壊を防ぐ（下記）

読み取り側にも本体の閲覧認可が適用されます（`:` システムページの read 拒否、
`$read_auth` ページの read/revisions 403 と、一覧・検索からの除外。
[3.3 節](#33-スコープの考え方)参照）。

認可に使う identity は、認証が済んだ時点で 1 つだけ作られる不変オブジェクト
（`lib/Identity.php`）です。read と write が同じ identity を見るため、
「読み取りだけ未ログイン扱いになる」といった取り違えが起きません。

### 6.1 Markdown プラグイン（`md.inc.php`）との互換

`page_write()` は保存時に `make_str_rules()` を通します。`#md` ページに対しては、
これが 2 つの副作用を起こします:

- `*` で始まる行（Markdown の箇条書き・強調）に見出しアンカー `[#xxxxxxxx]` が混入する
- `&now;` `&date;` `&time;` 等の `$str_rules` マクロが**実際の日時に置換される**

前者は描画側で修復できますが、**後者は不可逆でソースが失われます**:

```
入力:  現在時刻: &now;
保存:  現在時刻: 2026-08-07 (金) 07:57:44     ← 元に戻せない
```

`md.inc.php` v0.4+ は外部ライター向けに `md_page_write()` を提供しており、
本 API は保存時にこれを自動検出して使います（`#md` ページでのみ `$str_rules` と
`$fixed_heading_anchor` を一時無効化。非 Markdown ページは `page_write()` へ素通し）。
`md.inc.php` を入れていないサイトでは従来どおり `page_write()` を呼びます。

## 7. テスト

```bash
# ユニットテスト（PukiWiki 本体不要）
php rest-api-v2/test/unit_test.php

# 統合テスト（実 PukiWiki の使い捨てコピーに対して）
cp -r /var/www/pukiwiki /tmp/pkwk-test
PKWK_ROOT=/tmp/pkwk-test php rest-api-v2/test/integration_test.php

# サイト統合テスト（改造済みサイトとの噛み合わせ・該当しない項目は自動スキップ）
PKWK_ROOT=/tmp/pkwk-test php rest-api-v2/test/site_integration_test.php

# Node ブリッジのテスト（モック REST サーバーに対して）
cd pukiwiki-mcp && node --test
```

統合テストは本物の `page_write()` を通し、v0.1 で発見された問題
（sha1 乖離・空本文削除・凍結素通し等）の再発を検証します。素の 1.5.4 を前提に
しているため、`$edit_auth` を有効にしたサイトのコピーに対しては一部 FAIL します。

サイト統合テストはその逆で、**改造済みサイトに載せたときに壊れないか**を検証します
（`$read_auth` / `$edit_auth` と `wiki_user`、一覧のフィルタとページネーション、
数値だけのページ名、認可前に CAS 情報を漏らさないこと、Markdown ページの本文保全、
例外パスでのグローバル復元）。

## 8. トラブルシューティング

| 症状 | 確認事項 |
|------|---------|
| 常に 401 | キー作成済みか（`--list`）、`Authorization: Bearer` の綴り、CGI/FastCGI 環境の Authorization 引き継ぎ（[setup.md](../rest-api-v2/docs/setup.md) 参照） |
| `/pages/...` が 404 | `mod_rewrite` / `AllowOverride All`。または `.../api/v1/index.php/pages/...` の PATH_INFO 形式を試す |
| 409 が続く | 書き込み前に GET し直し、返ってきた `sha1` / `new_sha1` を次の `base_sha1` に使う |
| 保存内容が送信内容と違う | 正常動作（`#author` 行・見出しアンカーの付与）。`new_sha1` を信用する |
| 500 `insecure_data_dir` | データディレクトリが DocRoot 内。`PKWK_REST_DATA` で外に移す（[2 節](#2-インストール)参照） |
| 413 が返る | 本文が上限（既定 1MB）超。`PKWK_REST_MAX_BODY_BYTES` で調整 |
| 403 `read_forbidden` | `$read_auth` の閲覧制限ページ。API キーでは閲覧制限を迂回できない（仕様） |
| 全ページで 403 `read_forbidden`、一覧・検索が空 | `$read_auth = 1` のサイト。キーを `--wiki-user <名前>` 付きで作り直す（[3.4 節](#34-read_auth--edit_auth-を有効にしているサイト)） |
| 全ページで 403 `edit_forbidden` | `$edit_auth = 1` のサイト。キーを `--wiki-user <名前>` 付きで作り直す（[3.4 節](#34-read_auth--edit_auth-を有効にしているサイト)） |
| `#md` ページの `&now;` 等が実値に化ける | `md.inc.php` が v0.4 未満で `md_page_write()` が無い。プラグインを更新する（[6.1 節](#61-markdown-プラグインmdincphpとの互換)） |
| 500 エラー | PHP の error_log、`data/` の書き込み権限、`PKWK_ROOT` の指し先 |

環境変数（`PKWK_ROOT` / `PKWK_REST_DATA` / `PKWK_API_KEYS` / `PKWK_PROTECTED_PAGES` /
`PKWK_MCP_ACTOR` / `PKWK_MCP_WIKI_USER` / `PKWK_REST_ALLOW_DOCROOT_DATA` /
`PKWK_REST_MAX_BODY_BYTES` / `PKWK_REST_MAX_JSON_BYTES`）の詳細は
[rest-api-v2/docs/setup.md](../rest-api-v2/docs/setup.md) を参照。

環境変数が PHP に届かない共有ホスティングでは、`rest-api-v2/config.local.php`
（`config.local.php.example` をコピー）でデータディレクトリを指定できます。
優先順位は 環境変数 > `config.local.php` > 既定値。

## 9. ディレクトリ構成

```
rest-api-v2/
├── bootstrap.php        PukiWiki 本体の正規初期化を再現してロード
├── api/v1/index.php     REST フロントコントローラ
├── lib/                 Auth / Identity / PageStore / SnapshotStore / Audit / Router / Response / LocalConfig
├── mcp/server.php       MCP stdio サーバー・方式 A（同一マシン・PHP 直結）
├── setup/index.php      ブラウザー初期設定・APIキー発行と失効
├── bin/make-key.php     API キー管理 CLI
├── data/                キー・スナップショット・監査ログ（Web 非公開）
├── config.local.php.example  環境変数が使えないホスティング向けの設定例
├── .user.ini            display_errors=Off（PHP 警告が JSON に混入するのを防ぐ）
├── docs/                setup.md / api-reference.md
└── test/                ユニット＋統合テスト

pukiwiki-mcp/            MCP stdio ブリッジ・方式 B（リモート・Node.js・REST API 経由）
├── server.mjs           エントリポイント（依存パッケージなし）
├── lib/                 rest-client / tools
└── test/                モック REST サーバーに対する自動テスト
```

旧 v0.1 実装（`rest-api/`・SQLite 台帳＋下書き承認方式）は、検証で PukiWiki 統合層に
致命的な問題が複数見つかったため、ファイルのみ方式の v2 として再実装し、
v0.3 でリポジトリから削除しました。v0.1 のコードと問題一覧（DEPRECATED.md）は
[タグ v0.2 のツリー](https://github.com/m0370/pukiwiki154_RESTAPI/tree/v0.2/rest-api)で参照できます。

## 10. ライセンス

本拡張は **GPL v2 or (at your option) any later version** で配布します
（PukiWiki 1.5.4 本体のライセンスに準拠）。

PukiWiki 本体: Copyright © 2001-2022 PukiWiki Development Team — GPL v2+
本文はリポジトリ直下の [LICENSE](../LICENSE) を参照してください。
