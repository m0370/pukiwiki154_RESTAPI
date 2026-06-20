<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/Ledger.php';
require_once __DIR__ . '/../lib/PageReader.php';
require_once __DIR__ . '/../lib/ApiException.php';
require_once __DIR__ . '/../lib/BlockSplitter.php';
require_once __DIR__ . '/../lib/BlockEditor.php';

/**
 * MCP (Model Context Protocol) JSON-RPC 2.0 ハンドラ。
 *
 * 公開するツールは読み取りと下書き作成のみ。
 * ページへの直接書き込み（page:write）は一切出さない。
 * これが「AI は補助・投稿者 / 人間が承認者」という設計の実装上の保証。
 *
 * プロトコルバージョン: 2024-11-05
 *
 * ツール一覧:
 *   wiki_read_page    ページの内容とメタを取得
 *   wiki_search       全文検索（日本語/英語対応 trigram）
 *   wiki_list_pages   ページ一覧
 *   wiki_create_draft 下書き提案を作成（人間の承認が必要）
 *   wiki_get_draft    下書きのステータス確認
 *
 * リソース:
 *   wiki://pages/{page}  ページ本文を MCP リソースとして提供
 * @version v0.1
 */
final class McpHandler
{
    private const PROTOCOL_VERSION = '2024-11-05';
    private const SERVER_NAME      = 'pukiwiki-rest-api';
    private const SERVER_VERSION   = '0.1.0';

    // JSON-RPC エラーコード（仕様準拠）
    private const ERR_PARSE         = -32700;
    private const ERR_INVALID_REQ   = -32600;
    private const ERR_METHOD        = -32601;
    private const ERR_PARAMS        = -32602;
    private const ERR_INTERNAL      = -32603;

    public function __construct(
        private PageReader $reader,
        private Ledger     $ledger,
        private string     $actor = 'mcp-client',
    ) {}

    /**
     * JSON-RPC メッセージを処理してレスポンスを返す。
     * Notification（id なし）の場合は null を返す（レスポンス不要）。
     */
    public function handle(array $message): ?array
    {
        $id     = $message['id'] ?? null;
        $method = (string)($message['method'] ?? '');
        $params = (array)($message['params'] ?? []);

        // Notification はレスポンスを返さない
        if (!array_key_exists('id', $message)) {
            return null;
        }

        try {
            $result = match ($method) {
                'initialize'              => $this->handleInitialize($params),
                'tools/list'              => $this->handleToolsList(),
                'tools/call'              => $this->handleToolsCall($params),
                'resources/list'          => $this->handleResourcesList(),
                'resources/read'          => $this->handleResourcesRead($params),
                'ping'                    => [], // 疎通確認
                default                   => throw new \RuntimeException(
                    "Method not found: {$method}", self::ERR_METHOD
                ),
            };
            return $this->success($id, $result);
        } catch (\Throwable $e) {
            $code = ($e->getCode() < 0) ? $e->getCode() : self::ERR_INTERNAL;
            return $this->error($id, $code, $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // プロトコルメソッド
    // -------------------------------------------------------------------------

    private function handleInitialize(array $params): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities'    => [
                'tools'     => ['listChanged' => false],
                'resources' => ['listChanged' => false, 'subscribe' => false],
            ],
            'serverInfo' => [
                'name'    => self::SERVER_NAME,
                'version' => self::SERVER_VERSION,
            ],
        ];
    }

    private function handleToolsList(): array
    {
        return ['tools' => self::toolDefinitions()];
    }

    private function handleToolsCall(array $params): array
    {
        $name = (string)($params['name'] ?? '');
        $args = (array)($params['arguments'] ?? []);

        $text = match ($name) {
            'wiki_read_page'    => $this->toolReadPage($args),
            'wiki_search'       => $this->toolSearch($args),
            'wiki_list_pages'   => $this->toolListPages($args),
            'wiki_create_draft' => $this->toolCreateDraft($args),
            'wiki_get_draft'    => $this->toolGetDraft($args),
            'wiki_read_blocks'  => $this->toolReadBlocks($args),
            'wiki_patch_blocks' => $this->toolPatchBlocks($args),
            default             => throw new \RuntimeException(
                "Unknown tool: {$name}", self::ERR_PARAMS
            ),
        };

        return [
            'content' => [
                ['type' => 'text', 'text' => $text],
            ],
        ];
    }

    private function handleResourcesList(): array
    {
        $pages     = $this->reader->listPages(500, 0);
        $resources = array_map(static fn($p) => [
            'uri'      => 'wiki://pages/' . rawurlencode($p['name']),
            'name'     => $p['name'],
            'mimeType' => 'text/plain',
        ], $pages);
        return ['resources' => $resources];
    }

