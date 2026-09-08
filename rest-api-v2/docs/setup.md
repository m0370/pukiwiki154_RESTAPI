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
| PHP | 8.1 以上（DB 拡張は不要。`zlib` 拡張は推奨 — 無い環境ではスナップショットが非圧縮 `.txt` で保存される） |
| Web サーバー | Apache（`mod_rewrite`, `AllowOverride All`）推奨 |
| PukiWiki | 1.5.4（UTF-8 版で確認） |
| ファイルシステム | **ローカル FS 必須**。iCloud/Dropbox/NFS 等の同期フォルダでは flock/rename の保証が失われるため運用不可 |

## インストール

```bash
# 1. 配置
cp -r rest-api-v2 /var/www/pukiwiki/

# 2. データディレクトリを DocRoot の「外」に作る（標準・推奨）
#    キー・監査ログ・スナップショット（編集したページの全文）が入る。
#    DocRoot 内に置くと API は既定で 500 insecure_data_dir を返して起動を拒否する。
mkdir -p /var/lib/pukiwiki-rest/data
chown -R www-data:www-data /var/lib/pukiwiki-rest/data
chmod 750 /var/lib/pukiwiki-rest/data
rm -rf /var/www/pukiwiki/rest-api-v2/data   # 同梱の空 data/ は使わない

# 3. その場所を指す（環境変数が届かないホスティングでは config.local.php）
#    Apache: SetEnv PKWK_REST_DATA /var/lib/pukiwiki-rest/data
#    php-fpm: env[PKWK_REST_DATA] = /var/lib/pukiwiki-rest/data
export PKWK_REST_DATA=/var/lib/pukiwiki-rest/data

# 4. API キーを発行（生キーは一度だけ表示される）
#    $read_auth / $edit_auth を使うサイトでは --wiki-user を付ける（後述）
php /var/www/pukiwiki/rest-api-v2/bin/make-key.php --label my-editor --scope write
php /var/www/pukiwiki/rest-api-v2/bin/make-key.php --label ai-reader --scope read

# 5. 疎通確認（401 が返れば認証が効いている）
curl -i https://example.com/rest-api-v2/api/v1/pages/FrontPage
# → 401 Unauthorized

curl -H "Authorization: Bearer pkw2_..." \
     https://example.com/rest-api-v2/api/v1/pages/FrontPage
# → 200 + JSON
```

### FTP でアップロードする場合

`cp -r` が使えない環境では、**隠しファイルが落ちること**が最大の落とし穴です。
機能上必要なドットファイルが 4 つあります:

```
rest-api-v2/.htaccess        api/ 以外を 403 にする
rest-api-v2/api/.htaccess    URL 書き換えと Authorization ヘッダの引き継ぎ
rest-api-v2/data/.htaccess   data/ の deny
rest-api-v2/.user.ini        display_errors=Off
```

FTP クライアントの「隠しファイルを表示」を有効にしてから転送してください。
落ちても API は動いてしまうため、欠落に気づけません。転送後に必ず:

```bash
curl -i https://example.com/rest-api-v2/config.local.php   # → 403（200 なら .htaccess が欠落）
curl -i https://example.com/rest-api-v2/api/v1/pages/FrontPage  # → 401（404 なら書き換えが無効）
```

書き換えが効かない場合は PATH_INFO 形式
（`.../api/v1/index.php/pages/FrontPage`）でも動作します。

空ディレクトリ（`data/snapshots` `data/audit` `data/locks`）は作成不要です。
初回アクセス時に API が自動で作成します。`test/` は本番に置かないでください。

### アップデートの順序

`lib/Identity.php` は v2.1 で追加した必須ファイルで、`lib/PageStore.php` が
冒頭で `require_once` します。**必ず `lib/Identity.php` → `lib/PageStore.php` →
`api/v1/index.php` → `bin/make-key.php` → `mcp/server.php` の順**で置いてください。
順序を誤ると、その間 API 全体が 500 になります。

既存の API キーはそのまま使えます（`keys.php` の形式と `lib/Auth.php` は無変更）。

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
環境変数 `PKWK_REST_DATA` で指すようにしてください（**DocRoot 外が標準・推奨**）。

なお、データディレクトリが DocRoot 内にある場合、API は既定で 500
（`insecure_data_dir`）を返して起動を拒否します。nginx・`php -S`・`AllowOverride None`
など `.htaccess` が効かない環境で keys.php・監査ログ・スナップショットが配信されるのを
防ぐためです。上記の 403 確認を済ませた上で DocRoot 内のまま使う場合のみ、環境変数
`PKWK_REST_ALLOW_DOCROOT_DATA=1` を設定してください。

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

