/**
 * PukiWiki REST API v2 のモック（node:http）。
 *
 * 実サーバー（rest-api-v2/api/v1/index.php）のレスポンス形式・エラーコード・
 * ルーティング順（revisions 一覧 → revision 取得 → ページ取得/PUT）を再現する。
 * 書き込み時は PukiWiki の本文正規化（#author 行付与）を模倣し、
 * new_sha1 が送信内容の sha1 と異なる状況を作る。
 */

import { createServer } from 'node:http';
import { createHash } from 'node:crypto';

export const READ_KEY = 'pkw2_mock_read_key';
export const WRITE_KEY = 'pkw2_mock_write_key';

const sha1 = (s) => createHash('sha1').update(s, 'utf8').digest('hex');
export const EMPTY_SHA1 = sha1('');

const AUTHOR_LINE = '#author("2026-07-04T00:00:00+00:00";"mock";"mock")\n';

const clamp = (n, min, max) => Math.max(min, Math.min(max, n));

function readBody(req) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    req.on('data', (c) => chunks.push(c));
    req.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
    req.on('error', reject);
  });
}

export function createMockRest() {
  const pages = new Map();     // name -> { content, frozen }
  const revisions = new Map(); // name -> [{ id, ts, time, sha1, gz_size, content }]
  const state = {
    lastPath: null,      // ブリッジが送ったデコード済みパス（エンコード検証用）
    maxBytes: 1024 * 1024,
    revCounter: 0,
  };

  const seed = (name, content, frozen = false) => {
    pages.set(name, { content, frozen });
  };

  const server = createServer(async (req, res) => {
    const json = (status, obj) => {
      res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8' });
      res.end(JSON.stringify(obj));
    };
    const error = (status, code, message) => json(status, { error: { status, code, message } });

    const m = /^Bearer\s+(\S+)$/i.exec(req.headers['authorization'] ?? '');
    if (!m) return error(401, 'missing_token', 'Authorization: Bearer header is required');
    const key = m[1];
    if (key !== READ_KEY && key !== WRITE_KEY) {
      return error(401, 'invalid_token', 'Unknown API key');
    }
    const scope = (key === WRITE_KEY) ? 'write' : 'read';

    const u = new URL(req.url, 'http://localhost');
    const path = decodeURIComponent(u.pathname);
    state.lastPath = path;

    // GET /pages（一覧）
    if (path === '/pages' && req.method === 'GET') {
      const limit = clamp(Number(u.searchParams.get('limit') ?? 100) || 100, 1, 1000);
      const offset = Math.max(0, Number(u.searchParams.get('offset') ?? 0) || 0);
      const names = [...pages.keys()].sort();
      const slice = names.slice(offset, offset + limit);
      return json(200, {
        pages: slice.map((n) => ({ name: n, mtime: 1782946039, updated_at: '2026-07-01T22:47:19+00:00' })),
        count: slice.length,
        total: names.length,
        limit,
        offset,
      });
    }

    // GET /search
    if (path === '/search' && req.method === 'GET') {
      const q = u.searchParams.get('q') ?? '';
      if (q === '') return error(400, 'missing_query', 'q is required');
      if ([...q].length < 2) return error(400, 'query_too_short', 'q must be at least 2 characters');
      const limit = clamp(Number(u.searchParams.get('limit') ?? 20) || 20, 1, 100);
      const results = [];
      for (const [name, p] of [...pages.entries()].sort()) {
        const nameMatch = name.toLowerCase().includes(q.toLowerCase());
        const idx = p.content.toLowerCase().indexOf(q.toLowerCase());
        if (nameMatch || idx >= 0) {
          results.push({
            page: name,
            snippet: idx >= 0 ? p.content.slice(Math.max(0, idx - 10), idx + q.length + 10) : '',
            name_match: nameMatch,
          });
        }
        if (results.length >= limit) break;
      }
      return json(200, { query: q, results, count: results.length });
    }

    let mm;

    // GET /pages/{page}/revisions（一覧）— ページ取得より先にマッチさせる
    if ((mm = /^\/pages\/(.+)\/revisions$/.exec(path)) && req.method === 'GET') {
      const name = mm[1];
      const revs = revisions.get(name) ?? [];
      return json(200, {
        page: name,
        revisions: revs.map(({ content, ...rest }) => rest),
        count: revs.length,
        note: 'These are snapshots taken on API writes.',
      });
    }

    // GET /pages/{page}/revisions/{rev}
    if ((mm = /^\/pages\/(.+)\/revisions\/([^/]+)$/.exec(path)) && req.method === 'GET') {
      const [, name, rev] = mm;
      if (!/^\d+\.\d{6}_[0-9a-f]{40}$/.test(rev)) {
        return error(400, 'invalid_revision_id', 'Malformed revision id');
      }
      const found = (revisions.get(name) ?? []).find((r) => r.id === rev);
      if (!found) return error(404, 'revision_not_found', 'No such revision');
      return json(200, {
        page: name,
        revision: rev,
        sha1: found.sha1,
        content: found.content,
        note: 'To restore this version, PUT it back with the current sha1 as base_sha1.',
      });
    }

    // GET /pages/{page}
    if ((mm = /^\/pages\/(.+)$/.exec(path)) && req.method === 'GET') {
      const name = mm[1];
      if (name.startsWith(':')) return error(403, 'system_page', 'System pages are not accessible');
      const p = pages.get(name);
      if (!p) return error(404, 'page_not_found', `Page not found: ${name}`);
      return json(200, {
        page: name,
        sha1: sha1(p.content),
        content: p.content,
        size: Buffer.byteLength(p.content, 'utf8'),
        mtime: 1782946039,
        updated_at: '2026-07-01T22:47:19+00:00',
        is_frozen: p.frozen,
        is_editable: !p.frozen,
      });
    }

    // PUT /pages/{page}
    if ((mm = /^\/pages\/(.+)$/.exec(path)) && req.method === 'PUT') {
      if (scope !== 'write') {
        return error(403, 'insufficient_scope', 'This endpoint requires write scope');
      }
      const name = mm[1];
      if (name.startsWith(':')) return error(403, 'system_page', 'System pages are not accessible');

      let body;
      try {
        body = JSON.parse(await readBody(req));
      } catch {
        return error(400, 'invalid_json', 'Request body must be a JSON object');
      }
      const base = String(body.base_sha1 ?? '');
      const content = body.content;
      if (base === '') return error(400, 'missing_base_sha1', 'base_sha1 is required');
      if (!/^[0-9a-f]{40}$/.test(base)) return error(400, 'invalid_base_sha1', 'base_sha1 must be 40 hex chars');
      if (typeof content !== 'string' || content === '') {
        return error(400, 'missing_content', 'content is required');
      }
      if (content.trim() === '') return error(400, 'empty_content', 'content must not be empty');
      if (Buffer.byteLength(content, 'utf8') > state.maxBytes) {
        return error(413, 'content_too_large', 'content exceeds limit');
      }

      const p = pages.get(name);
      if (p?.frozen) return error(403, 'page_frozen', 'Page is frozen (#freeze)');
      if (p) {
        const current = sha1(p.content);
        if (base !== current) {
          return error(409, 'sha1_conflict', `Expected sha1=${base}, current sha1=${current}`);
        }
      } else if (base !== EMPTY_SHA1) {
        return error(409, 'page_not_found_as_conflict',
          'Page does not exist; for new pages use the sha1 of an empty string');
      }

      // PukiWiki の本文正規化を模倣（#author 行を先頭に付与）
      const normalized = content.startsWith('#author(')
        ? content
        : AUTHOR_LINE + content;
      const isNew = !p;
      const changed = !p || p.content !== normalized;
      pages.set(name, { content: normalized, frozen: false });

      const newSha = sha1(normalized);
      state.revCounter += 1;
      const id = `${1782946039 + state.revCounter}.${String(state.revCounter).padStart(6, '0')}_${newSha}`;
      const list = revisions.get(name) ?? [];
      list.unshift({
        id,
        ts: 1782946039 + state.revCounter,
        time: '2026-07-01T22:47:19+00:00',
        sha1: newSha,
        gz_size: 100,
        content: normalized,
      });
      revisions.set(name, list);

      return json(isNew ? 201 : 200, {
        page: name,
        is_new: isNew,
        changed,
        new_sha1: newSha,
        size: Buffer.byteLength(normalized, 'utf8'),
        mtime: 1782946039 + state.revCounter,
        snapshot: id,
        note: 'Content may have been normalized by PukiWiki.',
      });
    }

    return error(404, 'not_found', 'No route matched');
  });

  const listen = () => new Promise((resolve) => {
    server.listen(0, '127.0.0.1', () => {
      resolve(`http://127.0.0.1:${server.address().port}`);
    });
  });
  const close = () => new Promise((resolve) => server.close(resolve));

  return { server, pages, revisions, state, seed, listen, close };
}
