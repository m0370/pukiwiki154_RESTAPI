<?php
/**
 * REST API 共通ブートストラップ。
 *
 * このファイルはフロントコントローラとテストの両方から require される。
 * 以下のグローバル変数を設定する:
 *
 *   $REST_LEDGER      Ledger インスタンス（制御プレーン / 台帳）
 *   $REST_REVISIONS   RevisionStore インスタンス（版 blob）
 *   $REST_RECONCILER  Reconciler インスタンス（自己修復）
 *   $REST_ENGINE      CommitEngine インスタンス（共有コミットエンジン）
 *   $REST_WIKI_DIR    wiki/ ディレクトリの絶対パス
 *
 * ——————————————————————————————————————————————
 * PukiWiki との統合方法
 * ——————————————————————————————————————————————
 * rest-api/ を PukiWiki ルートの直下に置いた場合、
 * 親ディレクトリに pukiwiki.ini.php があれば自動検出して読み込む。
 * 見つからない場合はスタンドアローンモードで動作する（主にテスト用）。
 *
 * 環境変数 PKWK_ROOT を設定すると自動検出をオーバーライドできる:
 *   PKWK_ROOT=/var/www/pukiwiki php rest-api/test/phase1_test.php
 * @version v0.1
 */
declare(strict_types=1);

// -------------------------------------------------------------------------
// REST API 専用ディレクトリ（データプレーン外に置いてウェブ非公開にする）
// -------------------------------------------------------------------------
$REST_DIR       = __DIR__;
$REST_DB_DIR    = $REST_DIR . '/data/db';
$REST_BLOB_DIR  = $REST_DIR . '/data/revisions/_blobs';
$REST_DB_PATH   = $REST_DB_DIR . '/ledger.sqlite';
$REST_SCHEMA    = $REST_DIR . '/schema/init.sql';

foreach ([$REST_DB_DIR, $REST_BLOB_DIR] as $d) {
    if (!is_dir($d) && !mkdir($d, 0750, true) && !is_dir($d)) {
        throw new \RuntimeException("Cannot create directory: {$d}");
    }
}

// -------------------------------------------------------------------------
// PukiWiki 検出
// -------------------------------------------------------------------------
$pkwk_root = getenv('PKWK_ROOT') ?: realpath($REST_DIR . '/..');
$pkwk_ini  = $pkwk_root . '/pukiwiki.ini.php';

if (file_exists($pkwk_ini)) {
    // PukiWiki が利用可能: ini と必要な lib を読み込む
    require_once $pkwk_ini;
    require_once $pkwk_root . '/lib/func.php';
    require_once $pkwk_root . '/lib/file.php';
    // auth.php の is_freeze(), is_editable() などが必要な場合
    if (file_exists($pkwk_root . '/lib/auth.php')) {
        require_once $pkwk_root . '/lib/auth.php';
    }
    $REST_WIKI_DIR = defined('DATA_DIR') ? rtrim(DATA_DIR, '/') : $pkwk_root . '/wiki';
} else {
    // スタンドアローンモード（テスト・開発環境）
    $REST_WIKI_DIR = $REST_DIR . '/data/wiki';
    if (!is_dir($REST_WIKI_DIR)) {
        mkdir($REST_WIKI_DIR, 0755, true);
    }
}

// -------------------------------------------------------------------------
// REST API クラス読み込み
// -------------------------------------------------------------------------
require_once $REST_DIR . '/lib/AtomicWriter.php';
require_once $REST_DIR . '/lib/RevisionStore.php';
require_once $REST_DIR . '/lib/Ledger.php';
require_once $REST_DIR . '/lib/Reconciler.php';
require_once $REST_DIR . '/lib/CommitEngine.php';
require_once $REST_DIR . '/lib/AdminManager.php';
require_once $REST_DIR . '/lib/ApiException.php';
require_once $REST_DIR . '/lib/Response.php';

// -------------------------------------------------------------------------
// コンポーネント初期化
// -------------------------------------------------------------------------
$REST_LEDGER      = Ledger::open($REST_DB_PATH, $REST_SCHEMA);
$REST_REVISIONS   = new RevisionStore($REST_BLOB_DIR);
$REST_RECONCILER  = new Reconciler($REST_LEDGER, $REST_REVISIONS, $REST_WIKI_DIR);
$REST_ENGINE      = new CommitEngine($REST_LEDGER, $REST_REVISIONS, $REST_WIKI_DIR);
$REST_ADMIN       = new AdminManager($REST_LEDGER, $REST_REVISIONS, $REST_ENGINE, $REST_WIKI_DIR);

// 初回リクエスト時（page_index が空の場合）に自動でインデックスを構築する。
// COUNT(*)1回のみのため通常リクエストのオーバーヘッドは無視できる。
if (is_dir($REST_WIKI_DIR)) {
    $REST_RECONCILER->buildIndexIfEmpty();
}

// PUT/PATCH による直接編集から保護するページ一覧。
// これらは draft ワークフロー（POST /drafts）経由のみ編集可能。
// 環境変数 PKWK_PROTECTED_PAGES で JSON 配列として上書き可能:
//   PKWK_PROTECTED_PAGES='["FrontPage","MenuBar","SiteMenu"]'
$REST_PROTECTED_PAGES = ($env = getenv('PKWK_PROTECTED_PAGES'))
    ? (json_decode($env, true) ?? ['FrontPage', 'MenuBar'])
    : ['FrontPage', 'MenuBar'];