`read` スコープにも PukiWiki 本体の閲覧制限が適用されます: `:` 始まりのシステムページは
取得・スナップショットとも 403、`$read_auth` の閲覧制限ページは 403 かつ検索結果・
ページ一覧から除外（本体 `lib/html.php` の `is_page_readable()` と同じ扱い）。

`$read_auth` を**全ページ**に掛けているサイトでは、read キーにも `--wiki-user` が要ります。
付けないキーは fail-closed のまま、取得が全ページ 403・一覧と検索が空になります:

```bash
php rest-api-v2/bin/make-key.php --label ai-reader --scope read --wiki-user tgoto
```

## 書き込みの安全装置

write キーでも以下は常に強制されます:

1. **楽観ロック（CAS）**: `base_sha1`（読んだ時点の sha1）が現在と一致しないと 409。
   古い版に基づく上書き事故を防ぐ
2. **凍結ページ拒否**: `#freeze` されたページへの書き込みは 403。
   **凍結＝「API 編集不可マーカー」**として使える（重要ページは凍結しておく）
3. **保護ページ**: `FrontPage`・`MenuBar` は 403（環境変数 `PKWK_PROTECTED_PAGES` で変更可）
4. **システムページ**: `:config` など `:` 始まりは 403
5. **編集認可（`$edit_auth`）**: `$edit_auth_pages` に該当するページへの書き込みは、
   キーに `wiki_user` が設定されていなければ 403（fail-closed）。
   `wiki_user` を設定したキーは、そのユーザーとして PukiWiki 本体の
   `is_page_writable()` の判定を受ける（→ [$edit_auth を有効にしたサイト](#edit_auth-を有効にしたサイト)）
6. **空本文拒否**: 空の content は 400（PukiWiki は空本文をページ削除として扱うため）
7. **本文サイズ上限**: 既定 1MB 超は 413（`PKWK_REST_MAX_BODY_BYTES` で変更可）
8. **全版スナップショット**: 書き込み前後の版を `data/snapshots/` に gzip 保存（削除しない）
9. **監査ログ**: 全書き込み・拒否を `data/audit/audit-YYYYMM.jsonl` に追記
10. **#author 記録**: 保存されたページの `#author` 行にキーの label が入る
    （PukiWiki の差分画面だけで「誰が API で書いたか」を追跡できる）

## MCP サーバー（Claude Desktop / Claude Code）

ここで説明するのは PukiWiki と**同一マシン**で動かす PHP 直結版（方式 A）です。
リモートから REST API 経由で使う Node ブリッジ版（方式 B・API キー認証あり）は
[pukiwiki-mcp/README.md](../../pukiwiki-mcp/README.md) を参照してください。

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
| `PKWK_REST_DATA` | `rest-api-v2/data` | データディレクトリ（**DocRoot 外を推奨**。`bin/make-key.php` も参照する） |
| `PKWK_API_KEYS` | `{data}/keys.php` | キー設定ファイルのパス |
| `PKWK_PROTECTED_PAGES` | `["FrontPage","MenuBar"]` | 保護ページ（JSON 配列） |
| `PKWK_MCP_ACTOR` | `mcp-client` | MCP 操作の監査ログ・#author 名 |
| `PKWK_MCP_WIKI_USER` | （未設定） | MCP 方式 A が名乗る PukiWiki ユーザー名（`$edit_auth` 有効サイトで必須） |
| `PKWK_REST_ALLOW_DOCROOT_DATA` | （未設定） | `1` で DocRoot 内データディレクトリを明示許可（deny 動作確認済みの場合のみ） |
| `PKWK_REST_MAX_BODY_BYTES` | `1048576`（1MB） | ページ本文の上限。超過は 413（REST・MCP 両方に効く） |
| `PKWK_REST_MAX_JSON_BYTES` | `max(8MB, 本文上限×8+64KB)` | raw JSON リクエストの上限。超過は 413 |

環境変数が PHP に届かないホスティング（共有サーバー等で `SetEnv` も
php-fpm のプール設定も触れない場合）では、`rest-api-v2/config.local.php` を置けます。
`config.local.php.example` をコピーして使ってください。優先順位は
**環境変数 > `config.local.php` > 既定値**で、`bootstrap.php`（Web）と
`bin/make-key.php`（CLI）が同じ解決を行うため keys.php の場所がずれません。

## $edit_auth を有効にしたサイト

`pukiwiki.ini.php` で `$edit_auth = 1` にしているサイトでは、**そのままだと
API の書き込みが全ページ 403 `edit_forbidden` になります**。API にはログイン
セッションが無く、`is_page_writable()` が未ログインユーザーとして判定するためです。

とくに `$edit_auth_pages` に `'##' => 'someone'` のような**空の正規表現**を
使っている場合、それは全ページにマッチするので API は完全に読み取り専用になります。

これを解くには、API キーに「どの PukiWiki ユーザーとして書くか」を持たせます:

```bash
php rest-api-v2/bin/make-key.php --label my-editor --scope write --wiki-user tgoto
```

`--wiki-user` に指定するのは `$auth_users` に実在するユーザー名です。書き込みの
間だけ `$auth_user` / `$auth_user_groups` をそのユーザーに設定し、PukiWiki 本体の
`$edit_auth` 判定を**正規に**通します。認可の迂回ではありません:

- そのユーザーが編集できないページは、API からも 403 のまま
- `#freeze`・保護ページ・`:` システムページの拒否はそのまま効く
- `#author` 行には wiki ユーザー名とキーのラベルの両方が残るため、
  「どのキーが誰として書いたか」を差分画面から追跡できる

`--wiki-user` を付けないキーは従来どおり fail-closed です。read 系（取得・一覧・検索）も
同じ identity で `$read_auth` を評価するため、`$read_auth` を使うサイトでは read キーにも
指定してください（`$read_auth = 0` のサイトでは read キーに不要）。

> ⚠ **`wiki_user` の実在は検証していません。** PukiWiki 本体の
> `get_groups_from_username()`（`lib/auth.php`）はユーザー名自身を暗黙のグループとして
> 返すため、`$auth_users` から削除済みのユーザー名でも認可を通ります。つまり
> **Wiki 側のアカウントを消しても、そのユーザーを名乗る API キーは失効しません。**
> パスワード変更・アカウント削除と API キーの失効は別の操作です。キーの停止は必ず
> `make-key.php --revoke <ラベル>` で行ってください。
> （キー発行時に `$auth_users` との突き合わせを行う改善は検討中。外部認証
> （LDAP / SAML）への委任と、ローカルユーザーの検証を区別する必要があるため未実装です。）

MCP 方式 A（PHP 直結）では環境変数 `PKWK_MCP_WIKI_USER` が同じ役割を持ちます。

## テスト

```bash
# ユニットテスト（PukiWiki 本体不要）
php rest-api-v2/test/unit_test.php

# 統合テスト（実 PukiWiki の使い捨てコピーに対して実行）
cp -r /var/www/pukiwiki /tmp/pkwk-test
PKWK_ROOT=/tmp/pkwk-test php rest-api-v2/test/integration_test.php

# サイト統合テスト（改造済みサイトに載せたときの噛み合わせ）
PKWK_ROOT=/tmp/pkwk-test php rest-api-v2/test/site_integration_test.php
```

`integration_test.php` は「素の 1.5.4 に対する REST API の正しさ」を検証します
（`$edit_auth = 0` を前提とするセクションがあるため、`$edit_auth` を有効にしたサイトの
コピーに対しては FAIL します）。

`site_integration_test.php` はその逆で、**改造済みサイト固有の設定・プラグインと
噛み合うか**を検証します。`$edit_auth` と `wiki_user` の関係、Markdown プラグイン
（`md.inc.php`）を入れたサイトで本文が壊れないか、例外パスでグローバルが復元されるか、
などを見ます。該当する改造が無いサイトでは、そのセクションは自動でスキップされます。

## トラブルシューティング

| 症状 | 確認事項 |
|------|---------|
| 常に 401 | キー作成済みか（`--list`）、`Authorization: Bearer` の綴り、CGI 環境の Authorization 引き継ぎ。CLI と Web で `PKWK_REST_DATA` がずれていないか（`--list` が表示する keys.php のパスを確認） |
| 全ページで 403 `edit_forbidden` | `$edit_auth = 1` のサイト。キーを `--wiki-user <名前>` 付きで作り直す（→ [$edit_auth を有効にしたサイト](#edit_auth-を有効にしたサイト)） |
| `#md` ページの `&now;` 等が実値に化ける | `md.inc.php` が v0.4 未満で `md_page_write()` が無い。プラグインを更新する |
| `/pages/...` が 404 | `mod_rewrite`／`AllowOverride All`。または PATH_INFO 形式 URL を試す |
| 500 エラー | PHP の error_log。`data/` の書き込み権限。`PKWK_ROOT` の指し先 |
| 409 が続く | 書き込み前に必ずページを取得し直し、返ってきた `sha1`／`new_sha1` を次の `base_sha1` に使う |
| 保存内容が送信内容と違う | 正常動作。PukiWiki が `#author` 行・見出しアンカーを付与する。`new_sha1` を信用する |
