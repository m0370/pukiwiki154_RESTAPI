<?php
declare(strict_types=1);

/**
 * PukiWiki マークアップのブロック分割器。
 *
 * コンテンツを「編集単位ブロック」に分割し、
 * 各ブロックに content-hash アンカー（block_sha1）を付与する。
 *
 * ──────────────────────────────────────────────────
 * 不変量（必須）:
 *   BlockSplitter::join(BlockSplitter::split($content)) === $content
 *
 * これにより、ブロック分割→再組み立てで元の文字列が完全復元できる。
 * ──────────────────────────────────────────────────
 *
 * ブロック種別と分類ルール:
 *
 *   heading   /^={1,3}\s/   見出し（1行が1ブロック、連結しない）
 *   hr        /^-{4,}/      水平線（1行が1ブロック）
 *   empty     ''             空行（1行が1ブロック）
 *   plugin    /^#[a-zA-Z]/  プラグイン行（1行が1ブロック）
 *   list      /^[-+*]{1,3}/ リスト（連続する行をまとめる）
 *   table     /^\|/         テーブル行（連続する行をまとめる）
 *   pre       /^ /           整形済みテキスト（連続する行をまとめる）
 *   definition /^:.+\|/     定義リスト（連続する行をまとめる）
 *   paragraph  それ以外      テキスト段落（連続する行をまとめる）
 *
 * heading / hr / empty / plugin は常に単独ブロック。
 * それ以外は同じタイプが続く限り同一ブロックにまとめる。
 * @version v0.1
 */
final class BlockSplitter
{
    public const T_HEADING    = 'heading';
    public const T_HR         = 'hr';
    public const T_EMPTY      = 'empty';
    public const T_PLUGIN     = 'plugin';
    public const T_LIST       = 'list';
    public const T_TABLE      = 'table';
    public const T_PRE        = 'pre';
    public const T_DEFINITION = 'definition';
    public const T_PARAGRAPH  = 'paragraph';

    // 常に単独ブロックにするタイプ
    private const STANDALONE = [
        self::T_HEADING,
        self::T_HR,
        self::T_EMPTY,
        self::T_PLUGIN,
    ];

    /**
     * コンテンツをブロック配列に分割する。
     *
     * @return array<array{type: string, content: string, block_sha1: string}>
     */
    public static function split(string $content): array
    {
        if ($content === '') {
            return [];
        }

        $lines   = explode("\n", $content);
        $blocks  = [];
        $pending_type  = null;
        $pending_lines = [];

        $flush = static function () use (&$blocks, &$pending_type, &$pending_lines): void {
            if ($pending_type === null) {
                return;
            }
            $text     = implode("\n", $pending_lines);
            $blocks[] = self::makeBlock($pending_type, $text);
            $pending_type  = null;
            $pending_lines = [];
        };

        foreach ($lines as $line) {
            $type = self::lineType($line);

            if (in_array($type, self::STANDALONE, true)) {
                // 単独ブロック: 保留中のブロックを先に確定してから追加
                $flush();
                $blocks[] = self::makeBlock($type, $line);
                continue;
            }

            // グループ化タイプ
            if ($type === $pending_type) {
                $pending_lines[] = $line;
            } else {
                $flush();
                $pending_type  = $type;
                $pending_lines = [$line];
            }
        }
        $flush();

        return $blocks;
    }

    /**
     * ブロック配列を文字列に再組み立てする。
     * split() と組み合わせると不変量 join(split($c)) === $c が成り立つ。
     */
    public static function join(array $blocks): string
    {
        return implode("\n", array_column($blocks, 'content'));
    }

    /**
     * 行のブロック種別を返す。
     */
    public static function lineType(string $line): string
    {
        if ($line === '') {
            return self::T_EMPTY;
        }
        // 水平線: ---- 以上（先に判定しないと -- がリストと誤判定する）
        if (preg_match('/^-{4,}$/', $line)) {
            return self::T_HR;
        }
        // 見出し: = 〜 ===
        if (preg_match('/^={1,3}(?:\s|$)/', $line)) {
            return self::T_HEADING;
        }
        // プラグイン: #name(...)
        if (preg_match('/^#[a-zA-Z_]/', $line)) {
            return self::T_PLUGIN;
        }
        // リスト: -, --, ---, +, ++, +++, *, **, ***
        if (preg_match('/^[-+*]{1,3}(?:\s|$)/', $line) && !str_starts_with($line, '----')) {
            return self::T_LIST;
        }
        // テーブル: |...|
        if (str_starts_with($line, '|')) {
            return self::T_TABLE;
        }
        // 定義リスト: :term|desc
        if (str_starts_with($line, ':') && str_contains($line, '|')) {
            return self::T_DEFINITION;
        }
        // 整形済みテキスト: 先頭スペース
        if (str_starts_with($line, ' ') || str_starts_with($line, "\t")) {
            return self::T_PRE;
        }
        return self::T_PARAGRAPH;
    }

    // -------------------------------------------------------------------------

    private static function makeBlock(string $type, string $content): array
    {
        return [
            'type'       => $type,
            'content'    => $content,
            'block_sha1' => substr(sha1($content), 0, 16),
        ];
    }
}
