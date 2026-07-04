/**
 * PukiWiki REST API v2 への HTTP クライアント。
 *
 * サーバー側仕様（rest-api-v2/api/v1/index.php）:
 *   - 認証は Authorization: Bearer <key>
 *   - 階層ページ名（親/子）はパスにスラッシュのまま書く
 *     （AllowEncodedSlashes Off 環境で %2F が 404 になるため、
 *      各セグメントのみ encodeURIComponent し '/' は温存する）
 *   - エラーは { error: { status, code, message } } 形式
 *
 * License: GPL v2 or (at your option) any later version
 */

export class RestError extends Error {
  constructor(status, code, message) {
    super(message);
    this.name = 'RestError';
    this.status = status;
    this.code = code;
  }
}

export class RestClient {
  /**
   * @param {{ baseUrl: string, apiKey: string, timeoutMs?: number }} opts
   *   baseUrl 例: https://host/rest-api-v2/api/v1
   */
  constructor({ baseUrl, apiKey, timeoutMs = 30000 }) {
    this.baseUrl = String(baseUrl).replace(/\/+$/, '');
    this.apiKey = apiKey;
    this.timeoutMs = timeoutMs;
  }

  /** 階層ページ名をパスに変換（セグメント毎にエンコード、'/' は温存） */
  static encodePagePath(page) {
    return page.split('/').map(encodeURIComponent).join('/');
  }

  async request(method, path, { query = null, body = null } = {}) {
    let url = this.baseUrl + path;
    if (query !== null) {
      const qs = new URLSearchParams(query).toString();
      if (qs !== '') url += '?' + qs;
    }

    const options = {
      method,
      headers: {
        'Authorization': `Bearer ${this.apiKey}`,
        'Accept': 'application/json',
      },
      signal: AbortSignal.timeout(this.timeoutMs),
    };
    if (body !== null) {
      options.headers['Content-Type'] = 'application/json; charset=utf-8';
      options.body = JSON.stringify(body);
    }

    let res;
    try {
      res = await fetch(url, options);
    } catch (e) {
      const reason = (e && e.name === 'TimeoutError')
        ? `timed out after ${this.timeoutMs}ms`
        : (e?.cause?.message ?? e?.message ?? String(e));
      throw new RestError(0, 'network_error',
        `Cannot reach PukiWiki REST API at ${url}: ${reason}`);
    }

    let data = null;
    const text = await res.text();
    if (text !== '') {
      try {
        data = JSON.parse(text);
      } catch {
        data = null;
      }
    }

    if (!res.ok) {
      const err = (data && typeof data === 'object' && data.error) ? data.error : {};
      throw new RestError(
        res.status,
        err.code ?? `http_${res.status}`,
        err.message ?? `HTTP ${res.status} from REST API`,
      );
    }
    if (data === null || typeof data !== 'object') {
      throw new RestError(res.status, 'invalid_response',
        `REST API returned a non-JSON response (HTTP ${res.status}). Check PUKIWIKI_API_URL.`);
    }
    return data;
  }

  readPage(page) {
    return this.request('GET', '/pages/' + RestClient.encodePagePath(page));
  }

  listPages(limit, offset) {
    return this.request('GET', '/pages', { query: { limit, offset } });
  }

  search(q, limit) {
    return this.request('GET', '/search', { query: { q, limit } });
  }

  writePage(page, base_sha1, content) {
    return this.request('PUT', '/pages/' + RestClient.encodePagePath(page),
      { body: { base_sha1, content } });
  }

  pageRevisions(page) {
    return this.request('GET', '/pages/' + RestClient.encodePagePath(page) + '/revisions');
  }

  readRevision(page, rev) {
    return this.request('GET',
      '/pages/' + RestClient.encodePagePath(page) + '/revisions/' + encodeURIComponent(rev));
  }
}
