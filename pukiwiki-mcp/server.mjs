#!/usr/bin/env node
/**
 * pukiwiki-mcp — PukiWiki REST API v2 を叩く MCP stdio ブリッジ。
 *
 * PukiWiki と同じマシンで動かす必要はない。リモートの PukiWiki に対して
 * REST API（rest-api-v2）経由で読み書きする。認証は 2 スコープ API キー
 * （Authorization: Bearer）で、read キーなら閲覧・検索のみ、write キーなら編集も可。
 *
 * 登録例（claude_desktop_config.json / .mcp.json）:
 *   {
 *     "mcpServers": {
 *       "pukiwiki": {
 *         "command": "node",
 *         "args": ["/path/to/pukiwiki-mcp/server.mjs"],
 *         "env": {
 *           "PUKIWIKI_API_URL": "https://example.com/rest-api-v2/api/v1",
 *           "PUKIWIKI_API_KEY": "pkw2_..."
 *         }
 *       }
 *     }
 *   }
 *
 * License: GPL v2 or (at your option) any later version
 */

import { createInterface } from 'node:readline';
import { RestClient } from './lib/rest-client.mjs';
import { callTool, toolDefinitions } from './lib/tools.mjs';

const PROTOCOL_VERSION = '2024-11-05';
const SERVER_NAME = 'pukiwiki-mcp';
const SERVER_VERSION = '0.1.0';

const ERR_METHOD = -32601;
const ERR_INTERNAL = -32603;
const ERR_PARSE = -32700;

const baseUrl = process.env.PUKIWIKI_API_URL ?? '';
const apiKey = process.env.PUKIWIKI_API_KEY ?? '';
const envTimeout = Number(process.env.PUKIWIKI_TIMEOUT_MS);
const timeoutMs = Number.isFinite(envTimeout) && envTimeout > 0 ? envTimeout : 30000;

if (baseUrl === '' || apiKey === '') {
  process.stderr.write(
    '[pukiwiki-mcp] 環境変数 PUKIWIKI_API_URL と PUKIWIKI_API_KEY を設定してください。\n'
    + '[pukiwiki-mcp] Set PUKIWIKI_API_URL (e.g. https://host/rest-api-v2/api/v1) '
    + 'and PUKIWIKI_API_KEY (pkw2_...).\n',
  );
  process.exit(1);
}

const client = new RestClient({ baseUrl, apiKey, timeoutMs });

async function handle(message) {
  if (!Object.hasOwn(message, 'id')) {
    return null; // Notification には応答しない
  }
  const { id } = message;
  const method = String(message.method ?? '');
  const params = (message.params && typeof message.params === 'object') ? message.params : {};

  try {
    let result;
    switch (method) {
      case 'initialize':
        result = {
          protocolVersion: PROTOCOL_VERSION,
          capabilities: { tools: { listChanged: false } },
          serverInfo: { name: SERVER_NAME, version: SERVER_VERSION },
        };
        break;
      case 'tools/list':
        result = { tools: toolDefinitions() };
        break;
      case 'tools/call':
        result = await callTool(client, String(params.name ?? ''), params.arguments ?? {});
        break;
      case 'ping':
        result = {};
        break;
      default: {
        const err = new Error(`Method not found: ${method}`);
        err.jsonrpcCode = ERR_METHOD;
        throw err;
      }
    }
    return { jsonrpc: '2.0', id, result };
  } catch (e) {
    const code = typeof e?.jsonrpcCode === 'number' ? e.jsonrpcCode : ERR_INTERNAL;
    return {
      jsonrpc: '2.0',
      id,
      error: { code, message: e?.message ?? String(e) },
    };
  }
}

process.stderr.write(`[pukiwiki-mcp] v${SERVER_VERSION} ready (api=${baseUrl})\n`);

const rl = createInterface({ input: process.stdin, terminal: false });

rl.on('line', async (line) => {
  line = line.trim();
  if (line === '') return;

  let resp;
  let message;
  try {
    message = JSON.parse(line);
  } catch {
    message = undefined;
  }

  if (message === undefined || message === null
      || typeof message !== 'object' || Array.isArray(message)) {
    resp = { jsonrpc: '2.0', id: null, error: { code: ERR_PARSE, message: 'Parse error' } };
  } else {
    resp = await handle(message);
  }

  if (resp !== null) {
    process.stdout.write(JSON.stringify(resp) + '\n');
  }
});

rl.on('close', () => {
  process.stderr.write('[pukiwiki-mcp] server stopped\n');
});
