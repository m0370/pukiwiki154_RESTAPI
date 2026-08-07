/**
 * pukiwiki-mcp ブリッジのエンドツーエンドテスト。
 *
 * モック REST サーバー（mock-rest.mjs）を立て、server.mjs を子プロセスとして
 * 起動し、実際に stdio 経由の JSON-RPC で全ツールを検証する。
 *
 * 実行: node --test pukiwiki-mcp/test/
 */

import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { fileURLToPath } from 'node:url';
import { createMockRest, READ_KEY, WRITE_KEY, EMPTY_SHA1 } from './mock-rest.mjs';
import { RestClient } from '../lib/rest-client.mjs';

const SERVER_PATH = fileURLToPath(new URL('../server.mjs', import.meta.url));

/** server.mjs を子プロセスで起動し JSON-RPC を話すヘルパ */
class Bridge {
  constructor(env) {
    const cleanEnv = { ...process.env };
    delete cleanEnv.PUKIWIKI_API_URL;
    delete cleanEnv.PUKIWIKI_API_KEY;
    delete cleanEnv.PUKIWIKI_TIMEOUT_MS;
    this.child = spawn(process.execPath, [SERVER_PATH], {
      env: { ...cleanEnv, ...env },
      stdio: ['pipe', 'pipe', 'pipe'],
    });
    this.nextId = 1;
    this.pending = new Map();
    this.anyWaiters = [];
    this.buf = '';
    this.child.stdout.setEncoding('utf8');
    this.child.stdout.on('data', (d) => {
      this.buf += d;
      let i;
      while ((i = this.buf.indexOf('\n')) >= 0) {
        const line = this.buf.slice(0, i).trim();
        this.buf = this.buf.slice(i + 1);
        if (line === '') continue;
        const msg = JSON.parse(line);
        const waiter = this.pending.get(msg.id);
        if (waiter) {
          this.pending.delete(msg.id);
          waiter(msg);
        } else if (this.anyWaiters.length > 0) {
          this.anyWaiters.shift()(msg);
        }
      }
    });
  }

  request(method, params = {}) {
    const id = this.nextId++;
    this.child.stdin.write(JSON.stringify({ jsonrpc: '2.0', id, method, params }) + '\n');
    return new Promise((resolve, reject) => {
      const t = setTimeout(() => {
        this.pending.delete(id);
        reject(new Error(`timeout waiting for response to ${method}`));
      }, 10000);
      this.pending.set(id, (msg) => {
        clearTimeout(t);
        resolve(msg);
      });
    });
  }

  sendRaw(line) {
    this.child.stdin.write(line + '\n');
  }

  nextMessage() {
    return new Promise((resolve, reject) => {
      const t = setTimeout(() => reject(new Error('timeout waiting for message')), 10000);
      this.anyWaiters.push((msg) => {
        clearTimeout(t);
        resolve(msg);
      });
    });
  }

  /** tools/call して text を返す。expectError で isError の有無を検証 */
  async call(name, args, { expectError = false } = {}) {
    const resp = await this.request('tools/call', { name, arguments: args });
    assert.equal(resp.error, undefined,
      `unexpected JSON-RPC error: ${JSON.stringify(resp.error)}`);
    assert.equal(!!resp.result.isError, expectError,
      `isError mismatch for ${name}: ${resp.result.content?.[0]?.text}`);
    return resp.result.content[0].text;
  }

  kill() {
    this.child.kill();
  }
}

let mock;
let baseUrl;
let bridge;      // write キーで接続
let readBridge;  // read キーで接続

before(async () => {
  mock = createMockRest();
  baseUrl = await mock.listen();
  mock.seed('FrontPage', 'ようこそ FrontPage へ');
  mock.seed('メモ/既存', '既存メモの本文です カルボプラチン について');
  mock.seed('凍結ページ', '凍結された本文', true);

  bridge = new Bridge({ PUKIWIKI_API_URL: baseUrl, PUKIWIKI_API_KEY: WRITE_KEY });
  readBridge = new Bridge({ PUKIWIKI_API_URL: baseUrl, PUKIWIKI_API_KEY: READ_KEY });
});

after(async () => {
  bridge?.kill();
  readBridge?.kill();
  await mock?.close();
});

// ---------------------------------------------------------------------------
// プロトコル層
// ---------------------------------------------------------------------------

test('initialize がプロトコル版と serverInfo を返す', async () => {
  const resp = await bridge.request('initialize', {
    protocolVersion: '2024-11-05',
    capabilities: {},
    clientInfo: { name: 'test', version: '0' },
  });
  assert.equal(resp.result.protocolVersion, '2024-11-05');
  assert.equal(resp.result.serverInfo.name, 'pukiwiki-mcp');
});

