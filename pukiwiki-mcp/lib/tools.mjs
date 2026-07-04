/**
 * MCP ツール定義とハンドラ。
 *
 * 既存 4 ツール（wiki_read_page / wiki_list_pages / wiki_search / wiki_write_page）は
 * PHP 版 rest-api-v2/mcp/McpHandler.php と name / inputSchema / 出力テキスト形式の互換を保つ。
 * 追加 2 ツール（wiki_page_revisions / wiki_read_revision）は REST のスナップショット API を公開する。
 *
 * PHP 版との差異: エラー時は isError: true を付ける（クライアントが失敗を機械判別できる上位互換）。
 *
 * License: GPL v2 or (at your option) any later version
 */

import { RestError } from './rest-client.mjs';

/** sha1('') — 新規ページ作成時の base_sha1 */
export const EMPTY_SHA1 = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';

/** JSON-RPC レベルのエラー（ツール content ではなく error オブジェクトで返すもの） */
export class JsonRpcError extends Error {
  constructor(code, message) {
    super(message);
    this.name = 'JsonRpcError';
    this.jsonrpcCode = code;
  }
}

const ERR_PARAMS = -32602;

function strArg(args, name, required = false) {
  const v = args[name] ?? '';
  if (typeof v !== 'string') {
    throw new JsonRpcError(ERR_PARAMS, `"${name}" must be a string`);
  }
  if (required && v === '') {
    throw new JsonRpcError(ERR_PARAMS, `"${name}" is required`);
  }
  return v;
}

function intArg(args, name, def) {
  const v = args[name];
  if (v === undefined || v === null) return def;
  const n = Number(v);
  return Number.isFinite(n) ? Math.trunc(n) : def;
}

const clamp = (n, min, max) => Math.max(min, Math.min(max, n));

/** REST のエラーコード → 次の一手の案内文 */
const ERROR_HINTS = {
  sha1_conflict:
    'The page was modified since your last read. Call wiki_read_page again and retry with the new SHA1.',
  page_not_found_as_conflict:
    `The page does not exist. To create a new page, use base_sha1=${EMPTY_SHA1}.`,
  page_frozen:
    'This page is frozen (#freeze) and cannot be edited via the API. Unfreeze it in the PukiWiki web UI first.',
  page_protected:
    'This page is protected by server configuration (PKWK_PROTECTED_PAGES) and cannot be edited via the API.',
  system_page:
    'System pages (names starting with ":") are not accessible via the API.',
  wiki_readonly:
    'The wiki is in read-only mode (PKWK_READONLY).',
  page_locked:
    'The page is temporarily locked by another writer. Wait a moment and retry.',
  insufficient_scope:
    'Your API key has "read" scope only. Writing requires a key issued with --scope write.',
  missing_token:
    'No API key was sent. Check the PUKIWIKI_API_KEY environment variable.',
  invalid_token:
    'The API key was rejected. Check PUKIWIKI_API_KEY (the raw pkw2_... key shown once at creation).',
  expired_token:
    'The API key has expired. Ask the wiki administrator to issue a new one.',
  ip_not_allowed:
    'The API key has an IP restriction that does not match your address.',
  content_too_large:
    'The page content exceeds the server-side size limit.',
  payload_too_large:
    'The request body exceeds the server-side size limit.',
};

function errorText(prefix, e) {
  if (!(e instanceof RestError)) throw e;
  const hint = ERROR_HINTS[e.code];
  return `${prefix} (${e.code}): ${e.message}` + (hint ? `\n${hint}` : '');
}

const tri = (v) => (v === null || v === undefined) ? 'unknown' : (v ? 'yes' : 'no');

// ---------------------------------------------------------------------------
// ツール実装（{ text, isError? } を返す）
// ---------------------------------------------------------------------------

