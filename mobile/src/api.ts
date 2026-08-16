import type {
  ArticleSummary,
  Bootstrap,
  ChapterPackage,
  ChapterSummary,
  DailySummary,
  MobileApiFailure,
  MobileEnvelope,
  ReaderToken,
  ReviewItem,
  ReviewRating,
  QueuedAction,
  SyncBatchResult,
  WordSenseSummary,
} from './types';

export class MobileApiError extends Error {
  constructor(
    public readonly code: string,
    message: string,
    public readonly status: number,
    public readonly details?: Record<string, string[]>,
  ) {
    super(message);
  }
}

const MAX_PAGINATION_PAGES = 1000;
const PUBLIC_HTTP_ERROR = '正式移动端仅允许 HTTPS；HTTP 只可用于本地调试地址。';

export type ServerConnectionKind = 'secure-https' | 'local-http-development';

export interface ServerConnectionClassification {
  normalizedUrl: string;
  kind: ServerConnectionKind;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function parseIpv4(hostname: string): number[] | null {
  const parts = hostname.split('.');
  if (parts.length !== 4 || parts.some(part => !/^\d{1,3}$/.test(part))) return null;
  const octets = parts.map(Number);
  return octets.every(octet => octet >= 0 && octet <= 255) ? octets : null;
}

export function isPrivateDevelopmentHost(hostname: string): boolean {
  const normalized = hostname.toLowerCase().replace(/^\[|\]$/g, '');
  if (normalized === 'localhost' || normalized === '127.0.0.1' || normalized === '::1') return true;
  if (normalized.endsWith('.local')) return true;

  const ipv4 = parseIpv4(normalized);
  if (!ipv4) return false;
  if (ipv4[0] === 10) return true;
  if (ipv4[0] === 172 && ipv4[1] >= 16 && ipv4[1] <= 31) return true;
  return ipv4[0] === 192 && ipv4[1] === 168;
}

export function classifyServerConnection(value: string): ServerConnectionClassification {
  const url = new URL(value.trim());
  if (!['https:', 'http:'].includes(url.protocol)) {
    throw new Error('服务器地址必须使用 http 或 https');
  }
  if (url.protocol === 'http:' && !isPrivateDevelopmentHost(url.hostname)) {
    throw new Error(PUBLIC_HTTP_ERROR);
  }
  url.pathname = url.pathname.replace(/\/+$/, '');
  url.search = '';
  url.hash = '';
  return {
    normalizedUrl: url.toString().replace(/\/$/, ''),
    kind: url.protocol === 'https:' ? 'secure-https' : 'local-http-development',
  };
}

export function normalizeServerUrl(value: string): string {
  return classifyServerConnection(value).normalizedUrl;
}

function invalidPagination(): MobileApiError {
  return new MobileApiError(
    'INVALID_PAGINATION',
    '服务器分页信息无效，已停止继续加载。',
    0,
  );
}

function paginationPage(value: unknown, expectedPage: number): { currentPage: number; lastPage: number } {
  if (!isRecord(value)) throw invalidPagination();
  const currentPage = value.current_page;
  const lastPage = value.last_page;
  if (
    !Number.isInteger(currentPage)
    || !Number.isInteger(lastPage)
    || Number(currentPage) !== expectedPage
    || Number(currentPage) < 1
    || Number(lastPage) < Number(currentPage)
    || Number(currentPage) > MAX_PAGINATION_PAGES
    || Number(lastPage) > MAX_PAGINATION_PAGES
  ) {
    throw invalidPagination();
  }
  return { currentPage: Number(currentPage), lastPage: Number(lastPage) };
}

export class MobileApiClient {
  private token: string | null = null;

  constructor(
    private baseUrl: string,
    private readonly fetcher: typeof fetch = fetch,
  ) {
    this.baseUrl = normalizeServerUrl(baseUrl);
  }

  setToken(token: string | null): void {
    this.token = token;
  }

  setBaseUrl(baseUrl: string): void {
    this.baseUrl = normalizeServerUrl(baseUrl);
  }

  async login(payload: {
    email: string;
    password: string;
    device_uuid: string;
    platform: 'android' | 'ios' | 'web';
    device_name: string;
    app_version: string;
  }): Promise<{ token: string; device: { device_uuid: string } }> {
    return this.request('/auth/tokens', {
      method: 'POST',
      body: JSON.stringify(payload),
    }, false);
  }

  bootstrap(): Promise<Bootstrap> {
    return this.request('/bootstrap');
  }

