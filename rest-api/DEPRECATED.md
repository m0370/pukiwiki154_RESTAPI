# ⚠ このディレクトリ（rest-api/ v0.1）は非推奨です

**後継: `rest-api-v2/` を使用してください。**

v0.1（SQLite 台帳＋下書き承認ワークフロー方式）は 2026-07-02 の検証で、
PukiWiki 統合層に以下の致命的問題が確認されました（未修正のまま凍結）:

1. **統合パスが起動時 Fatal** — bootstrap.php が `pukiwiki.ini.php` を単体 require するが、
   ini は `DATA_HOME` 定数（本体 index.php が定義）に依存。さらに `page_write()` が必要とする
   diff.php / backup.php / link.php 等を未ロード
2. **sha1 台帳の恒常的乖離** — `page_write()` は make_str_rules()＋add_author_info() で
   本文を変形するが、CommitEngine は変形前の sha1 を記録
3. **空本文 PUT でページ削除が素通し**（admin 専用ソフト削除の原則を迂回）
4. **rest-api/data/ が Web 公開される**（deny ルールが api/ 配下にしかない）
5. **階層ページ名（親/子）が実質アクセス不能**（%2F 必須 × Apache AllowEncodedSlashes Off）

ほか High/Medium 級の問題は、リポジトリの検証記録を参照。
テスト 728 件は全て page_write() モックによるスタンドアロン実行であり、
実 PukiWiki との統合は未検証でした（v2 では実機統合テストを必須化）。
