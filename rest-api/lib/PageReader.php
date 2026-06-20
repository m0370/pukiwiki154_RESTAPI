<?php
declare(strict_types=1);

require_once __DIR__ . '/Ledger.php';
require_once __DIR__ . '/Reconciler.php';
require_once __DIR__ . '/ApiException.php';

/**
 * ページ読み取りハンドラ。
 *
 * 読み取り時に必ず Reconciler::check() を呼んで自己修復を試みる。
 * これにより PukiWiki 本体による外部編集が透過的に DB に追従される。
 * @version v0.1
 */
final class PageReader
{
    public function __construct(
        private Reconciler $reconciler,
        private Ledger     $ledger,
        private string     $wiki_dir
    ) {}

    /**
     * ページの内容とメタデータを返す。
     * ページが存在しない場合は ApiException(404)。
     *
     * @return array{
     *   page: string,
     *   rev: int,
     *   sha1: string,
     *   content: string,
     *   size: int,
     *   updated_at: string,
     *   is_frozen: bool|null,
     *   is_editable: bool|null,
     *   status: string
     * }
     */
    public function read(string $page): array
    {
        // 読み取り前に自己修復（外部編集の検出と DB 追従）
        $this->reconciler->check($page);

        $file = $this->wiki_dir . '/' . Reconciler::encode($page) . '.txt';
        if (!file_exists($file)) {
            throw new ApiException(404, "Page '{$page}' not found");
        }

        $content = file_get_contents($file);
        if ($content === false) {
            throw new ApiException(500, "Cannot read page file");
        }

        $meta  = $this->ledger->getPage($page);
        $sha1  = sha1($content);
        $mtime = (int)filemtime($file);

        return [
            'page'        => $page,
            'rev'         => $meta !== null ? (int)$meta['current_rev'] : 1,
            'sha1'        => $sha1,
            'content'     => $content,
            'size'        => strlen($content),
            'updated_at'  => gmdate('c', $mtime),
            'is_frozen'   => $this->isFrozen($page),
            'is_editable' => $this->isEditable($page),
            'status'      => $meta !== null ? (string)$meta['status'] : 'active',
        ];
    }

    /**
     * 全ページの一覧（名前・更新日時）を返す。
     * 台帳のインデックスから取得するためファイル走査より高速。
     */
    public function listPages(int $limit = 1000, int $offset = 0): array
    {
        return $this->ledger->listPages($limit, $offset);
    }

    // PukiWiki が読み込まれている場合は本体の関数を使う。
    // 未読み込み（スタンドアローン・テスト環境）では null を返す。

    private function isFrozen(string $page): ?bool
    {
        if (function_exists('is_freeze')) {
            return (bool)is_freeze($page);
        }
        return null;
    }

    private function isEditable(string $page): ?bool
    {
        if (function_exists('is_editable')) {
            return (bool)is_editable($page);
        }
        return null;
    }
}
