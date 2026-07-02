<?php
declare(strict_types=1);

/**
 * API エラーを HTTP ステータスコード付きで伝搬するための例外。
 * @version v2.0
 */
final class ApiException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly string $error_code = ''
    ) {
        parent::__construct($message);
    }
}
