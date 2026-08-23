import { describe, expect, it } from 'vitest';
import { OfflineRepository, type OfflineState, type OfflineStateStore } from './offlineRepository';

class MemoryStore implements OfflineStateStore {
  readonly values = new Map<string, OfflineState>();

  async read(key: string): Promise<OfflineState | null> {
    const value = this.values.get(key);
    return value ? structuredClone(value) : null;
  }

  async write(key: string, state: OfflineState): Promise<void> {
    this.values.set(key, structuredClone(state));
  }

  async delete(key: string): Promise<void> {
    this.values.delete(key);
  }
}

describe('OfflineRepository', () => {
  it('isolates cached packages by user and language', async () => {
    const store = new MemoryStore();
    const english = new OfflineRepository(7, 'English', store);
    const spanish = new OfflineRepository(7, 'Spanish', store);
    const otherUser = new OfflineRepository(8, 'English', store);

    await english.saveArticles([{ book_id: 3, name: 'Book', language: 'English', chapter_count: 1 }]);

    expect(await english.articles()).toHaveLength(1);
    expect(await spanish.articles()).toBeNull();
    expect(await otherUser.articles()).toBeNull();
  });

  it('persists snapshots and allocates monotonic rating sequence', async () => {
    const store = new MemoryStore();
    const repository = new OfflineRepository(7, 'English', store);
    await repository.saveChapters(3, [{ chapter_id: 9, name: 'One', token_count: 2 }]);
    await repository.saveChapterPackage(3, 9, {
      source_revision: 'sha256:revision',
      content_version: 'sha256:chapter',
      dictionary_version: 'sha256:dictionary',
      tokens: [{
        position: 1,
        canonical_token_index: 10,
        word: 'Hello',
        lemma: 'hello',
        pos: 'noun',
        source_sentence_identity: 1,
        is_structure: false,
        space_after: true,
      }],
      sentence_translations: [],
      sense_summaries: [],
      dictionary_summaries: { hello: ['你好'] },
    });
    const questionKey = 'a'.repeat(64);
    const first = await repository.enqueueRating(10, 'good', 1200, new Date('2026-08-01T00:00:00Z'), undefined, questionKey);
    const second = await repository.enqueueRating(11, 'again', 900, new Date('2026-08-01T00:00:01Z'));

    expect((await repository.chapters(3))?.[0].chapter_id).toBe(9);
    expect((await repository.chapterPackage(3, 9))?.tokens[0].lemma).toBe('hello');
    expect((await repository.chapterPackage(3, 9))?.dictionary_summaries.hello).toEqual(['你好']);
    expect([first.sequence, second.sequence]).toEqual([1, 2]);
    expect(first.payload.question_example_key).toBe(questionKey);
    expect(await repository.pendingCardIds()).toEqual(new Set([10, 11]));
  });

  it('derives downloaded chapters and records the completed server manifest version', async () => {
    const store = new MemoryStore();
    const repository = new OfflineRepository(7, 'English', store);
    const articlePackage = {
      source_revision: 'sha256:revision',
      content_version: 'sha256:chapter',
      dictionary_version: 'sha256:dictionary',
      tokens: [],
      sentence_translations: [],
      sense_summaries: [],
      dictionary_summaries: {},
    };

    await repository.saveChapterPackage(3, 10, articlePackage);
    await repository.saveChapterPackage(3, 9, articlePackage);
    expect(await repository.bookDownloadState(3)).toEqual({
      chapterIds: [9, 10],
      contentVersion: null,
    });

    await repository.markBookDownloaded(3, 'sha256:manifest');
    expect(await new OfflineRepository(7, 'English', store).bookDownloadState(3)).toEqual({
      chapterIds: [9, 10],
      contentVersion: 'sha256:manifest',
    });
  });

  it('removes successes, retains retryable actions and records terminal issues', async () => {
    const repository = new OfflineRepository(7, 'English', new MemoryStore());
    const applied = await repository.enqueueRating(10, 'good', 100);
    const retryable = await repository.enqueueRating(11, 'hard', 100);
    const conflict = await repository.enqueueRating(12, 'easy', 100);

    await repository.applySyncResults([
      { client_action_id: applied.client_action_id, outcome: 'applied', error: null },
      {
        client_action_id: retryable.client_action_id,
        outcome: 'retryable',
        error: { code: 'INTERNAL_ERROR', message: 'retry', retryable: true },
      },
      {
        client_action_id: conflict.client_action_id,
        outcome: 'conflict',
        error: { code: 'OUT_OF_ORDER_ACTION', message: 'newer rating exists' },
      },
    ]);

    expect((await repository.queuedActions()).map(item => item.client_action_id))
      .toEqual([retryable.client_action_id]);
    expect(await repository.issues()).toEqual([expect.objectContaining({
      client_action_id: conflict.client_action_id,
      code: 'OUT_OF_ORDER_ACTION',
    })]);
  });

  it('recovers the same pending action identity after the app repository restarts', async () => {
    const store = new MemoryStore();
    const beforeRestart = new OfflineRepository(7, 'English', store);
    const queued = await beforeRestart.enqueueRating(10, 'good', 100);

    const afterRestart = new OfflineRepository(7, 'English', store);
    expect(await afterRestart.queuedActions()).toEqual([queued]);

    await afterRestart.applySyncResults([{
      client_action_id: queued.client_action_id,
      outcome: 'retryable',
      error: { code: 'INTERNAL_ERROR', message: 'retry', retryable: true },
    }]);
    expect(await afterRestart.queuedActions()).toEqual([queued]);
  });

  it('queues reading interactions and explicit ratings in the existing ordered queue', async () => {
    const repository = new OfflineRepository(7, 'English', new MemoryStore());
    const opened = await repository.enqueueReadingInteraction(
      '11111111-1111-4111-8111-111111111111',
      'word:0',
    );
    const rating = await repository.enqueueRating(
      10,
      'good',
      250,
      new Date('2026-08-01T00:00:01Z'),
      {
        readingSessionId: '11111111-1111-4111-8111-111111111111',
        occurrenceId: 'word:0',
      },
    );

    expect(opened.type).toBe('reading_session.interaction');
    expect(opened.payload).toEqual({
      reading_session_id: '11111111-1111-4111-8111-111111111111',
      interaction_type: 'opened',
      occurrence_id: 'word:0',
    });
    expect(rating.type).toBe('sense_review.rating');
    expect(rating.payload).toMatchObject({
      review_card_id: 10,
      reading_session_id: '11111111-1111-4111-8111-111111111111',
      occurrence_id: 'word:0',
    });
    expect(rating.payload.question_example_key).toBeUndefined();
    expect((await repository.queuedActions()).map(action => action.sequence)).toEqual([1, 2]);
    expect(await repository.pendingCardIds()).toEqual(new Set([10]));
  });

  it('keeps every reading position event and folds successful server continuity into the cached package', async () => {
    const store = new MemoryStore();
    const repository = new OfflineRepository(7, 'English', store);
    const continuity = {
      source_revision: 'sha256:revision',
      resume: {
        source_revision: 'sha256:revision',
        canonical_token_index: 100,
        position_occurred_at: '2026-08-01T00:00:02.000Z',
      },
      furthest: { source_revision: 'sha256:revision', canonical_token_index: 100 },
    };
    await repository.saveChapterPackage(3, 9, {
      source_revision: 'sha256:revision',
      content_version: 'sha256:chapter',
      dictionary_version: 'sha256:dictionary',
      tokens: [],
      sentence_translations: [],
      sense_summaries: [],
      dictionary_summaries: {},
      reading_session: {
        reading_session_id: '11111111-1111-4111-8111-111111111111',
        chapter_id: 9,
        source_revision: 'sha256:revision',
        status: 'active',
        completed: false,
        reading_targets: [],
        continuity: {
          source_revision: 'sha256:revision',
          resume: null,
          furthest: null,
        },
      },
    });

    const first = await repository.enqueueReadingPosition(9, 'sha256:revision', 10, new Date('2026-08-01T00:00:01Z'));
    const second = await repository.enqueueReadingPosition(9, 'sha256:revision', 100, new Date('2026-08-01T00:00:02Z'));
    expect((await repository.queuedActions()).filter(action => action.type === 'reading_position.update')).toHaveLength(2);
    expect(await repository.pendingReadingPosition(9, 'sha256:revision')).toEqual(second);

    await repository.applySyncResults([
      {
        client_action_id: first.client_action_id,
        outcome: 'applied',
        error: null,
        data: { chapter_id: 9, continuity },
      },
    ]);

    expect(await repository.pendingReadingPosition(9, 'sha256:revision')).toEqual(second);
    expect((await repository.chapterPackage(3, 9))?.reading_session?.continuity).toEqual(continuity);
  });

  it('chooses a pending resume by occurred time before same-device sequence', async () => {
    const repository = new OfflineRepository(7, 'English', new MemoryStore());
    const newer = await repository.enqueueReadingPosition(
      9,
      'sha256:revision',
      100,
      new Date('2026-08-01T00:00:02Z'),
    );
    await repository.enqueueReadingPosition(
      9,
      'sha256:revision',
      10,
      new Date('2026-08-01T00:00:01Z'),
    );

    expect(await repository.pendingReadingPosition(9, 'sha256:revision')).toEqual(newer);
  });

  it('deletes only the active user-language scope during local sign-out cleanup', async () => {
    const store = new MemoryStore();
    const current = new OfflineRepository(7, 'English', store);
    const other = new OfflineRepository(8, 'English', store);
    await current.saveArticles([{ book_id: 1, name: 'Current', language: 'English', chapter_count: 1 }]);
    await other.saveArticles([{ book_id: 2, name: 'Other', language: 'English', chapter_count: 1 }]);

    await current.clear();

    expect(await current.articles()).toBeNull();
    expect(await other.articles()).toHaveLength(1);
  });
});
