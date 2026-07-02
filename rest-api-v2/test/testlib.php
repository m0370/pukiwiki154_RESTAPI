<?php
/**
 * テスト用の最小アサーションヘルパ。
 * @version v2.0
 */
declare(strict_types=1);

$GLOBALS['__test_pass'] = 0;
$GLOBALS['__test_fail'] = 0;

function section(string $name): void
{
    echo "\n== {$name} ==\n";
}

function ok(bool $cond, string $label): void
{
    if ($cond) {
        $GLOBALS['__test_pass']++;
        echo "  ✓ {$label}\n";
    } else {
        $GLOBALS['__test_fail']++;
        echo "  ✗ FAIL: {$label}\n";
    }
}

/** ApiException が投げられ、status が一致することを確認する */
function expect_api_error(callable $fn, int $status, string $label): void
{
    try {
        $fn();
        ok(false, "{$label}（例外が投げられなかった）");
    } catch (ApiException $e) {
        ok($e->status === $status, "{$label}（status={$e->status}, code={$e->error_code}）");
    }
}

function summary(): never
{
    $pass = $GLOBALS['__test_pass'];
    $fail = $GLOBALS['__test_fail'];
    echo "\n----------------------------------------\n";
    echo "結果: {$pass} passed, {$fail} failed\n";
    exit($fail > 0 ? 1 : 0);
}
