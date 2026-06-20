<?php
declare(strict_types=1);

require_once __DIR__ . '/Ledger.php';
require_once __DIR__ . '/RevisionStore.php';

/**
 * ファイル↔DB 照合・自己修復（Reconciler）。
 *
 * PukiWiki 本体やプラグインは DB を知らずに wiki/ を直接書き換える。
 * 読み取り時にファイルの sha1 と DB の content_sha1 を照合し、
 * 食い違っていれば「外部編集」として DB を追従させる（自己修復）。
 *
 * これにより「正本はファイル」の不変条件を DB 側から守る。
 * 完全な防止は本体改造が必要で、検出追従が現実解。
 *
 * ページ名のエンコード: PukiWiki は encode($page) = strtoupper(bin2hex($page))
 * でファイル名を決める。PukiWiki が利用可能な環境では本体の encode()/decode() を
 * 優先する。スタンドアローンテスト用に同等実装も持つ。
 * @version v0.1
 */
final class Reconciler
{
    private Ledger $ledger;
    private RevisionStore $revisions;
    private string $wiki_dir;

    public function __construct(Ledger $ledger, RevisionStore $revisions, string $wiki_dir)
    {
        $this->ledger    = $ledger;
        $this->revisions = $revisions;
        $this->wiki_dir  = rtrim($wiki_dir, '/');
    }

    /**
     * 1 ページを照合する。
     * @return string 'in_sync' | 'healed' | 'file_not_found'
     */
    public function check(string $page): string
    {
        $file = $this->wiki_dir . '/' . self::encode($page) . '.txt';

        if (!file_exists($file)) {
            $meta = $this->ledger->getPage($page);
            if ($meta !== null && $meta['status'] === 'active') {
                $this->ledger->markDeleted($page, time());
                $this->ledger->log('external_delete_healed', $page, 'system');
                return 'healed';
            }
            return 'file_not_found';
        }

        $content = file_get_contents($file);
        if ($content === false) {
            throw new \RuntimeException("Cannot read wiki file: {$file}");
        }
        $file_sha1 = sha1($content);

        $meta = $this->ledger->getPage($page);
        if ($meta !== null && $meta['content_sha1'] === $file_sha1) {
            return 'in_sync';
        }

        // 外部編集を検出: blob を保存 → DB を修復 → 検索インデックスも更新
        $now     = time();
        $new_rev = ($meta !== null ? (int)$meta['current_rev'] : 0) + 1;
        $this->revisions->append($content, $page, $new_rev, 'external-edit');
        $this->ledger->heal($page, $file_sha1, $now);
        $this->ledger->indexPage($page, $content, $now);
        return 'healed';
    }

    /**
     * wiki_dir 全体を走査して DB と同期させる（起動時・DB再構築後に使う）。
     * @return array ['in_sync' => int, 'healed' => int, 'file_not_found' => int]
     */
    public function fullScan(): array
    {
        $counts = ['in_sync' => 0, 'healed' => 0, 'file_not_found' => 0];

        $dh = opendir($this->wiki_dir);
        if ($dh === false) {
            throw new \RuntimeException("Cannot open wiki dir: {$this->wiki_dir}");
        }

        $pages = [];
        while (($entry = readdir($dh)) !== false) {
            if (!str_ends_with($entry, '.txt')) {
                continue;
            }
            $encoded = substr($entry, 0, -4);
            $page    = self::decode($encoded);
            if ($page === '') {
                continue;
            }
            $pages[] = $page;
        }
        closedir($dh);

        foreach ($pages as $page) {
            $result = $this->check($page);
            $counts[$result]++;
        }
        return $counts;
    }

    /**
     * ファイルから DB を完全再構築する（DB 破損からの復旧）。
     * pages・revisions・page_index すべてを最初から作り直す。
     */
    public function rebuildFromFiles(): array
    {
        $now    = time();
        $counts = ['pages' => 0, 'errors' => 0];

        $dh = opendir($this->wiki_dir);
        if ($dh === false) {
            throw new \RuntimeException("Cannot open wiki dir: {$this->wiki_dir}");
        }

        $page_index_data = [];
        while (($entry = readdir($dh)) !== false) {
            if (!str_ends_with($entry, '.txt')) {
                continue;
            }
            $encoded = substr($entry, 0, -4);
            $page    = self::decode($encoded);
            if ($page === '') {
                continue;
            }

            $file = $this->wiki_dir . '/' . $entry;
            $content = file_get_contents($file);
            if ($content === false) {
                $counts['errors']++;
                continue;
            }

            $sha1    = sha1($content);
            $file_mtime = (int)filemtime($file);

            // blob を保存
            $this->revisions->append($content, $page, 1, 'rebuild');

            // pages テーブルを upsert
            $this->ledger->upsertPage($page, 1, $sha1, $file_mtime);

            // 版履歴を追記
            $this->ledger->appendRevision($page, 1, $sha1, 'rebuild', [], $file_mtime);

            $page_index_data[] = [$page, $content, $file_mtime];
            $counts['pages']++;
        }
        closedir($dh);

        // 検索インデックスを再構築
        $this->ledger->rebuildIndex($page_index_data);
        $this->ledger->log('db_rebuilt_from_files', null, 'system', $counts);

        return $counts;
    }