async function toolReadPage(client, args) {
  const page = strArg(args, 'page', true);
  let d;
  try {
    d = await client.readPage(page);
  } catch (e) {
    if (e instanceof RestError && e.status === 404) {
      return {
        text: `Page '${page}' does not exist.\n`
          + `To create it, call wiki_write_page with base_sha1=${EMPTY_SHA1} (sha1 of empty string).`,
      };
    }
    return { text: errorText('Read failed', e), isError: true };
  }

  return {
    text: [
      `# Page: ${d.page}`,
      `SHA1: ${d.sha1}`,
      `Updated: ${d.updated_at}  |  Size: ${d.size} bytes`,
      `Frozen: ${tri(d.is_frozen)}  |  Editable: ${tri(d.is_editable)}`,
      '',
      'To edit this page, pass the SHA1 above as base_sha1 to wiki_write_page.',
      '--- content ---',
      d.content,
    ].join('\n'),
  };
}

async function toolListPages(client, args) {
  const limit = clamp(intArg(args, 'limit', 100), 1, 1000);
  const offset = Math.max(0, intArg(args, 'offset', 0));

  let r;
  try {
    r = await client.listPages(limit, offset);
  } catch (e) {
    return { text: errorText('List failed', e), isError: true };
  }

  const pages = r.pages ?? [];
  if (pages.length === 0) {
    return { text: 'No pages found.' };
  }
  const lines = [`Pages (${pages.length} of ${r.total}):`, ''];
  for (const p of pages) {
    lines.push(`- ${p.name} (${p.updated_at})`);
  }
  return { text: lines.join('\n') };
}

async function toolSearch(client, args) {
  const query = strArg(args, 'query', true).trim();
  if ([...query].length < 2) {
    return { text: 'Query must be at least 2 characters.' };
  }
  const limit = clamp(intArg(args, 'limit', 10), 1, 100);

  let r;
  try {
    r = await client.search(query, limit);
  } catch (e) {
    return { text: errorText('Search failed', e), isError: true };
  }

  const results = r.results ?? [];
  if (results.length === 0) {
    return { text: `No pages found for: ${query}` };
  }
  const lines = [`Search results for "${query}" (${results.length}):`, ''];
  for (const item of results) {
    lines.push(`## ${item.page}` + (item.name_match ? '  (name match)' : ''));
    if (item.snippet !== '') {
      lines.push('  ' + item.snippet);
    }
  }
  return { text: lines.join('\n') };
}

async function toolWritePage(client, args) {
  const page = strArg(args, 'page', true);
  const base_sha1 = strArg(args, 'base_sha1', true).trim();
  const content = strArg(args, 'content', true);

  let r;
  try {
    r = await client.writePage(page, base_sha1, content);
  } catch (e) {
    return { text: errorText('Write failed', e), isError: true };
  }

  return {
    text: [
      r.is_new ? 'Page created successfully.' : 'Page updated successfully.',
      `Page     : ${r.page}`,
      `New SHA1 : ${r.new_sha1}`,
      `Changed  : ` + (r.changed ? 'yes' : 'no (content was identical)'),
      `Size     : ${r.size} bytes`,
      '',
      'NOTE: PukiWiki normalized the content (#author line, heading anchors).',
      'Before further edits, call wiki_read_page again and use the new SHA1 as base_sha1.',
    ].join('\n'),
  };
}

async function toolPageRevisions(client, args) {
  const page = strArg(args, 'page', true);

  let r;
  try {
    r = await client.pageRevisions(page);
  } catch (e) {
    return { text: errorText('Revisions lookup failed', e), isError: true };
  }

  const revs = r.revisions ?? [];
  if (revs.length === 0) {
    return {
      text: `No snapshots recorded for '${page}'.\n`
        + 'Snapshots are taken automatically on every API write (not on web UI edits).',
    };
  }
  const lines = [`Snapshots for '${page}' (${revs.length}, newest first):`, ''];
  for (const rev of revs) {
    lines.push(`- ${rev.id}  (${rev.time})`);
  }
  lines.push('', 'Use wiki_read_revision with one of these ids to view a past version.');
  return { text: lines.join('\n') };
}

