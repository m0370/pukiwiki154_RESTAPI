<?php
declare(strict_types=1);

/**
 * unified diff 生成ユーティリティ。
 *
 * POSIX の diff コマンド（-u オプション）を呼び出して生成する。
 * 一時ファイルを同一 FS 内で作成し、終了後は必ず削除する。
 *
 * diff コマンドが使えない環境（Windows 等）ではフォールバックを使う。
 * @version v0.1
 */
final class DiffEngine
{
    /**
     * $old と $new の unified diff テキストを返す。
     * 差分がなければ空文字列。
     */
    public static function unified(
        string $old,
        string $new,
        string $label_old = 'current',
        string $label_new = 'draft',
        int    $context   = 3
    ): string {
        if ($old === $new) {
            return '';
        }

        if (self::diffAvailable()) {
            return self::shellDiff($old, $new, $label_old, $label_new, $context);
        }
        return self::phpDiff($old, $new, $label_old, $label_new);
    }

    /**
     * unified diff 文字列から追加行数・削除行数を集計する。
     * @return array{added: int, removed: int, has_diff: bool}
     */
    public static function stats(string $diff): array
    {
        $added = $removed = 0;
        foreach (explode("\n", $diff) as $line) {
            if ($line === '' || strlen($line) < 1) {
                continue;
            }
            if ($line[0] === '+' && !str_starts_with($line, '+++')) {
                $added++;
            } elseif ($line[0] === '-' && !str_starts_with($line, '---')) {
                $removed++;
            }
        }
        return ['added' => $added, 'removed' => $removed, 'has_diff' => ($added + $removed) > 0];
    }

    /**
     * unified diff を人間が見やすい HTML に変換する。
     * XSS 対策のため、行内容は htmlspecialchars で逃がす。
     */
    public static function toHtml(string $diff): string
    {
        if ($diff === '') {
            return '<p class="diff-none">差分なし（内容は同一です）</p>';
        }

        $html = "<pre class=\"diff\">\n";
        foreach (explode("\n", $diff) as $line) {
            if ($line === '') {
                $html .= "\n";
                continue;
            }
            $esc   = htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $class = match (true) {
                str_starts_with($line, '---') || str_starts_with($line, '+++') => 'diff-header',
                str_starts_with($line, '@@')                                    => 'diff-hunk',
                str_starts_with($line, '+')                                     => 'diff-added',
                str_starts_with($line, '-')                                     => 'diff-removed',
                default                                                          => 'diff-context',
            };
            $html .= "<span class=\"{$class}\">{$esc}</span>\n";
        }
        return $html . "</pre>";
    }

    // -------------------------------------------------------------------------

    private static function diffAvailable(): bool
    {
        static $ok = null;
        if ($ok === null) {
            exec('diff --version 2>/dev/null', $out, $code);
            // diff は等しければ 0、差異があれば 1、エラーは 2 を返す
            $ok = ($code === 0 || $code === 1);
        }
        return $ok;
    }

    private static function shellDiff(
        string $old, string $new,
        string $label_old, string $label_new,
        int $context
    ): string {
        $tmp1 = tempnam(sys_get_temp_dir(), 'pkwk_old_');
        $tmp2 = tempnam(sys_get_temp_dir(), 'pkwk_new_');
        try {
            file_put_contents($tmp1, $old);
            file_put_contents($tmp2, $new);
            $cmd = sprintf(
                'diff -u -U %d %s %s 2>/dev/null',
                $context,
                escapeshellarg($tmp1),
                escapeshellarg($tmp2)
            );
            exec($cmd, $lines, $code);
            $diff = implode("\n", $lines);
            // 一時パスをラベルに置換
            $diff = str_replace($tmp1, $label_old, $diff);
            $diff = str_replace($tmp2, $label_new, $diff);
            return $diff;
        } finally {
            @unlink($tmp1);
            @unlink($tmp2);
        }
    }

    /**
     * 純 PHP のシンプルな行差分。
     * diff コマンドが使えない場合のフォールバック。
     * LCS ではなく逐次比較のため大きなファイルでは不正確だが、
     * 人間レビュー目的には十分。
     */
    private static function phpDiff(
        string $old, string $new,
        string $label_old, string $label_new
    ): string {
        $old_lines = explode("\n", $old);
        $new_lines = explode("\n", $new);
        $out = ["--- {$label_old}", "+++ {$label_new}", '@@ -1 +1 @@'];

        // 行ごとに照合（LCS 近似: 同じ行は飛ばす）
        $oi = $ni = 0;
        $mo = count($old_lines);
        $mn = count($new_lines);

        while ($oi < $mo || $ni < $mn) {
            $ol = $old_lines[$oi] ?? null;
            $nl = $new_lines[$ni] ?? null;

            if ($ol === $nl) {
                $out[] = ' ' . $ol;
                $oi++; $ni++;
            } elseif ($oi < $mo) {
                $out[] = '-' . $ol;
                $oi++;
                // 先読み: 次の新規行が一致するか
                if ($ni < $mn && isset($old_lines[$oi]) && $old_lines[$oi] === $new_lines[$ni]) {
                    // 挿入と見なす
                } else {
                    if ($ni < $mn) {
                        $out[] = '+' . $nl;
                        $ni++;
                    }
                }
            } else {
                $out[] = '+' . $nl;
                $ni++;
            }
        }

        return implode("\n", $out);
    }
}
