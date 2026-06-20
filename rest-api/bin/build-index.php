#!/usr/bin/env php
<?php
/**
 * 検索インデックス再構築スクリプト（CLI）
 *
 * PukiWiki の wiki/ ディレクトリから page_index を再構築する。
 * 初回セットアップや DB 破損からの復旧時に実行する。
 *
 * 実行例:
 *   php rest-api/bin/build-index.php
 *   php rest-api/bin/build-index.php --verify-only
 *   PKWK_ROOT=/var/www/pukiwiki php rest-api/bin/build-index.php
 * @version v0.1
 */
declare(strict_types=1);

// CLI からの実行を確認
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

chdir(dirname(__DIR__)); // rest-api/ を作業ディレクトリにする

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/Reconciler.php';

$args = array_slice($argv ?? [], 1);
$verify_only = in_array('--verify-only', $args, true);
$full_rebuild = in_array('--full-rebuild', $args, true); // DB 全再構築（pages/revisions も）

echo "Wiki dir  : {$REST_WIKI_DIR}\n";
echo "DB path   : {$REST_DB_PATH}\n\n";

if ($verify_only) {
    echo "=== インデックス整合性チェック ===\n";
    $status = $REST_RECONCILER->verifyIndex();
    printf("ファイル数    : %d\n", $status['total_files']);
    printf("インデックス数: %d\n", $status['total_indexed']);
    printf("整合性       : %s\n", $status['is_consistent'] ? '✓ 一致' : '✗ 不一致あり');

    if (!empty($status['missing_in_index'])) {
        echo "\nインデックス未登録（wiki/ にあるのにインデックスにないページ）:\n";
        foreach ($status['missing_in_index'] as $p) {
            echo "  - {$p}\n";
        }
    }
    if (!empty($status['orphan_in_index'])) {
        echo "\n孤立エントリ（インデックスにあるのに wiki/ にないページ）:\n";
        foreach ($status['orphan_in_index'] as $p) {
            echo "  - {$p}\n";
        }
    }
    exit($status['is_consistent'] ? 0 : 1);
}

if ($full_rebuild) {
    echo "=== DB 全再構築（pages / revisions / page_index）===\n";
    $start = microtime(true);
    $result = $REST_RECONCILER->rebuildFromFiles();
    $elapsed = round(microtime(true) - $start, 2);
    printf("完了: %d ページ、エラー %d 件（%.2f 秒）\n", $result['pages'], $result['errors'], $elapsed);
    exit($result['errors'] > 0 ? 1 : 0);
}

// デフォルト: page_index のみ再構築
echo "=== 検索インデックス再構築（page_index のみ）===\n";
$start = microtime(true);
$count = $REST_RECONCILER->buildIndex();
$elapsed = round(microtime(true) - $start, 2);
printf("完了: %d ページをインデックス化（%.2f 秒）\n", $count, $elapsed);
exit(0);
