import type {
  ArticleSummary,
  ChapterPackage,
  ChapterSummary,
  OfflineSyncIssue,
  QueuedAction,
  ReviewItem,
  ReviewRating,
  SyncActionResult,
} from './types';

export interface OfflineStateStore {
  read(key: string): Promise<OfflineState | null>;
  write(key: string, state: OfflineState): Promise<void>;
  delete(key: string): Promise<void>;
}

interface CachedValue<T> {
  value: T;
  cached_at: string;
}

export interface OfflineState {
  articles?: CachedValue<ArticleSummary[]>;
  chapters: Record<string, CachedValue<ChapterSummary[]>>;
  chapter_packages: Record<string, CachedValue<ChapterPackage>>;
  reviews?: CachedValue<ReviewItem[]>;
  queue: QueuedAction[];
  next_sequence: number;
  issues: OfflineSyncIssue[];
}

const EMPTY_STATE = (): OfflineState => ({
  chapters: {},
  chapter_packages: {},
  queue: [],
  next_sequence: 1,
  issues: [],
});

export class IndexedDbStateStore implements OfflineStateStore {
  private database: Promise<IDBDatabase> | null = null;

  async read(key: string): Promise<OfflineState | null> {
    const database = await this.open();
    return new Promise((resolve, reject) => {
      const request = database.transaction('scopes', 'readonly').objectStore('scopes').get(key);
      request.onsuccess = () => resolve((request.result as OfflineState | undefined) ?? null);
      request.onerror = () => reject(request.error);
    });
  }

  async write(key: string, state: OfflineState): Promise<void> {
    const database = await this.open();
    await new Promise<void>((resolve, reject) => {
      const transaction = database.transaction('scopes', 'readwrite');
      transaction.objectStore('scopes').put(state, key);
      transaction.oncomplete = () => resolve();
      transaction.onerror = () => reject(transaction.error);
      transaction.onabort = () => reject(transaction.error);
    });
  }

  async delete(key: string): Promise<void> {
    const database = await this.open();
    await new Promise<void>((resolve, reject) => {
      const transaction = database.transaction('scopes', 'readwrite');
      transaction.objectStore('scopes').delete(key);
      transaction.oncomplete = () => resolve();
      transaction.onerror = () => reject(transaction.error);
      transaction.onabort = () => reject(transaction.error);
    });
  }

  private open(): Promise<IDBDatabase> {
    this.database ??= new Promise((resolve, reject) => {
      const request = indexedDB.open('linguacafe-offline-v1', 1);
      request.onupgradeneeded = () => request.result.createObjectStore('scopes');
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });
    return this.database;
  }
}

export class OfflineRepository {
  private mutation: Promise<void> = Promise.resolve();

  constructor(
    userId: number,
    language: string,
    private readonly store: OfflineStateStore = new IndexedDbStateStore(),
  ) {
    this.scope = `user:${userId}:language:${language}`;
  }

  private readonly scope: string;

  async articles(): Promise<ArticleSummary[] | null> {
    return (await this.state()).articles?.value ?? null;
  }

  async saveArticles(value: ArticleSummary[]): Promise<void> {
    await this.update(state => { state.articles = this.cached(value); });
  }

  async chapters(bookId: number): Promise<ChapterSummary[] | null> {
    return (await this.state()).chapters[String(bookId)]?.value ?? null;
  }

  async saveChapters(bookId: number, value: ChapterSummary[]): Promise<void> {
    await this.update(state => { state.chapters[String(bookId)] = this.cached(value); });
  }

  async chapterPackage(bookId: number, chapterId: number): Promise<ChapterPackage | null> {
    return (await this.state()).chapter_packages?.[`${bookId}:${chapterId}`]?.value ?? null;
  }

  async saveChapterPackage(bookId: number, chapterId: number, value: ChapterPackage): Promise<void> {
    await this.update(state => {
      state.chapter_packages ??= {};
      state.chapter_packages[`${bookId}:${chapterId}`] = this.cached(value);
    });
  }

  async reviews(): Promise<ReviewItem[] | null> {
    return (await this.state()).reviews?.value ?? null;
  }

  async saveReviews(value: ReviewItem[]): Promise<void> {
    await this.update(state => { state.reviews = this.cached(value); });
  }

  async enqueueRating(
    reviewCardId: number,
    rating: ReviewRating,
    reviewDurationMs: number,
    now = new Date(),
  ): Promise<QueuedAction> {
    let queued!: QueuedAction;
    await this.update(state => {
      queued = {
        client_action_id: crypto.randomUUID(),
        type: 'sense_review.rating',
        occurred_at: now.toISOString(),
        sequence: state.next_sequence++,
        payload: {
          review_card_id: reviewCardId,
          rating,
          review_duration_ms: Math.min(600000, Math.max(0, reviewDurationMs)),
        },
      };
      state.queue.push(queued);
    });
    return queued;
  }

  async queuedActions(): Promise<QueuedAction[]> {
    return [...(await this.state()).queue].sort((a, b) => a.sequence - b.sequence);
  }

  async pendingCardIds(): Promise<Set<number>> {
    return new Set((await this.state()).queue.map(action => action.payload.review_card_id));
  }

  async issues(): Promise<OfflineSyncIssue[]> {
    return [...(await this.state()).issues];
  }

  async applySyncResults(results: SyncActionResult[]): Promise<void> {
    const byId = new Map(results.map(result => [result.client_action_id, result]));
    await this.update(state => {
      state.queue = state.queue.filter(action => {
        const result = byId.get(action.client_action_id);
        if (!result || result.outcome === 'retryable') return true;
        if (result.outcome !== 'applied' && result.outcome !== 'replayed') {
          state.issues.unshift({
            client_action_id: action.client_action_id,
            code: result.error?.code ?? result.outcome.toUpperCase(),
            message: result.error?.message ?? '服务器未接受离线操作',
            recorded_at: new Date().toISOString(),
          });
          state.issues = state.issues.slice(0, 20);
        }
        return false;
      });
    });
  }

  async clearIssues(): Promise<void> {
    await this.update(state => { state.issues = []; });
  }

  async clear(): Promise<void> {
    await this.mutation.catch(() => undefined);
    await this.store.delete(this.scope);
  }

  private cached<T>(value: T): CachedValue<T> {
    return { value, cached_at: new Date().toISOString() };
  }

  private async state(): Promise<OfflineState> {
    return (await this.store.read(this.scope)) ?? EMPTY_STATE();
  }

  private async update(mutator: (state: OfflineState) => void): Promise<void> {
    const operation = this.mutation.then(async () => {
      const state = await this.state();
      mutator(state);
      await this.store.write(this.scope, state);
    });
    this.mutation = operation.catch(() => undefined);
    await operation;
  }
}