    private function handleResourcesRead(array $params): array
    {
        $uri = (string)($params['uri'] ?? '');
        if (!str_starts_with($uri, 'wiki://pages/')) {
            throw new \RuntimeException("Unsupported URI: {$uri}", self::ERR_PARAMS);
        }
        $page = urldecode(substr($uri, strlen('wiki://pages/')));
        try {
            $data = $this->reader->read($page);
        } catch (ApiException $e) {
            throw new \RuntimeException($e->getMessage(), self::ERR_PARAMS);
        }
        return [
            'contents' => [
                [
                    'uri'      => $uri,
                    'mimeType' => 'text/plain',
                    'text'     => $data['content'],
                ],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // ツール実装
    // -------------------------------------------------------------------------

    private function toolReadPage(array $args): string
    {
        $page = trim((string)($args['page'] ?? ''));
        if ($page === '') {
            throw new \InvalidArgumentException('"page" is required');
        }

        try {
            $data = $this->reader->read($page);
        } catch (ApiException $e) {
            if ($e->status === 404) {
                return "Page '{$page}' does not exist.";
            }
            throw $e;
        }

        $frozen   = $data['is_frozen']   === null ? 'unknown' : ($data['is_frozen']   ? 'yes' : 'no');
        $editable = $data['is_editable'] === null ? 'unknown' : ($data['is_editable'] ? 'yes' : 'no');

        return implode("\n", [
            "# Page: {$data['page']}",
            "Rev: {$data['rev']}  |  SHA1: {$data['sha1']}",
            "Updated: {$data['updated_at']}  |  Size: {$data['size']} bytes",
            "Frozen: {$frozen}  |  Editable: {$editable}",
            "Status: {$data['status']}",
            '',
            $data['content'],
        ]);
    }

    private function toolSearch(array $args): string
    {
        $query = trim((string)($args['query'] ?? ''));
        if ($query === '') {
            throw new \InvalidArgumentException('"query" is required');
        }
        if (mb_strlen($query, 'UTF-8') < 3) {
            return "Query must be at least 3 characters (trigram tokenizer minimum).";
        }
        $limit   = max(1, min(100, (int)($args['limit'] ?? 10)));
        $results = $this->ledger->search($query, $limit);

        if (empty($results)) {
            return "No pages found for: {$query}";
        }

        $lines = ["Search results for \"{$query}\" (" . count($results) . " found):", ''];
        foreach ($results as $r) {
            $lines[] = "## {$r['name']}";
            $lines[] = strip_tags((string)($r['excerpt'] ?? ''));
            $lines[] = '';
        }
        return implode("\n", $lines);
    }

    private function toolListPages(array $args): string
    {
        $limit  = max(1, min(1000, (int)($args['limit'] ?? 100)));
        $offset = max(0, (int)($args['offset'] ?? 0));
        $pages  = $this->reader->listPages($limit, $offset);

        if (empty($pages)) {
            return "No pages found.";
        }

        $lines = ["Pages (" . count($pages) . "):", ''];
        foreach ($pages as $p) {
            $dt     = $p['updated_at'] > 0 ? date('Y-m-d', (int)$p['updated_at']) : '—';
            $lines[] = "- {$p['name']} ({$dt})";
        }
        return implode("\n", $lines);
    }

    private function toolCreateDraft(array $args): string
    {
        $page      = trim((string)($args['page']      ?? ''));
        $base_sha1 = trim((string)($args['base_sha1'] ?? ''));
        $body      = (string)($args['body'] ?? '');
        $meta      = (array)($args['meta']  ?? []);

        if ($page === '' || $base_sha1 === '' || $body === '') {
            throw new \InvalidArgumentException('"page", "base_sha1", and "body" are all required');
        }
        if (!preg_match('/^[0-9a-f]{40}$/i', $base_sha1)) {
            throw new \InvalidArgumentException('"base_sha1" must be a 40-character hex SHA1');
        }

        $now      = time();
        $expires  = $now + 7 * 24 * 3600; // 7日後に自動失効
        $draft_id = $this->ledger->createDraft(
            $page, $base_sha1, $body,
            $this->actor, $now, $expires,
            array_merge(['source' => 'mcp'], $meta)
        );

        return implode("\n", [
            "Draft created successfully.",
            "Draft ID : {$draft_id}",
            "Page     : {$page}",
            "Base SHA1: {$base_sha1}",
            "Status   : open (pending human review)",
            "Expires  : " . date('Y-m-d H:i:s', $expires),
            '',
            "IMPORTANT: This draft will NOT be published until a human approves it.",
            "The reviewer can compare your draft with the current page and decide.",
        ]);
    }

    private function toolGetDraft(array $args): string
    {
        $id = (int)($args['draft_id'] ?? 0);
        if ($id <= 0) {
            throw new \InvalidArgumentException('"draft_id" must be a positive integer');
        }
        $draft = $this->ledger->getDraft($id);
        if ($draft === null) {
            return "Draft #{$id} not found.";
        }

        $expires = $draft['expires_at']
            ? date('Y-m-d H:i:s', (int)$draft['expires_at'])
            : 'never';

        return implode("\n", [
            "Draft #{$id}",
            "Page    : {$draft['page']}",
            "Status  : {$draft['status']}",
            "Owner   : {$draft['owner']}",
            "Base SHA1: {$draft['base_sha1']}",
            "Created : " . date('Y-m-d H:i:s', (int)$draft['created_at']),
            "Updated : " . date('Y-m-d H:i:s', (int)$draft['updated_at']),
            "Expires : {$expires}",
        ]);
    }

    private function toolReadBlocks(array $args): string
    {
        $page = trim((string)($args['page'] ?? ''));
        if ($page === '') {
            throw new \InvalidArgumentException('"page" is required');
        }

        try {
            $data = $this->reader->read($page);
        } catch (ApiException $e) {
            if ($e->status === 404) {
                return "Page '{$page}' does not exist.";
            }
            throw $e;
        }

        $blocks = BlockSplitter::split($data['content']);
        $lines  = [
            "# Blocks: {$data['page']}",
            "Page SHA1 : {$data['sha1']}  |  Rev: {$data['rev']}  |  Blocks: " . count($blocks),
            '',
            'Use wiki_patch_blocks with these block_sha1 values to propose changes.',
            '',
        ];
        foreach ($blocks as $i => $block) {
            if ($block['type'] === 'empty') {
                $lines[] = "[{$i}] type=empty  sha1={$block['block_sha1']}  (blank line)";
            } else {
                $preview = mb_substr(str_replace("\n", '↵', $block['content']), 0, 70, 'UTF-8');
                if (mb_strlen($block['content'], 'UTF-8') > 70) {
                    $preview .= '…';
                }
                $lines[] = "[{$i}] type={$block['type']}  sha1={$block['block_sha1']}";
                $lines[] = "    {$preview}";
            }
        }
        return implode("\n", $lines);
    }

    private function toolPatchBlocks(array $args): string
    {
        $page      = trim((string)($args['page']      ?? ''));
        $base_sha1 = trim((string)($args['base_sha1'] ?? ''));
        $patches   = (array)($args['patches']  ?? []);
        $meta      = (array)($args['meta']     ?? []);

        if ($page === '' || $base_sha1 === '' || empty($patches)) {
            throw new \InvalidArgumentException('"page", "base_sha1", and "patches" are all required');
        }
        if (!preg_match('/^[0-9a-f]{40}$/i', $base_sha1)) {
            throw new \InvalidArgumentException('"base_sha1" must be 40 hex chars (from wiki_read_blocks)');
        }

        // 現在のページを取得
        try {
            $data = $this->reader->read($page);
        } catch (ApiException $e) {
            if ($e->status === 404) {
                return "Page '{$page}' does not exist.";
            }
            throw $e;
        }

        // base_sha1 の競合チェック
        if ($data['sha1'] !== $base_sha1) {
            return implode("\n", [
                "Conflict: page '{$page}' has been modified since you read it.",
                "Your base_sha1 : {$base_sha1}",
                "Current sha1   : {$data['sha1']}",
                '',
                "Call wiki_read_blocks again to get the current block sha1s.",
            ]);
        }

        // ブロックパッチを適用
        try {
            $new_content = BlockEditor::apply($data['content'], $patches);
        } catch (ApiException $e) {
            return "Block patch failed: " . $e->getMessage();
        }

        // 下書き作成
        $now      = time();
        $expires  = $now + 7 * 24 * 3600;
        $draft_id = $this->ledger->createDraft(
            $page, $base_sha1, $new_content,
            $this->actor, $now, $expires,
            array_merge(
                ['source' => 'mcp_block_patch', 'patches_count' => count($patches)],
                $meta
            )
        );

        $deleted  = count(array_filter($patches, fn($p) => ($p['new_content'] ?? '') === null || ($p['new_content'] ?? '') === ''));
        $modified = count($patches) - $deleted;

        return implode("\n", [
            "Block patch draft created successfully.",
            "Draft ID       : {$draft_id}",
            "Page           : {$page}",
            "Base SHA1      : {$base_sha1}",
            "Blocks modified: {$modified}",
            "Blocks deleted : {$deleted}",
            "Status         : open (pending human review)",
            "Expires        : " . date('Y-m-d H:i:s', $expires),
            '',
            "IMPORTANT: This draft will NOT be published until a human approves it.",
            "Review URL: /rest-api/api/review.php?id={$draft_id}",
        ]);
    }

    // -------------------------------------------------------------------------
    // ユーティリティ
    // -------------------------------------------------------------------------

    private function success(mixed $id, array $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function error(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => ['code' => $code, 'message' => $message],
        ];
    }

    // -------------------------------------------------------------------------
    // ツール定義（MCP tools/list で返すスキーマ）
    // -------------------------------------------------------------------------

    private static function toolDefinitions(): array
    {
        return [
            [
                'name'        => 'wiki_read_page',
                'description' => 'Read the content and metadata of a PukiWiki page.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'page' => [
                            'type'        => 'string',
                            'description' => 'Page name (e.g. "FrontPage", "Help/Syntax")',
                        ],
                    ],
                    'required' => ['page'],
                ],
            ],
            [
                'name'        => 'wiki_search',
                'description' => 'Full-text search across all PukiWiki pages. Supports Japanese and English. Minimum 3 characters required.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'query' => [
                            'type'        => 'string',
                            'description' => 'Search query (minimum 3 characters)',
                        ],
                        'limit' => [
                            'type'        => 'integer',
                            'description' => 'Maximum results (1-100, default 10)',
                            'default'     => 10,
                            'minimum'     => 1,
                            'maximum'     => 100,
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name'        => 'wiki_list_pages',
                'description' => 'List all PukiWiki pages with their last update times.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'limit'  => ['type' => 'integer', 'description' => 'Max pages to return (default 100)', 'default' => 100],
                        'offset' => ['type' => 'integer', 'description' => 'Pagination offset (default 0)',      'default' => 0],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name'        => 'wiki_create_draft',
                'description' => implode(' ', [
                    'Propose a new version of a PukiWiki page as a draft.',
                    'The draft is NOT published automatically —',
                    'a human reviewer must approve it first.',
                    'Call wiki_read_page first to get the current sha1.',
                ]),
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'page'      => [
                            'type'        => 'string',
                            'description' => 'Target page name',
                        ],
                        'base_sha1' => [
                            'type'        => 'string',
                            'description' => '40-char SHA1 of the page at the time you read it (from wiki_read_page)',
                        ],
                        'body'      => [
                            'type'        => 'string',
                            'description' => 'Complete proposed content in PukiWiki markup',
                        ],
                        'meta'      => [
                            'type'        => 'object',
                            'description' => 'Optional metadata, e.g. {"reason": "fix typo"}',
                        ],
                    ],
                    'required' => ['page', 'base_sha1', 'body'],
                ],
            ],
            [
                'name'        => 'wiki_get_draft',
                'description' => 'Get the current status of a draft you previously created.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'draft_id' => [
                            'type'        => 'integer',
                            'description' => 'Draft ID returned by wiki_create_draft',
                        ],
                    ],
                    'required' => ['draft_id'],
                ],
            ],
            [
                'name'        => 'wiki_read_blocks',
                'description' => implode(' ', [
                    'Read a PukiWiki page split into addressable blocks.',
                    'Each block has a block_sha1 (16-char hex) you can use in wiki_patch_blocks.',
                    'Block types: heading, paragraph, list, table, pre, plugin, hr, empty.',
                    'Headings and empty lines are always standalone blocks.',
                ]),
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'page' => [
                            'type'        => 'string',
                            'description' => 'Page name',
                        ],
                    ],
                    'required' => ['page'],
                ],
            ],
            [
                'name'        => 'wiki_patch_blocks',
                'description' => implode(' ', [
                    'Propose block-level changes to a PukiWiki page as a draft.',
                    'Specify which blocks to replace or delete using block_sha1 from wiki_read_blocks.',
                    'The draft is NOT published until a human approves it.',
                    'Use this for surgical edits to specific sections.',
                    'For whole-page rewrites, use wiki_create_draft instead.',
                ]),
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'page'      => ['type' => 'string', 'description' => 'Target page name'],
                        'base_sha1' => [
                            'type'        => 'string',
                            'description' => '40-char page SHA1 from wiki_read_blocks output',
                        ],
                        'patches'   => [
                            'type'        => 'array',
                            'description' => 'Block patches to apply',
                            'items'       => [
                                'type'       => 'object',
                                'properties' => [
                                    'block_sha1'  => [
                                        'type'        => 'string',
                                        'description' => '16-char block sha1 from wiki_read_blocks',
                                    ],
                                    'new_content' => [
                                        'type'        => ['string', 'null'],
                                        'description' => 'New content for this block, or null to delete it',
                                    ],
                                ],
                                'required' => ['block_sha1', 'new_content'],
                            ],
                        ],
                        'meta' => [
                            'type'        => 'object',
                            'description' => 'Optional metadata, e.g. {"reason": "fix typo in section 2"}',
                        ],
                    ],
                    'required' => ['page', 'base_sha1', 'patches'],
                ],
            ],
        ];
    }
}
