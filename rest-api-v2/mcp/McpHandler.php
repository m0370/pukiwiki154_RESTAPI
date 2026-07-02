<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/PageStore.php';
require_once __DIR__ . '/../lib/ApiException.php';

/**
 * MCP (Model Context Protocol) JSON-RPC 2.0 ハンドラ。
 *
 * ツール（4個）:
 *   wiki_read_page   ページの内容とメタ（sha1・凍結状態）を取得
 *   wiki_list_pages  ページ一覧
 *   wiki_search      全文検索
 *   wiki_write_page  ページ全文を書き込み（base_sha1 必須の楽観ロック付き）
 *
 * 注意: このサーバーは書き込みツールを公開する（v2 の設計判断）。
 * AI に直接編集を許可する構成のため、以下の安全装置が常に効く:
 *   - base_sha1 CAS（古い版に基づく上書きは 409 相当のエラー）
 *   - 凍結ページ・保護ページ・システムページは拒否
 *   - 空本文（＝ページ削除）は拒否
 *   - 全書き込みがスナップショット保存＋監査ログ＋ #author 記録される
 * 編集させたくないページは PukiWiki の凍結機能でオプトアウトできる。
 * @version v2.0
 */
final class McpHandler
{
    private const PROTOCOL_VERSION = '2024-11-05';
    private const SERVER_NAME      = 'pukiwiki-rest-api';
    private const SERVER_VERSION   = '2.0.0';

    private const ERR_METHOD   = -32601;
    private const ERR_PARAMS   = -32602;
    private const ERR_INTERNAL = -32603;

    public function __construct(
        private PageStore $pages,
        private string    $actor = 'mcp-client',
    ) {}

    /** JSON-RPC メッセージを処理する。Notification（id なし）は null を返す */
    public function handle(array $message): ?array
    {
        if (!array_key_exists('id', $message)) {
            return null;
        }
        $id     = $message['id'];
        $method = (string)($message['method'] ?? '');
        $params = (array)($message['params'] ?? []);

        try {
            $result = match ($method) {
                'initialize' => [
                    'protocolVersion' => self::PROTOCOL_VERSION,
                    'capabilities'    => ['tools' => ['listChanged' => false]],
                    'serverInfo'      => [
                        'name'    => self::SERVER_NAME,
                        'version' => self::SERVER_VERSION,
                    ],
                ],
                'tools/list' => ['tools' => self::toolDefinitions()],
                'tools/call' => $this->toolsCall($params),
                'ping'       => [],
                default      => throw new \RuntimeException("Method not found: {$method}", self::ERR_METHOD),
            };
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
        } catch (\Throwable $e) {
            $code = ($e->getCode() < 0) ? (int)$e->getCode() : self::ERR_INTERNAL;
            return [
                'jsonrpc' => '2.0',
                'id'      => $id,
                'error'   => ['code' => $code, 'message' => $e->getMessage()],
            ];
        }
    }

    private function toolsCall(array $params): array
    {
        $name = (string)($params['name'] ?? '');
        $args = (array)($params['arguments'] ?? []);

        $text = match ($name) {
            'wiki_read_page'  => $this->toolReadPage($args),
            'wiki_list_pages' => $this->toolListPages($args),
            'wiki_search'     => $this->toolSearch($args),
            'wiki_write_page' => $this->toolWritePage($args),
            default           => throw new \RuntimeException("Unknown tool: {$name}", self::ERR_PARAMS),
        };

        return ['content' => [['type' => 'text', 'text' => $text]]];
    }

    // -------------------------------------------------------------------------
    // ツール実装
    // -------------------------------------------------------------------------

    private function toolReadPage(array $args): string
    {
        $page = self::strArg($args, 'page', required: true);
        try {
            $d = $this->pages->read($page);
        } catch (ApiException $e) {
            if ($e->status === 404) {
                return "Page '{$page}' does not exist.\n"
                    . "To create it, call wiki_write_page with base_sha1="
                    . PageStore::EMPTY_SHA1 . " (sha1 of empty string).";
            }
            return 'Error: ' . $e->getMessage();
        }

        $frozen   = $d['is_frozen']   === null ? 'unknown' : ($d['is_frozen']   ? 'yes' : 'no');
        $editable = $d['is_editable'] === null ? 'unknown' : ($d['is_editable'] ? 'yes' : 'no');

        return implode("\n", [
            "# Page: {$d['page']}",
            "SHA1: {$d['sha1']}",
            "Updated: {$d['updated_at']}  |  Size: {$d['size']} bytes",
            "Frozen: {$frozen}  |  Editable: {$editable}",
            '',
            'To edit this page, pass the SHA1 above as base_sha1 to wiki_write_page.',
            '--- content ---',
            $d['content'],
        ]);
    }