    // -------------------------------------------------------------------------
    // 検索インデックス管理
    // -------------------------------------------------------------------------

    /**
     * page_index が空の場合のみ wiki/ から構築する（起動時の初回ビルド用）。
     * 既にインデックスが存在する場合は何もしない（COUNT クエリのみ）。
     * @return int 追加したページ数（0 = 既に構築済み）
     */
    public function buildIndexIfEmpty(): int
    {
        $count = (int)$this->ledger->getPdo()
            ->query('SELECT COUNT(*) FROM page_index')
            ->fetchColumn();
        return $count > 0 ? 0 : $this->buildIndex();
    }

    /**
     * wiki/ 全ファイルから page_index を再構築する。
     * pages/revisions テーブルは変更しない（軽量リビルド）。
     * @return int 構築したページ数
     */
    public function buildIndex(): int
    {
        $now  = time();
        $data = [];

        $dh = opendir($this->wiki_dir);
        if ($dh === false) {
            throw new \RuntimeException("Cannot open wiki dir: {$this->wiki_dir}");
        }

        while (($entry = readdir($dh)) !== false) {
            if (!str_ends_with($entry, '.txt')) {
                continue;
            }
            $encoded = substr($entry, 0, -4);
            $page    = self::decode($encoded);
            if ($page === '') {
                continue;
            }
            $content = @file_get_contents($this->wiki_dir . '/' . $entry);
            if ($content === false) {
                continue;
            }
            $data[] = [$page, $content, (int)filemtime($this->wiki_dir . '/' . $entry)];
        }
        closedir($dh);

        $this->ledger->rebuildIndex($data);
        $this->ledger->log('index_rebuilt', null, 'system', ['pages' => count($data)]);
        return count($data);
    }

    /**
     * page_index と wiki/ ファイルの整合性を検証する。
     * インデックスにあるのにファイルがない（孤立）、
     * ファイルがあるのにインデックスにない（未登録）を検出する。
     *
     * @return array{
     *   total_files: int,
     *   total_indexed: int,
     *   missing_in_index: string[],
     *   orphan_in_index: string[]
     * }
     */
    public function verifyIndex(): array
    {
        // wiki/ のファイルを収集
        $file_pages = [];
        $dh = opendir($this->wiki_dir);
        if ($dh !== false) {
            while (($entry = readdir($dh)) !== false) {
                if (!str_ends_with($entry, '.txt')) {
                    continue;
                }
                $page = self::decode(substr($entry, 0, -4));
                if ($page !== '') {
                    $file_pages[$page] = true;
                }
            }
            closedir($dh);
        }

        // page_index を収集
        $indexed     = $this->ledger->listPages(100000, 0);
        $indexed_set = array_flip(array_column($indexed, 'name'));

        $missing_in_index = [];
        foreach (array_keys($file_pages) as $page) {
            if (!isset($indexed_set[$page])) {
                $missing_in_index[] = $page;
            }
        }

        $orphan_in_index = [];
        foreach (array_keys($indexed_set) as $page) {
            if (!isset($file_pages[$page])) {
                $orphan_in_index[] = $page;
            }
        }

        return [
            'total_files'      => count($file_pages),
            'total_indexed'    => count($indexed),
            'missing_in_index' => $missing_in_index,
            'orphan_in_index'  => $orphan_in_index,
            'is_consistent'    => empty($missing_in_index) && empty($orphan_in_index),
        ];
    }

    // -------------------------------------------------------------------------
    // PukiWiki encode/decode（スタンドアローン実装）
    // PukiWiki が読み込まれている環境では本体の encode()/decode() が優先される。
    // -------------------------------------------------------------------------

    public static function encode(string $page): string
    {
        if (function_exists('encode')) {
            return encode($page);
        }
        return $page === '' ? '' : strtoupper(bin2hex($page));
    }

    public static function decode(string $encoded): string
    {
        if (function_exists('decode')) {
            return decode($encoded);
        }
        if ($encoded === '' || !ctype_xdigit($encoded)) {
            return '';
        }
        $bin = @hex2bin(strtolower($encoded));
        return $bin !== false ? $bin : '';
    }
}