test('tools/list が 6 ツールを返す', async () => {
  const resp = await bridge.request('tools/list');
  const names = resp.result.tools.map((t) => t.name).sort();
  assert.deepEqual(names, [
    'wiki_list_pages',
    'wiki_page_revisions',
    'wiki_read_page',
    'wiki_read_revision',
    'wiki_search',
    'wiki_write_page',
  ]);
});

test('ping が空オブジェクトを返す', async () => {
  const resp = await bridge.request('ping');
  assert.deepEqual(resp.result, {});
});

test('未知メソッドは -32601', async () => {
  const resp = await bridge.request('no/such/method');
  assert.equal(resp.error.code, -32601);
});

test('未知ツールは -32602', async () => {
  const resp = await bridge.request('tools/call', { name: 'no_such_tool', arguments: {} });
  assert.equal(resp.error.code, -32602);
});

test('引数の型不正は -32602', async () => {
  const resp = await bridge.request('tools/call', {
    name: 'wiki_read_page',
    arguments: { page: 123 },
  });
  assert.equal(resp.error.code, -32602);
});

test('JSON でない行には Parse error (-32700)', async () => {
  bridge.sendRaw('this is not json');
  const msg = await bridge.nextMessage();
  assert.equal(msg.error.code, -32700);
});

test('PUKIWIKI_API_URL 未設定なら exit 1', async () => {
  const b = new Bridge({});
  const [code] = await once(b.child, 'exit');
  assert.equal(code, 1);
});

// ---------------------------------------------------------------------------
// 読み取り系ツール
// ---------------------------------------------------------------------------