async function toolReadRevision(client, args) {
  const page = strArg(args, 'page', true);
  const revision = strArg(args, 'revision', true).trim();

  let r;
  try {
    r = await client.readRevision(page, revision);
  } catch (e) {
    return { text: errorText('Revision read failed', e), isError: true };
  }

  return {
    text: [
      `# Page: ${r.page}`,
      `Revision: ${r.revision}`,
      `SHA1: ${r.sha1}`,
      '',
      'To restore this version: call wiki_read_page to get the CURRENT SHA1,',
      'then wiki_write_page with that SHA1 as base_sha1 and this content.',
      '--- content ---',
      r.content,
    ].join('\n'),
  };
}

// ---------------------------------------------------------------------------
// ディスパッチとツール定義
// ---------------------------------------------------------------------------

const HANDLERS = {
  wiki_read_page: toolReadPage,
  wiki_list_pages: toolListPages,
  wiki_search: toolSearch,
  wiki_write_page: toolWritePage,
  wiki_page_revisions: toolPageRevisions,
  wiki_read_revision: toolReadRevision,
};

export async function callTool(client, name, args) {
  const handler = HANDLERS[name];
  if (!handler) {
    throw new JsonRpcError(ERR_PARAMS, `Unknown tool: ${name}`);
  }
  const { text, isError } = await handler(client, args);
  const result = { content: [{ type: 'text', text }] };
  if (isError) {
    result.isError = true;
  }
  return result;
}

export function toolDefinitions() {
  return [
    {
      name: 'wiki_read_page',
      description: 'Read a PukiWiki page with its metadata (SHA1, frozen state). '
        + 'Always call this before editing to get the current SHA1.',
      inputSchema: {
        type: 'object',
        properties: {
          page: { type: 'string', description: 'Page name, e.g. "FrontPage" or "親/子"' },
        },
        required: ['page'],
      },
    },
    {
      name: 'wiki_list_pages',
      description: 'List PukiWiki pages (sorted by name).',
      inputSchema: {
        type: 'object',
        properties: {
          limit: { type: 'integer', default: 100 },
          offset: { type: 'integer', default: 0 },
        },
        required: [],
      },
    },
    {
      name: 'wiki_search',
      description: 'Full-text search across all pages (case-insensitive substring, '
        + 'Japanese/English). Minimum 2 characters.',
      inputSchema: {
        type: 'object',
        properties: {
          query: { type: 'string' },
          limit: { type: 'integer', default: 10 },
        },
        required: ['query'],
      },
    },
    {
      name: 'wiki_write_page',
      description: [
        'Write the full content of a PukiWiki page (create or update).',
        'REQUIRED WORKFLOW: 1) call wiki_read_page to get the current SHA1,',
        '2) pass it as base_sha1. If the page changed since your read, the write',
        'is rejected (conflict) — re-read and retry.',
        `For NEW pages use base_sha1=${EMPTY_SHA1}.`,
        'Frozen/protected/system pages and empty content are rejected.',
        'Every write is snapshotted and audit-logged.',
      ].join(' '),
      inputSchema: {
        type: 'object',
        properties: {
          page: { type: 'string', description: 'Target page name' },
          base_sha1: {
            type: 'string',
            description: '40-char SHA1 from wiki_read_page (optimistic lock)',
          },
          content: {
            type: 'string',
            description: 'Complete new page content in PukiWiki markup (non-empty)',
          },
        },
        required: ['page', 'base_sha1', 'content'],
      },
    },
    {
      name: 'wiki_page_revisions',
      description: 'List snapshot revisions of a page (newest first). Snapshots are taken '
        + 'automatically on every API write and are independent from PukiWiki standard backups.',
      inputSchema: {
        type: 'object',
        properties: {
          page: { type: 'string', description: 'Page name' },
        },
        required: ['page'],
      },
    },
    {
      name: 'wiki_read_revision',
      description: 'Read the full content of a past snapshot revision (id from '
        + 'wiki_page_revisions). To restore it, write it back with wiki_write_page '
        + 'using the CURRENT page SHA1 as base_sha1.',
      inputSchema: {
        type: 'object',
        properties: {
          page: { type: 'string', description: 'Page name' },
          revision: { type: 'string', description: 'Revision id, e.g. "1782946039.123456_<sha1>"' },
        },
        required: ['page', 'revision'],
      },
    },
  ];
}
