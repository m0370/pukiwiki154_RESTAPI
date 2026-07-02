#!/usr/bin/env php
<?php
/**
 * PukiWiki REST API v2 — MCP サーバー（stdio トランスポート）
 *
 * Claude Desktop / Claude Code から起動され、stdin/stdout で JSON-RPC 2.0 を話す。
 *
 * 登録例（claude_desktop_config.json）:
 *   {
 *     "mcpServers": {
 *       "pukiwiki": {
 *         "command": "php",
 *         "args": ["/path/to/pukiwiki/rest-api-v2/mcp/server.php"],
 *         "env": {
 *           "PKWK_ROOT":      "/path/to/pukiwiki",
 *           "PKWK_MCP_ACTOR": "claude-desktop"
 *         }
 *       }
 *     }
 *   }
 *
 * このサーバーは wiki_write_page（直接編集）を公開する。
 * 編集させたくないページは PukiWiki の凍結機能で保護すること。
 *
 * License: GPL v2 or (at your option) any later version（PukiWiki 1.5.4 本体に準拠）
 * @version v2.0
 */

if (php_sapi_name() !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/McpHandler.php';

$actor   = (string)(getenv('PKWK_MCP_ACTOR') ?: 'mcp-client');
$handler = new McpHandler($REST_PAGES, $actor);

fwrite(STDERR, "[pukiwiki-mcp] v2 server ready (actor={$actor}, pukiwiki="
    . ($REST_PKWK_LOADED ? 'loaded' : 'standalone') . ")\n");

while (ob_get_level() > 0) {
    ob_end_clean();
}

while (!feof(STDIN)) {
    $line = fgets(STDIN);
    if ($line === false) {
        break;
    }
    $line = trim($line);
    if ($line === '') {
        continue;
    }

    $message = json_decode($line, true);
    if (!is_array($message)) {
        $resp = [
            'jsonrpc' => '2.0',
            'id'      => null,
            'error'   => ['code' => -32700, 'message' => 'Parse error'],
        ];
    } else {
        $resp = $handler->handle($message);
    }

    if ($resp !== null) {
        fwrite(STDOUT, json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        fflush(STDOUT);
    }
}

fwrite(STDERR, "[pukiwiki-mcp] server stopped\n");
