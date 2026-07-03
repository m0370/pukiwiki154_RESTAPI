<?php
declare(strict_types=1);

/**
 * HTTP JSON レスポンスヘルパ。
 * テスト時は send() の代わりに toArray()/getStatus() で内容を取り出せる。
 * @version v2.0
 */
final class Response
{
    private function __construct(
        private int   $status,
        private array $body,
        private array $headers = []
    ) {
        $this->headers = array_merge([
            'Content-Type'           => 'application/json; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'no-store',
        ], $headers);
    }

    public static function ok(array $body, array $headers = []): self
    {
        return new self(200, $body, $headers);
    }

    public static function created(array $body, array $headers = []): self
    {
        return new self(201, $body, $headers);
    }

    public static function error(int $status, string $message, string $code = ''): self
    {
        return new self($status, [
            'error' => [
                'status'  => $status,
                'code'    => $code !== '' ? $code : self::defaultCode($status),
                'message' => $message,
            ],
        ]);
    }

    /** 例外を error レスポンスに変換。予期しない例外は詳細を隠して 500 */
    public static function fromException(\Throwable $e): self
    {
        if ($e instanceof \ApiException) {
            return self::error($e->status, $e->getMessage(), $e->error_code);
        }
        error_log('[pukiwiki-rest-api] ' . get_class($e) . ': ' . $e->getMessage()
            . ' at ' . $e->getFile() . ':' . $e->getLine());
        return self::error(500, 'Internal server error', 'internal_error');
    }

    public function send(): never
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        echo json_encode($this->body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public function toArray(): array
    {
        return $this->body;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    private static function defaultCode(int $status): string
    {
        return match ($status) {
            400     => 'bad_request',
            401     => 'unauthorized',
            403     => 'forbidden',
            404     => 'not_found',
            405     => 'method_not_allowed',
            409     => 'conflict',
            413     => 'payload_too_large',
            423     => 'locked',
            500     => 'internal_error',
            default => 'error',
        };
    }
}