    private function toolListPages(array $args): string
    {
        $limit  = max(1, min(1000, (int)($args['limit'] ?? 100)));
        $offset = max(0, (int)($args['offset'] ?? 0));
        $result = $this->pages->listPages($limit, $offset);

        if (empty($result['pages'])) {
            return 'No pages found.';
        }
        $lines = ["Pages (" . count($result['pages']) . " of {$result['total']}):", ''];
        foreach ($result['pages'] as $p) {
            $lines[] = "- {$p['name']} ({$p['updated_at']})";
        }
        return implode("\n", $lines);
    }

    private function toolSearch(array $args): string
    {
        $query = trim(self::strArg($args, 'query', required: true));
        if (mb_strlen($query, 'UTF-8') < 2) {
            return 'Query must be at least 2 characters.';
        }
        $limit   = max(1, min(100, (int)($args['limit'] ?? 10)));
        $results = $this->pages->search($query, $limit);

        if (empty($results)) {
            return "No pages found for: {$query}";
        }
        $lines = ["Search results for \"{$query}\" (" . count($results) . "):", ''];
        foreach ($results as $r) {
            $lines[] = "## {$r['page']}" . ($r['name_match'] ? '  (name match)' : '');
            if ($r['snippet'] !== '') {
                $lines[] = '  ' . $r['snippet'];
            }
        }
        return implode("\n", $lines);
    }

    private function toolWritePage(array $args): string
    {
        $page      = self::strArg($args, 'page', required: true);
        $base_sha1 = trim(self::strArg($args, 'base_sha1', required: true));
        $content   = self::strArg($args, 'content', required: true);

        try {
            $r = $this->pages->write($page, $content, $base_sha1, $this->actor);
        } catch (ApiException $e) {
            return "Write failed ({$e->error_code}): {$e->getMessage()}";
        }

        return implode("\n", [
            $r['is_new'] ? 'Page created successfully.' : 'Page updated successfully.',
            "Page     : {$r['page']}",
            "New SHA1 : {$r['new_sha1']}",
            "Changed  : " . ($r['changed'] ? 'yes' : 'no (content was identical)'),
            "Size     : {$r['size']} bytes",
            '',
            'NOTE: PukiWiki normalized the content (#author line, heading anchors).',
            'Before further edits, call wiki_read_page again and use the new SHA1 as base_sha1.',
        ]);
    }

    private static function strArg(array $args, string $name, bool $required = false): string
    {
        $v = $args[$name] ?? '';
        if (!is_string($v)) {
            throw new \RuntimeException("\"{$name}\" must be a string", self::ERR_PARAMS);
        }
        if ($required && $v === '') {
            throw new \RuntimeException("\"{$name}\" is required", self::ERR_PARAMS);
        }
        return $v;
    }

    // -------------------------------------------------------------------------
    // ツール定義
    // -------------------------------------------------------------------------

    private static function toolDefinitions(): array
    {
        return [
            [
                'name'        => 'wiki_read_page',
                'description' => 'Read a PukiWiki page with its metadata (SHA1, frozen state). '
                    . 'Always call this before editing to get the current SHA1.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'page' => ['type' => 'string', 'description' => 'Page name, e.g. "FrontPage" or "親/子"'],
                    ],
                    'required'   => ['page'],
                ],
            ],
            [
                'name'        => 'wiki_list_pages',
                'description' => 'List PukiWiki pages (sorted by name).',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'limit'  => ['type' => 'integer', 'default' => 100],
                        'offset' => ['type' => 'integer', 'default' => 0],
                    ],
                    'required'   => [],
                ],
            ],
            [
                'name'        => 'wiki_search',
                'description' => 'Full-text search across all pages (case-insensitive substring, '
                    . 'Japanese/English). Minimum 2 characters.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'query' => ['type' => 'string'],
                        'limit' => ['type' => 'integer', 'default' => 10],
                    ],
                    'required'   => ['query'],
                ],
            ],
            [
                'name'        => 'wiki_write_page',
                'description' => implode(' ', [
                    'Write the full content of a PukiWiki page (create or update).',
                    'REQUIRED WORKFLOW: 1) call wiki_read_page to get the current SHA1,',
                    '2) pass it as base_sha1. If the page changed since your read, the write',
                    'is rejected (conflict) — re-read and retry.',
                    'For NEW pages use base_sha1=' . PageStore::EMPTY_SHA1 . '.',
                    'Frozen/protected/system pages and empty content are rejected.',
                    'Every write is snapshotted and audit-logged.',
                ]),
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'page'      => ['type' => 'string', 'description' => 'Target page name'],
                        'base_sha1' => [
                            'type'        => 'string',
                            'description' => '40-char SHA1 from wiki_read_page (optimistic lock)',
                        ],
                        'content'   => [
                            'type'        => 'string',
                            'description' => 'Complete new page content in PukiWiki markup (non-empty)',
                        ],
                    ],
                    'required'   => ['page', 'base_sha1', 'content'],
                ],
            ],
        ];
    }
}