  async articles(): Promise<ArticleSummary[]> {
    const articles: ArticleSummary[] = [];
    const seenBookIds = new Set<number>();

    for (let page = 1; page <= MAX_PAGINATION_PAGES; page++) {
      const query = new URLSearchParams({ page: String(page), per_page: '20' });
      const data = await this.request<{
        items: Array<{
          book: { book_id: number; name: string; language: string };
          chapter_count: number;
        }>;
        pagination?: unknown;
      }>(`/article-packages?${query}`);

      data.items.forEach(item => {
        if (seenBookIds.has(item.book.book_id)) return;
        seenBookIds.add(item.book.book_id);
        articles.push({ ...item.book, chapter_count: item.chapter_count });
      });

      if (data.pagination === undefined) {
        if (page === 1) return articles;
        throw invalidPagination();
      }
      const pagination = paginationPage(data.pagination, page);
      if (pagination.currentPage >= pagination.lastPage) return articles;
    }

    throw invalidPagination();
  }

  async chapters(bookId: number): Promise<ChapterSummary[]> {
    const chapters: ChapterSummary[] = [];
    const seenChapterIds = new Set<number>();

    for (let page = 1; page <= MAX_PAGINATION_PAGES; page++) {
      const query = new URLSearchParams({
        chapter_page: String(page),
        chapters_per_page: '100',
      });
      const data = await this.request<{
        chapters: ChapterSummary[];
        chapter_pagination?: unknown;
      }>(`/article-packages/${bookId}?${query}`);

      data.chapters.forEach(chapter => {
        if (seenChapterIds.has(chapter.chapter_id)) return;
        seenChapterIds.add(chapter.chapter_id);
        chapters.push(chapter);
      });

      if (data.chapter_pagination === undefined) {
        if (page === 1) return chapters;
        throw invalidPagination();
      }
      const pagination = paginationPage(data.chapter_pagination, page);
      if (pagination.currentPage >= pagination.lastPage) return chapters;
    }

    throw invalidPagination();
  }

  async chapterPackage(bookId: number, chapterId: number): Promise<ChapterPackage> {
    const tokens: ReaderToken[] = [];
    const sentenceTranslations = new Map<string, ChapterPackage['sentence_translations'][number]>();
    const senseSummaries = new Map<number, ChapterPackage['sense_summaries'][number]>();
    const dictionarySummaries: Record<string, string[]> = {};
    let contentVersion = '';
    let dictionaryVersion = '';
    let cursor = '';
    do {
      const query = new URLSearchParams({ token_limit: '1000' });
      if (cursor) query.set('cursor', cursor);
      const data = await this.request<{
        chapter: { content_version: string };
        tokens: ReaderToken[];
        sentence_translations: ChapterPackage['sentence_translations'];
        sense_summaries: ChapterPackage['sense_summaries'];
        dictionary_version: string;
        dictionary_summaries: Record<string, string[]>;
        next_cursor: string | null;
      }>(`/article-packages/${bookId}/chapters/${chapterId}?${query}`);
      contentVersion ||= data.chapter.content_version;
      dictionaryVersion ||= data.dictionary_version;
      tokens.push(...data.tokens);
      data.sentence_translations.forEach(item => sentenceTranslations.set(String(item.sentence_index), item));
      data.sense_summaries.forEach(item => senseSummaries.set(item.occurrence_id, item));
      Object.assign(dictionarySummaries, data.dictionary_summaries);
      cursor = data.next_cursor ?? '';
    } while (cursor);
    return {
      content_version: contentVersion,
      dictionary_version: dictionaryVersion,
      tokens,
      sentence_translations: [...sentenceTranslations.values()],
      sense_summaries: [...senseSummaries.values()],
      dictionary_summaries: dictionarySummaries,
    };
  }

  dictionary(term: string): Promise<{ term: string; definitions: string[]; local_only: true }> {
    return this.request(`/dictionary/lookup?term=${encodeURIComponent(term)}`);
  }

  createSense(payload: {
    lemma: string;
    surface_form: string;
    pos: string;
    sense_zh: string;
    chapter_id?: number;
    sentence_id?: number | string | null;
    sentence_en?: string;
  }): Promise<{ word_sense: { sense_id: number; lemma: string; sense_zh: string } }> {
    return this.request('/word-senses', {
      method: 'POST',
      body: JSON.stringify({ ...payload, keep_new: true }),
    });
  }

  async wordSenses(): Promise<WordSenseSummary[]> {
    const senses: WordSenseSummary[] = [];
    for (let page = 1; page <= MAX_PAGINATION_PAGES; page++) {
      const query = new URLSearchParams({ page: String(page), per_page: '100' });
      const data = await this.request<{ items: WordSenseSummary[]; pagination: unknown }>(
        `/word-senses?${query}`,
      );
      senses.push(...data.items);
      const pagination = paginationPage(data.pagination, page);
      if (pagination.currentPage >= pagination.lastPage) return senses;
    }
    throw invalidPagination();
  }

