<?php
declare(strict_types=1);

/**
 * HTTP JSON レスポンスヘルパ。
 *
 * テスト時は send() の代わりに toArray()/toJson() で内容を取り出せる。
 * @version v0.1
 */
final class Response
{
    private array  $body;
    private int    $status;
    private array  $headers;

    private function __construct(int $status, array $body, array $extra_headers = [])
    {
        $this->status  = $status;
        $this->body    = $body;
        $this->headers = array_merge(
            [
                'Content-Type'                => 'application/json; charset=utf-8',
                'Access-Control-Allow-Origin' => '*',
                'X-Content-Type-Options'      => 'nosniff',
                'Cache-Control'               => 'no-store',
            ],
            $extra_headers
        );
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
                'code'    => $code ?: self::defaultCode($status),
                'message' => $message,
            ],
        ]);
    }

    /** ApiException をそのまま error レスポンスに変換 */
    public static function fromException(\Throwable $e): self
    {
        if ($e instanceof \ApiException) {
            return self::error($e->status, $e->getMessage(), $e->error_code);
        }
        // 予期しない例外は 500 として返す（本番ではスタックトレースを隠す）
        return self::error(500, 'Internal server error', 'internal_error');
    }

    /** HTTP レスポンスとして送信（本番のエントリポイントから呼ぶ） */
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

    public function toJson(): string
    {
        return json_encode($this->body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
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
            422     => 'unprocessable',
            500     => 'internal_error',
            default => 'error',
        };
    }
}
