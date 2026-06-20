<?php
declare(strict_types=1);

require_once __DIR__ . '/BlockSplitter.php';
require_once __DIR__ . '/ApiException.php';

/**
 * ブロック単位のパッチ適用器。
 *
 * AI は wiki_read_blocks でブロック一覧と block_sha1 を取得し、
 * 変更したいブロックの block_sha1 と新しい内容を指定してパッチを作成する。
 *
 * パッチ形式:
 *   [
 *     ['block_sha1' => '16hex', 'new_content' => '新しい内容'],
 *     ['block_sha1' => 'deadbeef01234567', 'new_content' => null],  // null = 削除
 *   ]
 *
 * 競合検出（per-block 409）:
 *   block_sha1 が現在のページに存在しない場合、ApiException(409, 'block_not_found') を投げる。
 *   これはページが更新されてブロック内容が変わった場合に発生する。
 *
 * 注意:
 *   ページ内に同じ内容のブロックが複数ある場合（block_sha1 重複）、
 *   パッチは最初に一致するブロックに適用される。
 *   同一 sha1 のブロックが複数あることを確認してから操作したい場合は、
 *   ページ全体の draft:create を使うこと。
 * @version v0.1
 */
final class BlockEditor
{
    /**
     * ブロックパッチをページ全文に適用して新しい全文を返す。
     *
     * @param string $full_content 現在のページ全文
     * @param array  $patches      パッチの配列 [{block_sha1, new_content: string|null}]
     * @return string 新しい全文
     * @throws ApiException 409 block_not_found — block_sha1 が見つからない場合
     */
    public static function apply(string $full_content, array $patches): string
    {
        $blocks = BlockSplitter::split($full_content);

        // block_sha1 → 最初のブロックインデックスのマップ
        $sha1_to_idx = [];
        foreach ($blocks as $i => $block) {
            if (!isset($sha1_to_idx[$block['block_sha1']])) {
                $sha1_to_idx[$block['block_sha1']] = $i;
            }
        }

        // パッチのバリデーション（全て適用前に確認）
        foreach ($patches as $patch) {
            $bsha1 = (string)($patch['block_sha1'] ?? '');
            if (!isset($sha1_to_idx[$bsha1])) {
                throw new ApiException(
                    409,
                    "Block '{$bsha1}' not found in current page. " .
                    "The page may have been modified since you read it. " .
                    "Call wiki_read_blocks again to get current block sha1s.",
                    'block_not_found'
                );
            }
        }

        // パッチを idx → new_content のマップに変換
        $idx_to_new = [];
        foreach ($patches as $patch) {
            $bsha1 = (string)($patch['block_sha1'] ?? '');
            $idx   = $sha1_to_idx[$bsha1];
            // 複数のパッチが同じブロックを対象にした場合は後勝ち
            $idx_to_new[$idx] = $patch['new_content'] ?? null;
        }

        // ブロックを新しい内容に置き換えながら再組み立て
        $new_block_contents = [];
        foreach ($blocks as $i => $block) {
            if (array_key_exists($i, $idx_to_new)) {
                $replacement = $idx_to_new[$i];
                if ($replacement !== null) {
                    // 置換（trailing newline は BlockSplitter::join の "\n" が担う）
                    $new_block_contents[] = rtrim((string)$replacement, "\n");
                }
                // null の場合はブロックを削除（何も追加しない）
            } else {
                $new_block_contents[] = $block['content'];
            }
        }

        // ブロックを "\n" で結合して全文を復元
        // 末尾の空ブロック（元文書が "\n" 終端だった場合）も保持される
        return implode("\n", $new_block_contents);
    }

    /**
     * ページ全文のブロック一覧を返す（API 表示用の拡張情報付き）。
     *
     * @return array<array{type: string, content: string, block_sha1: string, index: int, line_preview: string}>
     */
    public static function describe(string $full_content): array
    {
        $blocks = BlockSplitter::split($full_content);
        $result = [];
        foreach ($blocks as $i => $block) {
            $preview = mb_substr($block['content'], 0, 80, 'UTF-8');
            if (mb_strlen($block['content'], 'UTF-8') > 80) {
                $preview .= '…';
            }
            $result[] = [
                'index'        => $i,
                'type'         => $block['type'],
                'content'      => $block['content'],
                'block_sha1'   => $block['block_sha1'],
                'line_preview' => str_replace("\n", '↵', $preview),
            ];
        }
        return $result;
    }
}
