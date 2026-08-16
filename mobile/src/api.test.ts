import { readFileSync } from 'node:fs';
import { describe, expect, it, vi } from 'vitest';
import {
  classifyServerConnection,
  MobileApiClient,
  MobileApiError,
  normalizeServerUrl,
} from './api';

const envelope = (data: unknown, status = 200) => new Response(JSON.stringify({
  success: status < 400,
  ...(status < 400 ? { data } : { error: data }),
  meta: { server_time: '2026-07-29T00:00:00Z', schema_version: 1, minimum_client_version: '1.0.0' },
}), { status, headers: { 'Content-Type': 'application/json' } });

describe('MobileApiClient', () => {
  it('normalizes the server origin without accepting non-http schemes', () => {
    expect(normalizeServerUrl('https://example.com/')).toBe('https://example.com');
    expect(() => normalizeServerUrl('file:///tmp/test')).toThrow(/http/);
  });

  it('allows HTTPS for public hosts and rejects public HTTP before any request', () => {
    expect(classifyServerConnection('https://example.com/path/').kind).toBe('secure-https');
    expect(normalizeServerUrl('https://8.8.8.8/')).toBe('https://8.8.8.8');
    expect(() => normalizeServerUrl('http://example.com')).toThrow(/仅允许 HTTPS/);
    expect(() => normalizeServerUrl('http://8.8.8.8')).toThrow(/仅允许 HTTPS/);
    expect(() => normalizeServerUrl('http://172.32.0.1')).toThrow(/仅允许 HTTPS/);

    const fetcher = vi.fn(async () => envelope({ token: 'unexpected' }));
    expect(() => new MobileApiClient(
      'http://example.com',
      fetcher as unknown as typeof fetch,
    )).toThrow(/仅允许 HTTPS/);
    expect(fetcher).not.toHaveBeenCalled();
  });

  it('allows HTTP only for explicit local development hosts', () => {
    const localServers = [
      'http://localhost:8000',
      'http://127.0.0.1:8000',
      'http://[::1]:8000',
      'http://10.0.2.2:8000',
      'http://172.16.0.1:8000',
      'http://172.31.255.254:8000',
      'http://192.168.1.20:8000',
      'http://linguacafe.local:8000',
    ];

    localServers.forEach(server => {
      expect(classifyServerConnection(server).kind).toBe('local-http-development');
      expect(normalizeServerUrl(server)).toMatch(/^http:/);
    });
  });

  it('keeps the local HTTP warning explicit in the login UI contract', () => {
    const uiSource = readFileSync(new URL('./ui.ts', import.meta.url), 'utf8');
    expect(uiSource).toContain('仅用于本地调试');
    expect(uiSource).toContain('Android/iOS 正式版可能拒绝明文连接');
    expect(uiSource).toContain('正式使用应配置 HTTPS');
    expect(uiSource).toContain('id="local-http-warning"');
  });

  it('binds bearer authentication and the stable mobile API prefix', async () => {
    const fetcher = vi.fn(async () => envelope({ user: { id: 1 } }));
    const client = new MobileApiClient('https://example.com', fetcher as unknown as typeof fetch);
    client.setToken('secret');
    await client.bootstrap();
    const [url, init] = fetcher.mock.calls[0];
    expect(url).toBe('https://example.com/api/v1/mobile/bootstrap');
    expect((init.headers as Headers).get('Authorization')).toBe('Bearer secret');
  });

  it('preserves the caller platform during native login', async () => {
    const fetcher = vi.fn(async () => envelope({ token: 'token', device: { device_uuid: 'uuid' } }, 201));
    const client = new MobileApiClient('https://example.com', fetcher as unknown as typeof fetch);
    await client.login({
      email: 'ios@example.test',
      password: 'password',
      device_uuid: 'uuid',
      platform: 'ios',
      device_name: 'LinguaCafe iOS',
      app_version: '1.0.0',
    });
    const body = JSON.parse(String(fetcher.mock.calls[0][1].body));
    expect(body.platform).toBe('ios');
    expect(body.device_name).toBe('LinguaCafe iOS');
  });

  it('invokes a native-style fetcher with the global object as its receiver', async () => {
    const fetcher = vi.fn(function (this: unknown) {
      if (this !== globalThis) throw new TypeError('Illegal invocation');
      return Promise.resolve(envelope({ user: { id: 1 } }));
    });
    const client = new MobileApiClient('https://example.com', fetcher as unknown as typeof fetch);
    client.setToken('secret');

    await expect(client.bootstrap()).resolves.toEqual({ user: { id: 1 } });
    expect(fetcher).toHaveBeenCalledOnce();
  });

  it('uses one supplied client action id for a rating request', async () => {
    const fetcher = vi.fn(async () => envelope({ operation_id: 'op', card: {}, replayed: false }));
    const client = new MobileApiClient('https://example.com', fetcher as unknown as typeof fetch);
    client.setToken('secret');
    await client.rate(12, 'good', 'fixed-action-id', Date.now());
    const body = JSON.parse(String(fetcher.mock.calls[0][1].body));
    expect(body.client_action_id).toBe('fixed-action-id');
    expect(body.rating).toBe('good');
  });

  it('uses the supplied action identity for a text import without client retries', async () => {
    const fetcher = vi.fn(async () => envelope({ operation_id: 'op', processing_mode: 'fallback', replayed: false }, 201));
    const client = new MobileApiClient('https://example.com', fetcher as unknown as typeof fetch);
    client.setToken('secret');
    await client.importText({
      client_action_id: 'fixed-import-id',
      file_name: 'reader.txt',
      content: 'A short English document.',
      book_name: 'Reader',
      chapter_name: 'Imported text',
    });
    const [url, init] = fetcher.mock.calls[0];
    expect(url).toBe('https://example.com/api/v1/mobile/imports/text');
    expect(JSON.parse(String(init.body)).client_action_id).toBe('fixed-import-id');
    expect(fetcher).toHaveBeenCalledOnce();
  });

  it('submits the durable M4 action identity without rewriting queue fields', async () => {
    const fetcher = vi.fn(async () => envelope({
      batch_id: 'server-batch',
      status: 'completed',
      counts: { total: 1, succeeded: 1, failed: 0, replayed: 0 },
      results: [],
    }));
    const client = new MobileApiClient('https://example.com', fetcher as unknown as typeof fetch);
    client.setToken('secret');
    const action = {
      client_action_id: 'fixed-offline-action',
      type: 'sense_review.rating' as const,
      occurred_at: '2026-08-01T00:00:00.000Z',
      sequence: 9,
      payload: { review_card_id: 12, rating: 'good', review_duration_ms: 800 },
    };

    await client.syncActions([action]);

    const [url, init] = fetcher.mock.calls[0];
    const body = JSON.parse(String(init.body));
    expect(url).toBe('https://example.com/api/v1/mobile/sync/actions');
    expect(body.batch_id).toMatch(/^[0-9a-f-]{36}$/);
    expect(body.actions).toEqual([action]);
  });

  it('assembles every article shard into one offline package', async () => {
    const fetcher = vi
      .fn()
      .mockResolvedValueOnce(envelope({
        chapter: { content_version: 'sha256:chapter' },
        tokens: [{ word: 'one' }],
        sentence_translations: [{ sentence_index: 1, source_text: 'One.', translation_zh: '一。' }],
        sense_summaries: [{ occurrence_id: 7, word_sense_id: 8 }],
        dictionary_version: 'sha256:dictionary',
        dictionary_summaries: { one: ['一'] },
        next_cursor: 'next',
      }))
      .mockResolvedValueOnce(envelope({
        chapter: { content_version: 'sha256:chapter' },
        tokens: [{ word: 'two' }],
        sentence_translations: [{ sentence_index: 2, source_text: 'Two.', translation_zh: '二。' }],
        sense_summaries: [],
        dictionary_version: 'sha256:dictionary',
        dictionary_summaries: { two: ['二'] },
        next_cursor: null,
      }));
    const client = new MobileApiClient('https://example.com', fetcher as unknown as typeof fetch);
    client.setToken('secret');
    const article = await client.chapterPackage(1, 2);
    expect(article.tokens.map(token => token.word)).toEqual(['one', 'two']);
    expect(article.dictionary_summaries).toEqual({ one: ['一'], two: ['二'] });
    expect(article.sense_summaries).toEqual([{ occurrence_id: 7, word_sense_id: 8 }]);
    expect(fetcher).toHaveBeenCalledTimes(2);
  });

  it('starts and finishes reading only through the server reading contracts', async () => {
    const fetcher = vi.fn(async () => envelope({
      reading_session_id: 'session-id',
      completed: false,
      can_commit: true,
      settlement_mode: 'preflight',
      passive_good_count: 1,
      unresolved_count: 0,
      excluded_count: 0,
    }));
    const client = new MobileApiClient('https://example.com', fetcher as unknown as typeof fetch);
    client.setToken('secret');

    await client.startReadingSession(9, 'resume-id');
    await client.finishReadingSession(9, 'session-id', 'preflight');

    expect(fetcher.mock.calls[0][0]).toBe('https://example.com/api/v1/mobile/chapters/9/reading-sessions');
    expect(JSON.parse(String(fetcher.mock.calls[0][1].body))).toEqual({
      resume_reading_session_id: 'resume-id',
    });
    expect(fetcher.mock.calls[1][0]).toBe(
      'https://example.com/api/v1/mobile/chapters/9/reading-sessions/session-id/finish',
    );
    expect(JSON.parse(String(fetcher.mock.calls[1][1].body))).toEqual({ settlement_mode: 'preflight' });
  });

  it('requests a genuinely short-term Sense review horizon', async () => {
    const fetcher = vi.fn(async () => envelope({ items: [] }));
    const client = new MobileApiClient('https://example.com', fetcher as unknown as typeof fetch);
    client.setToken('secret');

    await client.reviews();

    expect(fetcher.mock.calls[0][0]).toBe(
      'https://example.com/api/v1/mobile/review-packages/short-term?horizon_days=7&limit=50',
    );
  });

  it('keeps the connected local-dictionary lookup endpoint', async () => {
    const fetcher = vi.fn(async () => envelope({
      term: 'hello',
      definitions: ['你好'],
      local_only: true,
    }));
    const client = new MobileApiClient('https://example.com', fetcher as unknown as typeof fetch);
    client.setToken('secret');

    await expect(client.dictionary('hello world')).resolves.toMatchObject({ definitions: ['你好'] });
    expect(fetcher.mock.calls[0][0]).toBe(
      'https://example.com/api/v1/mobile/dictionary/lookup?term=hello%20world',
    );
  });

  it('loads every saved WordSense page in server order', async () => {
    const fetcher = vi
      .fn()
      .mockResolvedValueOnce(envelope({
        items: [{ sense_id: 1, lemma: 'apple' }],
        pagination: { current_page: 1, last_page: 2, per_page: 100, total: 2 },
      }))
      .mockResolvedValueOnce(envelope({
        items: [{ sense_id: 2, lemma: 'banana' }],
        pagination: { current_page: 2, last_page: 2, per_page: 100, total: 2 },
      }));
    const client = new MobileApiClient('https://example.com', fetcher as unknown as typeof fetch);
    client.setToken('secret');

    await expect(client.wordSenses()).resolves.toEqual([
      { sense_id: 1, lemma: 'apple' },
      { sense_id: 2, lemma: 'banana' },
    ]);
    expect(fetcher.mock.calls.map(call => String(call[0]))).toEqual([
      'https://example.com/api/v1/mobile/word-senses?page=1&per_page=100',
      'https://example.com/api/v1/mobile/word-senses?page=2&per_page=100',
    ]);
  });

  it('aggregates every article page in server order and keeps the first duplicate id', async () => {
    const fetcher = vi.fn(async (input: RequestInfo | URL) => {
      const page = Number(new URL(String(input)).searchParams.get('page'));
      if (page === 1) {
        return envelope({
          items: [
            { book: { book_id: 1, name: 'One', language: 'english', material_type: 'cet4', exam_year: 2025, exam_set: 1 }, chapter_count: 2, content_version: 'sha256:set-one' },
            { book: { book_id: 2, name: 'Two', language: 'english' }, chapter_count: 3 },
          ],
          pagination: { current_page: 1, last_page: 2, per_page: 20, total: 3 },
        });
      }
      return envelope({
        items: [
          { book: { book_id: 2, name: 'Duplicate ignored', language: 'english' }, chapter_count: 99 },
          { book: { book_id: 3, name: 'Three', language: 'english' }, chapter_count: 4 },
        ],
        pagination: { current_page: 2, last_page: 2, per_page: 20, total: 3 },
      });
    });
    const client = new MobileApiClient('https://example.com', fetcher as unknown as typeof fetch);
    client.setToken('secret');

    await expect(client.articles()).resolves.toEqual([
      { book_id: 1, name: 'One', language: 'english', material_type: 'cet4', exam_year: 2025, exam_set: 1, chapter_count: 2, content_version: 'sha256:set-one' },
      { book_id: 2, name: 'Two', language: 'english', chapter_count: 3 },
      { book_id: 3, name: 'Three', language: 'english', chapter_count: 4 },
    ]);
    expect(fetcher).toHaveBeenCalledTimes(2);
    expect(String(fetcher.mock.calls[0][0])).toContain('page=1&per_page=20');
    expect(String(fetcher.mock.calls[1][0])).toContain('page=2&per_page=20');
  });

  it('aggregates every chapter page in server order and keeps the first duplicate id', async () => {
    const fetcher = vi.fn(async (input: RequestInfo | URL) => {
      const page = Number(new URL(String(input)).searchParams.get('chapter_page'));
      if (page === 1) {
        return envelope({
          chapters: [
            { chapter_id: 10, name: 'First', token_count: 100 },
            { chapter_id: 11, name: 'Second', token_count: 200 },
          ],
          chapter_pagination: { current_page: 1, last_page: 2, per_page: 100, total: 3 },
        });
      }
      return envelope({
        chapters: [
          { chapter_id: 11, name: 'Duplicate ignored', token_count: 999 },
          { chapter_id: 12, name: 'Third', token_count: 300 },
        ],
        chapter_pagination: { current_page: 2, last_page: 2, per_page: 100, total: 3 },
      });
    });
    const client = new MobileApiClient('https://example.com', fetcher as unknown as typeof fetch);
    client.setToken('secret');

    await expect(client.chapters(4)).resolves.toEqual([
      { chapter_id: 10, name: 'First', token_count: 100 },
      { chapter_id: 11, name: 'Second', token_count: 200 },
      { chapter_id: 12, name: 'Third', token_count: 300 },
    ]);
    expect(fetcher).toHaveBeenCalledTimes(2);
    expect(String(fetcher.mock.calls[0][0])).toContain('chapter_page=1&chapters_per_page=100');
    expect(String(fetcher.mock.calls[1][0])).toContain('chapter_page=2&chapters_per_page=100');
  });

  it('keeps the legacy single-page chapter envelope compatible', async () => {
    const fetcher = vi.fn(async () => envelope({
      chapters: [{ chapter_id: 21, name: 'Legacy chapter', token_count: 42 }],
    }));
    const client = new MobileApiClient('https://example.com', fetcher as unknown as typeof fetch);
    client.setToken('secret');

    await expect(client.chapters(4)).resolves.toEqual([
      { chapter_id: 21, name: 'Legacy chapter', token_count: 42 },
    ]);
    expect(fetcher).toHaveBeenCalledOnce();
  });

  it('fails closed when pagination repeats a page instead of progressing', async () => {
    const fetcher = vi
      .fn()
      .mockResolvedValueOnce(envelope({
        items: [],
        pagination: { current_page: 1, last_page: 2, per_page: 20, total: 1 },
      }))
      .mockResolvedValueOnce(envelope({
        items: [],
        pagination: { current_page: 1, last_page: 2, per_page: 20, total: 1 },
      }));
    const client = new MobileApiClient('https://example.com', fetcher as unknown as typeof fetch);
    client.setToken('secret');

    await expect(client.articles()).rejects.toMatchObject<Partial<MobileApiError>>({
      code: 'INVALID_PAGINATION',
    });
    expect(fetcher).toHaveBeenCalledTimes(2);
  });

  it('fails closed on invalid or unbounded pagination metadata', async () => {
    const invalidLastPage = vi.fn(async () => envelope({
      chapters: [],
      chapter_pagination: { current_page: 1, last_page: 0, per_page: 100, total: 1 },
    }));
    const unboundedLastPage = vi.fn(async () => envelope({
      items: [],
      pagination: { current_page: 1, last_page: 1001, per_page: 20, total: 20001 },
    }));
    const chapterClient = new MobileApiClient(
      'https://example.com',
      invalidLastPage as unknown as typeof fetch,
    );
    const articleClient = new MobileApiClient(
      'https://example.com',
      unboundedLastPage as unknown as typeof fetch,
    );
    chapterClient.setToken('secret');
    articleClient.setToken('secret');

    await expect(chapterClient.chapters(4)).rejects.toMatchObject({ code: 'INVALID_PAGINATION' });
    await expect(articleClient.articles()).rejects.toMatchObject({ code: 'INVALID_PAGINATION' });
    expect(invalidLastPage).toHaveBeenCalledOnce();
    expect(unboundedLastPage).toHaveBeenCalledOnce();
  });

  it('projects the legacy single-page article envelope for the UI', async () => {
    const fetcher = vi.fn(async () => envelope({
      items: [{
        book: { book_id: 4, name: 'Reader', language: 'english' },
        chapter_count: 3,
      }],
    }));
    const client = new MobileApiClient('https://example.com', fetcher as unknown as typeof fetch);
    client.setToken('secret');
    await expect(client.articles()).resolves.toEqual([{
      book_id: 4,
      name: 'Reader',
      language: 'english',
      chapter_count: 3,
    }]);
  });

  it('exposes safe server error codes to the UI', async () => {
    const fetcher = vi.fn(async () => envelope({
      code: 'DEVICE_REVOKED',
      message: 'Device revoked.',
    }, 401));
    const client = new MobileApiClient('https://example.com', fetcher as unknown as typeof fetch);
    client.setToken('secret');
    await expect(client.bootstrap()).rejects.toMatchObject<Partial<MobileApiError>>({
      code: 'DEVICE_REVOKED',
      status: 401,
    });
  });

  it('chooses the latest undoable rating instead of another operation type', async () => {
    const fetcher = vi.fn(async () => envelope({
      operations: [
        { operation_id: 'manual-op', operation_type: 'review_control.manual', can_undo: true, version: 3 },
        { operation_id: 'rating-op', operation_type: 'sense_review.rating', can_undo: true, version: 7 },
      ],
    }));
    const client = new MobileApiClient('https://example.com', fetcher as unknown as typeof fetch);
    client.setToken('secret');

    await expect(client.latestUndoableOperation()).resolves.toEqual({
      operation_id: 'rating-op',
      operation_type: 'sense_review.rating',
      can_undo: true,
      version: 7,
    });
    expect(fetcher.mock.calls[0][0]).toBe('https://example.com/api/v1/mobile/operations?limit=20');

    await client.undo('rating-op', 7);
    const undoInit = fetcher.mock.calls[1][1] as RequestInit;
    expect(undoInit.method).toBe('POST');
    expect(JSON.parse(undoInit.body as string)).toMatchObject({
      expected_version: 7,
      client_action_id: expect.stringMatching(/^[0-9a-f-]{36}$/),
    });
  });

  it('maps a non-json response to a safe client error', async () => {
    const fetcher = vi.fn(async () => new Response('<html>not json</html>', {
      status: 502,
      headers: { 'Content-Type': 'text/html' },
    }));
    const client = new MobileApiClient('https://example.com', fetcher as unknown as typeof fetch);
    client.setToken('secret');

    await expect(client.bootstrap()).rejects.toMatchObject<Partial<MobileApiError>>({
      code: 'INVALID_RESPONSE',
      status: 502,
    });
  });
});
