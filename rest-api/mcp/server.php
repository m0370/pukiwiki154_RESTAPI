#!/usr/bin/env php
<?php
/**
 * PukiWiki REST API — MCP サーバー（stdio トランスポート）
 *
 * Claude Desktop / Claude Code などの MCP クライアントから
 * サブプロセスとして起動され、stdin/stdout で JSON-RPC 2.0 を話す。
 *
 * ──────────────────────────────────────────────
 * Claude Desktop への登録方法（~/Library/Application Support/Claude/claude_desktop_config.json）:
 *
 *   {
 *     "mcpServers": {
 *       "pukiwiki": {
 *         "command": "php",
 *         "args": ["/path/to/rest-api/mcp/server.php"],
 *         "env": {
 *           "PKWK_ROOT":      "/path/to/pukiwiki_root",
 *           "PKWK_MCP_ACTOR": "claude-desktop"
 *         }
 *       }
 *     }
 *   }
 *
 * 環境変数:
 *   PKWK_ROOT      PukiWiki のルートディレクトリ（省略時は rest-api/ の親）
 *   PKWK_MCP_ACTOR 監査ログに記録されるアクター名（省略時: mcp-client）
 * ──────────────────────────────────────────────
 *
 * セキュリティ上の注意:
 *   このサーバーは page:write スコープを持たない。
 *   AI は読み取りと下書き作成のみ実行でき、本番ページを直接書き換えられない。
 * @version v0.1
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    exit(1);
}

// rest-api/ を起点にする（bootstrap.php が __DIR__ を使って相対パスを解決する）
chdir(dirname(__DIR__));

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/PageReader.php';
require_once __DIR__ . '/McpHandler.php';

$actor   = (string)(getenv('PKWK_MCP_ACTOR') ?: 'mcp-client');
$reader  = new PageReader($REST_RECONCILER, $REST_LEDGER, $REST_WIKI_DIR);
$handler = new McpHandler($reader, $REST_LEDGER, $actor);

// stderr にのみデバッグ情報を書く（stdout は JSON-RPC 専用）
fwrite(STDERR, "[pukiwiki-mcp] server ready (actor={$actor})\n");

// 出力バッファリングを無効化（レスポンスが即座に届くように）
while (ob_get_level() > 0) {
    ob_end_clean();
}

// stdin から1行ずつ JSON-RPC メッセージを読む
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
    if ($message === null) {
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