  importText(payload: {
    client_action_id: string;
    file_name: string;
    content: string;
    book_name: string;
    chapter_name: string;
  }): Promise<{
    operation_id: string;
    processing_mode: 'tokenizer' | 'fallback';
    replayed: boolean;
  }> {
    return this.request('/imports/text', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  }

  async reviews(): Promise<ReviewItem[]> {
    const data = await this.request<{ items: ReviewItem[] }>(
      '/review-packages/short-term?horizon_days=7&limit=50',
    );
    return data.items;
  }

  rate(cardId: number, rating: ReviewRating, actionId: string, startedAt: number) {
    return this.request<{
      operation_id: string;
      card: Record<string, unknown>;
      replayed: boolean;
    }>(`/review-cards/${cardId}/ratings`, {
      method: 'POST',
      body: JSON.stringify({
        rating,
        client_action_id: actionId,
        review_duration_ms: Math.min(600000, Math.max(0, Date.now() - startedAt)),
      }),
    });
  }

  syncActions(actions: QueuedAction[]): Promise<SyncBatchResult> {
    return this.request('/sync/actions', {
      method: 'POST',
      body: JSON.stringify({ batch_id: crypto.randomUUID(), actions }),
    });
  }

  async latestUndoableOperation(): Promise<{ operation_id: string; version: number } | null> {
    const data = await this.request<{
      operations: Array<{
        operation_id: string;
        operation_type: string;
        can_undo: boolean;
        version: number;
      }>;
    }>('/operations?limit=20');
    return data.operations.find(operation => (
      operation.operation_type === 'sense_review.rating' && operation.can_undo
    )) ?? null;
  }

  undo(operationId: string, expectedVersion: number) {
    return this.request(`/operations/${operationId}/undo`, {
      method: 'POST',
      body: JSON.stringify({
        client_action_id: crypto.randomUUID(),
        expected_version: expectedVersion,
      }),
    });
  }

  summary(): Promise<DailySummary> {
    return this.request('/summary');
  }

  revoke(deviceUuid: string) {
    return this.request(`/devices/${deviceUuid}`, { method: 'DELETE' });
  }

  async downloadMedia(assetId: string): Promise<Blob> {
    if (!this.token) throw new MobileApiError('UNAUTHENTICATED', '请重新登录', 401);
    let response: Response;
    try {
      response = await this.fetcher.call(globalThis, `${this.baseUrl}/api/v1/mobile/media/assets/${encodeURIComponent(assetId)}`, {
        headers: { Authorization: `Bearer ${this.token}`, Accept: 'audio/mpeg,audio/mp4' },
      });
    } catch {
      throw new MobileApiError('NETWORK_ERROR', '当前离线且音频尚未缓存', 0);
    }
    if (!response.ok) {
      throw new MobileApiError('MEDIA_DOWNLOAD_FAILED', `音频下载失败 (${response.status})`, response.status);
    }
    return response.blob();
  }

  private async request<T>(
    path: string,
    init: RequestInit = {},
    authenticated = true,
  ): Promise<T> {
    const headers = new Headers(init.headers);
    headers.set('Accept', 'application/json');
    if (init.body) headers.set('Content-Type', 'application/json');
    if (authenticated) {
      if (!this.token) throw new MobileApiError('UNAUTHENTICATED', '请重新登录', 401);
      headers.set('Authorization', `Bearer ${this.token}`);
    }

    let response: Response;
    try {
      response = await this.fetcher.call(globalThis, `${this.baseUrl}/api/v1/mobile${path}`, {
        ...init,
        headers,
      });
    } catch {
      throw new MobileApiError('NETWORK_ERROR', '无法连接服务器，请检查地址和网络', 0);
    }

    let payload: MobileEnvelope<T> | MobileApiFailure;
    try {
      payload = await response.json() as MobileEnvelope<T> | MobileApiFailure;
    } catch {
      throw new MobileApiError(
        'INVALID_RESPONSE',
        '服务器返回了无法识别的响应，请检查服务器地址和版本',
        response.status,
      );
    }
    if (!response.ok || !payload.success) {
      const failure = payload as MobileApiFailure;
      throw new MobileApiError(
        failure.error?.code ?? 'HTTP_ERROR',
        failure.error?.message ?? `请求失败 (${response.status})`,
        response.status,
        failure.error?.details,
      );
    }
    return payload.data;
  }
}
