# PukiWiki REST API / MCP

PukiWiki 1.5.4を、Claude Desktopから閲覧・検索・編集するための拡張です。**FTPで設置し、ブラウザーでAPIキーを発行して、Claude Desktopの設定欄へ貼り付ける**手順で利用できます。PukiWiki本体の改変は不要です。

**公開版: v2.2.0** ／ PukiWiki 1.5.4 UTF-8版・PHP 8.1以上 ／ GPL-2.0-or-later

**[設置用ZIPをダウンロード](https://github.com/m0370/pukiwiki154_RESTAPI/releases/download/v2.2.0/PukiWiki-REST-API-2.2.0.zip)** · [リリース一覧](https://github.com/m0370/pukiwiki154_RESTAPI/releases)

通常の導入にSSH・ターミナル操作・Node.jsの手動インストール・JSON編集は必要ありません。Cloudflareアカウントや `akismet2.inc.php` も不要です。

## できること

| 機能 | 内容 |
|---|---|
| ページの閲覧・編集 | 一覧・本文取得、新規作成・更新。閲覧制限や凍結を尊重します |
| AND / OR検索 | 複数語で検索。従来のフレーズ検索にも対応します |
| 添付ファイル | 一覧・取得・PCへの保存、新規アップロード、削除保管。変更操作は別途許可したキーだけが使えます |
| バックアップ | PukiWiki標準バックアップと、APIでの書き込み時に保存した履歴の読み取り |
| 更新日時の維持 | `notimestamp` を指定して、既存ページの更新日時を変えずに編集できます |
| キー管理 | ブラウザーで発行・一覧確認・失効。1人の管理者で複数キーを管理できます |

## 導入の流れ

[1. FTPで設置](#install) → [2. ブラウザーで初期設定](#browser-setup) → [3. APIキーを発行](#issue-key) → [4. Claude Desktopへ入力](#desktop)

サーバーへの設置はWikiごとに1回だけ行います。自宅・職場など複数のPCから使う場合は、各PC用のキーを発行してClaude Desktopへ設定します。

<a id="install"></a>
## 1. FTPで設置する

必要なのは、稼働中のPukiWiki 1.5.4（UTF-8版）、PHP 8.1以上、HTTPS、FTPクライアントです。サーバーには**Web公開領域の外で、PHPが読み書きできる保存先**が必要です。APIのデータはiCloud・Dropbox・NFSなどの同期・ネットワークファイルシステム上では運用しないでください。

[設置用ZIP](https://github.com/m0370/pukiwiki154_RESTAPI/releases/download/v2.2.0/PukiWiki-REST-API-2.2.0.zip)を展開します。GitHubの「Source code (zip)」ではなく、**`PukiWiki-REST-API-2.2.0.zip`** を選んでください。同梱の `はじめに.html` も手順書として使えます。

ZIP内の **`rest-api-v2` フォルダ**を、PukiWikiの `index.php` や `pukiwiki.ini.php` と同じ階層へFTPでアップロードします。

```text
PukiWikiの設置先/
├── index.php
├── pukiwiki.ini.php
├── wiki/
├── plugin/
└── rest-api-v2/         ← このフォルダを追加
    ├── setup/
    ├── api/
    ├── lib/
    ├── .htaccess
    └── .user.ini
```

`.htaccess` や `.user.ini` などの隠しファイルも転送してください。FTPクライアントで隠しファイルを表示し、転送先にも存在することを確認します。`pukiwiki-mcp.mcpb` はPC側で使うファイルなので、サーバーへの転送は不要です。

ApacheのURL書き換えが使えなくても、設定画面に表示される `.../api/v1/index.php` 形式のAPI URLを使えます。PHPから非公開の保存先へ書き込めないサーバーでは、ホスティング側の設定が必要になります。

<a id="browser-setup"></a>
## 2. ブラウザーで初期設定する

ブラウザーで、次のURLを開きます。

```text
https://あなたのサイト/rest-api-v2/setup/index.php
```

PukiWikiを `/wiki/` に設置している場合は、`https://あなたのサイト/wiki/rest-api-v2/setup/index.php` です。

**PukiWikiの凍結・解除に使う管理者パスワード**でログインします。一般の編集ユーザーのパスワードやAPIキーではありません。

初回だけ非公開の保存先を設定します。画面の候補を確認して設定してください。候補に書き込めない場合は、FTPで用意した公開領域外のフォルダの絶対パスを指定します。設定ファイルは自動作成されるため、手動編集は不要です。既に設定済みなら、この操作を繰り返す必要はありません。

<a id="issue-key"></a>
## 3. APIキーをブラウザーで発行する

同じ設定画面の「接続キーを発行」で、次を指定します。

| 項目 | 指定する内容 |
|---|---|
| キー名 | `home-m2` など、用途が分かる名前。英数字・ドット・ハイフン・下線が使えます |
| 許可する操作 | 読むだけなら閲覧権限（read）、ページを編集するなら編集権限（write） |
| Wikiユーザー | そのキーで利用する、既存のPukiWikiユーザー。閲覧・編集認証があるサイトでは選択が必要です |
| 添付の変更 | アップロード・削除も使う場合だけチェックします。編集権限も必要です |

発行ボタンを押すと、**`pkw2_` で始まるAPIキーが一度だけ表示されます**。全文をコピーし、次のClaude Desktopの設定欄へ貼り付けてください。発行済みキーの一覧から値を再表示することはできません。

サーバーのキー管理ファイルにはハッシュを保存します。キーを紛失した場合は、同じ画面で失効させて新しいキーを発行します。閲覧・編集認証のないサイトでだけ、Wikiユーザーを「匿名」にできます。

<a id="desktop"></a>
## 4. Claude Desktopの設定欄へ貼り付ける

デスクトップ拡張に対応したClaude Desktop（macOS / Windows）を使います。

1. ZIP同梱の **`pukiwiki-mcp.mcpb`** を手元のPCに保存します。[MCPBだけのダウンロード](https://github.com/m0370/pukiwiki154_RESTAPI/releases/download/v2.2.0/pukiwiki-mcp.mcpb)もできます。
2. Claude Desktopの **設定 → 拡張機能（Extensions）→ 詳細設定（Advanced settings）→ Install Extension** から、そのファイルを選んでインストールします。
3. 「PukiWiki REST API」拡張の設定画面に、次の3項目を入力して保存します。

| 拡張の設定欄 | 入れる内容 |
|---|---|
| API URL | ブラウザーのAPI設定画面に表示されたURL。例: `https://example.com/rest-api-v2/api/v1/index.php` |
| APIキー | 先ほど発行した `pkw2_…` の全文 |
| 添付用フォルダ | PC上で、添付の保存・アップロードに使うフォルダ |

**APIキーの入力先は、この拡張の「APIキー」欄です。`.env` や `claude_desktop_config.json` に書く必要はありません。** Nodeの実行環境もClaude Desktopが提供します。拡張の追加方法は[Claude公式ガイド](https://support.claude.com/en/articles/10949351-getting-started-with-local-mcp-servers-on-claude-desktop)も参照してください。

設定後、Claudeに「Wikiの接続状態を確認して」「FrontPageを読んで」と依頼してください。APIキー自体を会話本文へ貼り付ける必要はありません。

<a id="multiple-keys"></a>
## 複数のPCで使う・キーを失効する

同じ管理者・同じWikiユーザーで、名前の異なるキーを複数発行できます。

| キー名の例 | 設定するPC |
|---|---|
| `home-m2` | 自宅のM2 Mac |
| `work-m4` | 職場のM4 Mac |

各PCのClaude Desktopには、そのPC用のキーを入力します。API URLは共通です。キー名は管理用の名前で、特定のハードウェアに自動で固定されるものではありません。

使わなくなったキーは、ブラウザー設定画面の「発行済みキー」で**失効**させます。他のキーには影響しません。再発行したら、該当PCの拡張設定の「APIキー」も新しい値へ更新してください。

Wikiユーザーの削除だけでは、発行済みAPIキーは自動失効しません。利用を停止するときはキーも失効させてください。IP制限・有効期限の指定は[CLIの追加設定](docs/advanced-guide.md#31-キーを発行する)で行います。

<a id="update"></a>
## 既存のAPIを更新する

FTPで現在のAPIフォルダをバックアップし、新版を別名で転送します。既存の **`config.local.php` を新版へ引き継ぎ、公開領域外のキー・監査ログ・履歴を保持**したうえでフォルダを切り替えます。旧版のバックアップは公開領域外へ保管してください。接続に失敗したら旧版へ戻します。

既存のキーはそのまま使えます。ただし添付のアップロード・削除権限は自動付与されません。必要なら設定画面で許可付きキーを発行してください。PC側のMCPBも更新します。

旧構成でAPIフォルダ内にデータを置いている場合や、設定画面を独自にカスタマイズしている場合は、上書き前に保存先と差分を確認してください。初期設定画面は既存データを自動移設しません。[更新時の仕様と制限](docs/upgrade-2.2.md)も参照してください。

## よくある質問

| 困ったこと | 確認するところ |
|---|---|
| 設定画面にログインできない | HTTPSで開いているか、凍結・解除用の管理者パスワードかを確認します |
| 非公開の保存先を設定できない | Web公開領域外か、PHPから書き込めるかを確認します。必要ならホスティング管理者へ確認してください |
| キーをどこに書けばよいか分からない | Claude Desktopの「PukiWiki REST API」拡張の**APIキー欄**へ貼り付けます |
| 一覧が空、閲覧・編集が403になる | キー発行時に適切なWikiユーザーを選んだか、そのユーザーの権限やページの凍結を確認します |
| 接続時に401になる | キーの全文が正しく入力されているか、失効していないかを確認します |
| API URLで404になる | ブラウザー設定画面に表示された `.../api/v1/index.php` を拡張のAPI URLへ入力します |
| 添付をアップロードできない | 編集権限に加えて添付変更の許可が必要です。ファイルは設定した添付用フォルダ内に置きます |
| MCPBを追加できない | Claude Desktopの対応版か、組織の拡張インストール制限がないかを確認します |

手動でNodeブリッジを起動する場合、`.env` は自動読み込みされません。環境変数を起動プロセスへ渡す方法は、使用するMCPクライアントに合わせて設定します。これはMCPBでの通常の導入には不要です。

## 動作上の制限

PukiWiki本体の閲覧・編集制限、凍結、APIの保護ページ設定が適用されます。ページ削除・凍結・リネームはWeb UIで行います。`notimestamp` は既存ページの更新日時を維持し、競合確認とAPIの履歴・監査記録は続けます。

添付は最大5 MiB（アップロードはPukiWiki側の上限にも従います）。既存ファイルの上書きはせず、削除は履歴へ保管します。削除済みページのバックアップ・添付履歴はAPIの対象外です。

PHP・Node・HTTPでの機能検査は実施済みです。Claude Desktopへの実インストールとWindows実機は未検証です。[検証内容](docs/upgrade-2.2.md)を参照してください。

## 開発者・サーバー管理者向け

- [手動設置・CLIによるキー管理・REST利用例](docs/advanced-guide.md)
- [サーバー設定と環境変数](rest-api-v2/docs/setup.md)
- [REST APIリファレンス](rest-api-v2/docs/api-reference.md)
- [MCPのツール一覧・Claude Codeなどへの手動設定](pukiwiki-mcp/README.md)
- [v2.2の仕様と検証手順](docs/upgrade-2.2.md)

PHP直結MCPは、Wikiと同じマシン上で動かす管理者向けの互換方式です。今回の追加機能一式はMCPB／Nodeブリッジを使ってください。

## ライセンス

[GNU General Public License v2 or later](LICENSE)。PukiWiki本体と同じライセンスです。