test('wiki_read_page が本文とメタデータを返す', async () => {
  const text = await bridge.call('wiki_read_page', { page: 'FrontPage' });
  assert.match(text, /^# Page: FrontPage$/m);
  assert.match(text, /^SHA1: [0-9a-f]{40}$/m);
  assert.match(text, /Frozen: no {2}\| {2}Editable: yes/);
  assert.match(text, /ようこそ FrontPage へ/);
});

test('wiki_read_page: 存在しないページは EMPTY_SHA1 での作成を案内（isError なし）', async () => {
  const text = await bridge.call('wiki_read_page', { page: '存在しないページ' });
  assert.match(text, /does not exist/);
  assert.ok(text.includes(EMPTY_SHA1));
});

test('wiki_list_pages が一覧を返す', async () => {
  const text = await bridge.call('wiki_list_pages', {});
  assert.match(text, /^Pages \(\d+ of \d+\):/);
  assert.match(text, /- FrontPage /);
  assert.match(text, /- メモ\/既存 /);
});

test('wiki_search が日本語で全文検索できる', async () => {
  const text = await bridge.call('wiki_search', { query: 'カルボプラチン' });
  assert.match(text, /^Search results for "カルボプラチン"/);
  assert.match(text, /## メモ\/既存/);
});

test('wiki_search: 1 文字クエリは案内を返す', async () => {
  const text = await bridge.call('wiki_search', { query: 'あ' });
  assert.equal(text, 'Query must be at least 2 characters.');
});

// ---------------------------------------------------------------------------
// 書き込みフロー（作成 → 更新 → 競合）
// ---------------------------------------------------------------------------

test('新規作成 → 正規化された new_sha1 で更新 → 古い sha1 は 409', async () => {
  // 1) EMPTY_SHA1 で新規作成
  const created = await bridge.call('wiki_write_page', {
    page: '講演/2026年',
    base_sha1: EMPTY_SHA1,
    content: '* 講演メモ\n本文です。',
  });
  assert.match(created, /^Page created successfully\./);
  const newSha1 = created.match(/^New SHA1 : ([0-9a-f]{40})$/m)[1];

  // モックが受け取ったパスはデコード済みの階層ページ名（エンコード検証）
  assert.equal(mock.state.lastPath, '/pages/講演/2026年');

  // 2) read で正規化後の内容と sha1 が一致することを確認
  const read = await bridge.call('wiki_read_page', { page: '講演/2026年' });
  assert.match(read, new RegExp(`^SHA1: ${newSha1}$`, 'm'));
  assert.match(read, /#author\(/); // 正規化（#author 付与）が反映されている

  // 3) new_sha1 を base に更新成功
  const updated = await bridge.call('wiki_write_page', {
    page: '講演/2026年',
    base_sha1: newSha1,
    content: '* 講演メモ（改訂）\n本文を書き換えました。',
  });
  assert.match(updated, /^Page updated successfully\./);

  // 4) 古い sha1（newSha1）での上書きは 409 sha1_conflict
  const conflict = await bridge.call('wiki_write_page', {
    page: '講演/2026年',
    base_sha1: newSha1,
    content: '古い版に基づく編集',
  }, { expectError: true });
  assert.match(conflict, /Write failed \(sha1_conflict\)/);
  assert.match(conflict, /Call wiki_read_page again/);
});

test('存在しないページに非 EMPTY の base_sha1 は 409 (page_not_found_as_conflict)', async () => {
  const text = await bridge.call('wiki_write_page', {
    page: 'まだ無いページ',
    base_sha1: 'a'.repeat(40),
    content: '本文',
  }, { expectError: true });
  assert.match(text, /page_not_found_as_conflict/);
  assert.ok(text.includes(EMPTY_SHA1)); // 新規作成のヒントが付く
});

test('凍結ページへの書き込みは 403 (page_frozen)', async () => {
  const read = await bridge.call('wiki_read_page', { page: '凍結ページ' });
  assert.match(read, /Frozen: yes/);
  const sha = read.match(/^SHA1: ([0-9a-f]{40})$/m)[1];

  const text = await bridge.call('wiki_write_page', {
    page: '凍結ページ',
    base_sha1: sha,
    content: '書き換え試行',
  }, { expectError: true });
  assert.match(text, /Write failed \(page_frozen\)/);
  assert.match(text, /frozen/i);
});

test('本文サイズ超過は 413 (content_too_large)', async () => {
  const saved = mock.state.maxBytes;
  mock.state.maxBytes = 50;
  try {
    const text = await bridge.call('wiki_write_page', {
      page: 'サイズ超過テスト',
      base_sha1: EMPTY_SHA1,
      content: 'x'.repeat(100),
    }, { expectError: true });
    assert.match(text, /content_too_large/);
  } finally {
    mock.state.maxBytes = saved;
  }
});

// ---------------------------------------------------------------------------
// 認証・スコープ
// ---------------------------------------------------------------------------

test('read キーでも閲覧はできる', async () => {
  const text = await readBridge.call('wiki_read_page', { page: 'FrontPage' });
  assert.match(text, /ようこそ FrontPage へ/);
});

test('read キーでの書き込みは 403 (insufficient_scope)', async () => {
  const text = await readBridge.call('wiki_write_page', {
    page: 'FrontPage',
    base_sha1: 'a'.repeat(40),
    content: '本文',
  }, { expectError: true });
  assert.match(text, /insufficient_scope/);
  assert.match(text, /--scope write/);
});

test('不正キーは 401 (invalid_token)', async () => {
  const b = new Bridge({ PUKIWIKI_API_URL: baseUrl, PUKIWIKI_API_KEY: 'pkw2_wrong' });
  try {
    const text = await b.call('wiki_read_page', { page: 'FrontPage' }, { expectError: true });
    assert.match(text, /invalid_token/);
    assert.match(text, /PUKIWIKI_API_KEY/);
  } finally {
    b.kill();
  }
});

test('到達不能な URL は network_error', async () => {
  const b = new Bridge({
    PUKIWIKI_API_URL: 'http://127.0.0.1:1',
    PUKIWIKI_API_KEY: WRITE_KEY,
  });
  try {
    const text = await b.call('wiki_read_page', { page: 'FrontPage' }, { expectError: true });
    assert.match(text, /network_error/);
  } finally {
    b.kill();
  }
});

// ---------------------------------------------------------------------------
// リビジョン（スナップショット）ツール
// ---------------------------------------------------------------------------

test('wiki_page_revisions と wiki_read_revision で過去版を参照できる', async () => {
  // 上の書き込みテストで '講演/2026年' に 2 スナップショットがあるはず
  const list = await bridge.call('wiki_page_revisions', { page: '講演/2026年' });
  assert.match(list, /^Snapshots for '講演\/2026年' \(2, newest first\):/);
  const revId = list.match(/^- (\S+) {2}\(/m)[1];

  const rev = await bridge.call('wiki_read_revision', { page: '講演/2026年', revision: revId });
  assert.match(rev, new RegExp(`^Revision: ${revId.replace('.', '\\.')}$`, 'm'));
  assert.match(rev, /To restore this version/);
  assert.match(rev, /--- content ---/);
});

test('スナップショットが無いページの wiki_page_revisions は案内を返す', async () => {
  const text = await bridge.call('wiki_page_revisions', { page: 'FrontPage' });
  assert.match(text, /No snapshots recorded/);
});

test('不正なリビジョン ID は invalid_revision_id', async () => {
  const text = await bridge.call('wiki_read_revision', {
    page: '講演/2026年',
    revision: 'bogus-id',
  }, { expectError: true });
  assert.match(text, /invalid_revision_id/);
});

// ---------------------------------------------------------------------------
// ユニット: パスエンコード
// ---------------------------------------------------------------------------

test('encodePagePath: 各セグメントをエンコードし / は温存', () => {
  assert.equal(RestClient.encodePagePath('FrontPage'), 'FrontPage');
  assert.equal(
    RestClient.encodePagePath('講演/2026年'),
    '%E8%AC%9B%E6%BC%94/2026%E5%B9%B4',
  );
  assert.equal(
    RestClient.encodePagePath('A&B/C?D'),
    'A%26B/C%3FD',
  );
});
