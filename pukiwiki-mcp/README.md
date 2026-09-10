# PukiWiki REST API — Claude Desktop / MCP

PukiWikiの閲覧・検索・編集・バックアップ取得・添付管理を、Claudeから利用するためのMCPブリッジです。**Claude DesktopではMCPBを追加し、拡張の設定欄へAPI URLとAPIキーを入力するだけで設定できます。**

## Claude Desktopで使う（推奨）

サーバーへまだ設置していない場合は、先に[FTPとブラウザーによる導入手順](../README.md#install)を実施してください。

1. ブラウザーで `https://あなたのサイト/rest-api-v2/setup/index.php` を開き、PukiWikiの凍結・解除用の管理者パスワードでログインします。
2. 「接続キーを発行」でキー名・権限・Wikiユーザーを指定し、APIキーを発行します。添付のアップロード・削除を使う場合は、その許可も付けます。
3. [pukiwiki-mcp.mcpbをダウンロード](https://github.com/m0370/pukiwiki154_RESTAPI/releases/download/v2.2.0/pukiwiki-mcp.mcpb)します。設置用ZIPにも同梱されています。
4. Claude Desktopの **設定 → 拡張機能（Extensions）→ 詳細設定（Advanced settings）→ Install Extension** でファイルを選びます。
5. 「PukiWiki REST API」拡張の設定欄に次の値を入力して保存します。

| 設定欄 | 入力する値 |
|---|---|
| API URL | サーバーの設定画面に表示されたURL。例: `https://example.com/rest-api-v2/api/v1/index.php` |
| APIキー | 発行時に一度だけ表示される `pkw2_…` の全文 |
| 添付用フォルダ | PC上で添付の保存・アップロードに使うフォルダ |

**APIキーを記入する場所は、拡張の「APIキー」欄です。** `.env`・JSONファイルの作成や、Node.jsの手動インストールは不要です。デスクトップ拡張に対応したClaude DesktopがNode実行環境を提供します。[Claude公式の拡張導入ガイド](https://support.claude.com/en/articles/10949351-getting-started-with-local-mcp-servers-on-claude-desktop)も参照してください。

Claudeに「Wikiの接続状態を確認して」「FrontPageを読んで」と依頼して動作を確認します。キーを会話に貼り付ける必要はありません。MCPBの対象はmacOS / Windowsですが、実機インストールは未検証です。

同じWikiを複数のPCから使う場合、管理者は `home-m2`・`work-m4` のように異なる名前でキーを発行できます。各PCの拡張に別々のキーを入力すると、片方だけを失効できます。発行・失効・紛失時の再発行は[ブラウザーでのキー管理](../README.md#multiple-keys)を参照してください。

## 利用できる14ツール

| ツール | 機能 | 必要な権限 |
|---|---|---|
| `wiki_get_capabilities` | 接続・対応機能・キーの権限を確認 | read |
| `wiki_read_page` | ページ本文・更新確認用sha1・凍結状態を取得 | read |
| `wiki_list_pages` | ページ一覧 | read |
| `wiki_search` | フレーズ・AND・ORによる全文検索 | read |
| `wiki_write_page` | ページ作成・更新。`notimestamp` に対応 | write |
| `wiki_page_revisions` | API書き込みスナップショットの一覧 | read |
| `wiki_read_revision` | APIスナップショットの本文取得 | read |
| `wiki_standard_backups` | PukiWiki標準バックアップの一覧 | read |
| `wiki_read_standard_backup` | 標準バックアップの本文取得 | read |
| `wiki_list_attachments` | ページの添付一覧 | read |
| `wiki_read_attachment` | 添付をMCPコンテンツとして取得 | read |
| `wiki_download_attachment` | 添付を指定フォルダへ保存 | read |
| `wiki_upload_attachment` | 指定フォルダから添付を新規アップロード | write ＋ 添付変更の許可 |
| `wiki_delete_attachment` | 添付を削除保管 | write ＋ 添付変更の許可 |

`write` は `read` の操作も含みます。どのキーでもPukiWiki本体の閲覧・編集制限を受けます。ページ更新には現在の `base_sha1` が必要で、凍結・保護ページは更新できません。`wiki_search` の `mode` は `PHRASE`（既定）・`AND`・`OR`、`wiki_write_page` の `notimestamp` は真偽値です。

### 添付用フォルダについて

アップロード元とダウンロード先は、拡張の設定で選んだフォルダ内に限定します。アップロード時の `file` はそのフォルダからの相対パスです。会話に添付したファイルを直接アップロードする機能ではありません。

添付は最大5 MiBで、アップロードはPukiWiki側の上限にも従います。既存のローカルファイルやWikiの添付は上書きしません。削除時はSHA-256を確認してPukiWikiの添付履歴へ移動します。画像・テキスト・PDFなどの表示や解釈の可否は、クライアントにも依存します。

## 手動設定（Claude Code・その他のMCPクライアント向け）

**MCPBでClaude Desktopに追加した場合、この節の作業は不要です。** 手動起動ではNode.js 18以上が必要です。依存パッケージのインストールは不要です。

サーバーへ[REST API](../README.md#install)を設置し、[ブラウザーで発行したキー](../README.md#issue-key)を使います。キーの発行にCLIを使うこともできますが、必須ではありません。

<a id="claude-code-user"></a>
### Claude Code：ユーザースコープで登録する（macOS / Windows / Linux）

Claude Codeには **`--scope user` を付けて登録することを推奨します**。同じPC・同じOSユーザーなら、どの作業フォルダで起動しても利用できます。省略すると現在のプロジェクトだけで使うlocalスコープになります。[Claude Code公式のスコープ説明](https://code.claude.com/docs/en/mcp#scope-hierarchy-and-precedence)も参照してください。

Claude CodeとNode.jsをインストールしたうえで、ダウンロードした **`pukiwiki-mcp` フォルダ（`server.mjs` がある場所）でターミナルを開き**、以下を実行します。API URLとキーは実行後の入力欄へ貼り付けます。サーバーの絶対パスは自動取得するため、`/path/to/...` を書き換える作業はありません。登録後もこのフォルダを移動・削除しないでください。

macOS / Linux（Bash。macOSの標準zshでは先に `bash` を実行）：

```bash
(
  set -e
  test -f server.mjs || { echo 'pukiwiki-mcpフォルダで実行してください'; exit 1; }
  pkw_node=$(command -v node)
  pkw_server="$PWD/server.mjs"
  read -r -p 'API URL: ' pkw_url
  read -r -s -p 'APIキー（入力は表示されません）: ' pkw_key
  echo
  test -n "$pkw_url" && test -n "$pkw_key" || exit 1
  claude mcp add pukiwiki --scope user --transport stdio \
    --env "PUKIWIKI_API_URL=$pkw_url" --env "PUKIWIKI_API_KEY=$pkw_key" \
    -- "$pkw_node" "$pkw_server"
)
```

Windows（PowerShell）：

```powershell
& {
  $ErrorActionPreference = 'Stop'
  if (-not (Test-Path -LiteralPath './server.mjs' -PathType Leaf)) {
    throw 'pukiwiki-mcpフォルダで実行してください'
  }
  $pkwNode = (Get-Command node -CommandType Application).Source
  $pkwServer = (Resolve-Path -LiteralPath './server.mjs').Path
  $pkwUrl = Read-Host 'API URL'
  $pkwSecret = Read-Host 'APIキー' -AsSecureString
  $pkwKey = ([System.Net.NetworkCredential]::new('', $pkwSecret)).Password
  if (-not $pkwUrl -or -not $pkwKey) { throw 'API URLとキーは必須です' }
  claude mcp add pukiwiki --scope user --transport stdio `
    --env "PUKIWIKI_API_URL=$pkwUrl" --env "PUKIWIKI_API_KEY=$pkwKey" `
    -- "$pkwNode" "$pkwServer"
}
```

この手順ではキーを `~/.claude.json`（Windowsではユーザープロファイル内の `.claude.json`）に平文で保存します。`.env` は不要です。これはClaude Codeの設定であり、Claude Desktopの通常のチャットで使うMCPB拡張とは別です。macOS専用の `run.sh` やキーチェーンは必須ではありません。キーチェーン等によるキー保管方法と、user/local/projectという利用範囲は別の設定です。

添付を保存・アップロードする場合は、登録コマンドの `--` より前に `--env "PUKIWIKI_FILES_DIR=実在する添付用フォルダの絶対パス"` を追加してください。キーを再発行した場合は、PC側の登録キーも更新します。

#### 以前の設定から移す場合

同名の登録がある場合は、正常に接続できる設定のAPI URL・キー・コマンド・引数・添付用フォルダを引き継いでください。localスコープや `.mcp.json` の同名定義が残ると、userスコープより優先されます。別のWikiへの接続設定は削除しません。

Claude Codeに次のように依頼することもできます。APIキーを会話本文へ貼り付ける必要はありません。

> 現在の正常なpukiwiki MCP設定を、接続情報を変えずにuserスコープへ移してください。競合する同名のlocal/project設定だけを整理し、他のMCP設定は保持してください。キーを出力せず、別の作業フォルダからも接続できることを確認してください。

設定後はClaude Codeのセッションを起動し直し、`/mcp` で接続を確認します。OSの再起動は不要です。別のPCには、そのPCで改めて登録します。**ユーザースコープへの登録が、別のPCへ自動反映されるわけではありません。**

上記の登録・接続はmacOSで検証しています。Windows / Linuxの実機検証とAndroid対応は現時点では行っていません。

### その他のMCPクライアント：設定ファイルから起動する

クライアントの対応形式に合わせて次の項目を追加します。Claude Desktopを手動設定する場合は `claude_desktop_config.json` を使います。以下は構造を示す例です。パス・URL・キーは実際の値への置き換えが必要で、そのままでは接続できません。Claude Codeには上のユーザースコープ手順を使ってください。

```json
{
  "mcpServers": {
    "pukiwiki": {
      "command": "node",
      "args": ["/path/to/pukiwiki-mcp/server.mjs"],
      "env": {
        "PUKIWIKI_API_URL": "https://example.com/rest-api-v2/api/v1/index.php",
        "PUKIWIKI_API_KEY": "pkw2_ここを発行したキーに置き換える",
        "PUKIWIKI_FILES_DIR": "/path/to/attachment-folder"
      }
    }
  }
}
```

これはPC側でNodeプロセスを起動する設定です。REST API URLをリモートMCPサーバーのURLとして登録する形式ではありません。実際のキーを含む設定ファイルはGitへコミットしないでください。

### 環境変数

| 変数 | 必須 | 内容 |
|---|---|---|
| `PUKIWIKI_API_URL` | はい | REST APIのベースURL。`.../api/v1/index.php` 形式ならURL書き換え不要 |
| `PUKIWIKI_API_KEY` | はい | ブラウザーまたはCLIで発行したAPIキー |
| `PUKIWIKI_FILES_DIR` | ローカル添付操作時 | 添付のアップロード元・ダウンロード先フォルダ |
| `PUKIWIKI_TIMEOUT_MS` | いいえ | HTTPタイムアウト。既定30000ミリ秒 |

ブリッジは環境変数を読みますが、**`.env` は自動読み込みしません**。手動設定で`.env`を利用する場合は、起動プロセスへ環境変数を渡す仕組みを別途設定してください。

## PHP直結版との違い

このNodeブリッジはPC上で動き、HTTPSとAPIキーでリモートのWikiに接続します。MCPBにも同じ実装を同梱しています。

`rest-api-v2/mcp/server.php` はWikiと同じマシンで動かす管理者向けの互換版です。APIキーを使わず、従来の4ツールに検索方式・notimestampを加えた範囲が対象です。添付管理などの新機能一式はNode版／MCPBを使ってください。[PHP直結版の技術説明](../docs/advanced-guide.md#5-mcp-サーバーclaude-連携)を参照してください。

## 開発者向けテスト

ソースをiCloud等ではない使い捨て領域へコピーし、`node --test pukiwiki-mcp/test/*.test.mjs` を実行します。モックRESTサーバーとstdio通信で、ツール・認証エラー・編集競合・添付フォルダ制限などを検証します。

## ライセンス

[GPL v2 or later](LICENSE)。PukiWiki本体と同じライセンスです。
