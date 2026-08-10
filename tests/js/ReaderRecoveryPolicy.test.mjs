import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

import {
    buildReaderExplicitRatingActionCommand,
    buildReaderFinishRequest,
    buildReadingInteractionRequest,
    clearReaderExplicitRatingRetry,
    clearReaderManualSenseContinuation,
    clearReadingSessionRecoveryId,
    createReaderActionId,
    filterCandidatesToReadingTarget,
    findReadingTargetForOpenedSelection,
    loadReaderExplicitRatingRetry,
    loadReaderManualSenseContinuation,
    loadReadingSessionRecoveryId,
    normalizeReaderEvidencePage,
    normalizeReaderFinishResult,
    normalizeReaderUnfamiliarSnapshot,
    normalizeReadingSessionResponse,
    readerExplicitActionConflictCode,
    readerExplicitRatingCommandMatchesSession,
    saveReaderExplicitRatingRetry,
    saveReaderManualSenseContinuation,
    saveReadingSessionRecoveryId,
} from '../../resources/js/services/ReaderRecoveryPolicy.js';

function memoryStorage() {
    const values = new Map();
    return {
        values,
        getItem: key => values.get(key) || null,
        setItem: (key, value) => values.set(key, value),
        removeItem: key => values.delete(key),
    };
}

const sessionPayload = {
    reading_session_id: '0bb9b36e-10a7-46c2-8aaa-686f9f398f45',
    chapter_id: 42,
    source_revision: 'rev-1',
    resumed: true,
    completed: false,
    reading_targets: [
        {
            occurrence_id: 'occ-bank',
            kind: 'word',
            purpose: 'passive_disambiguation',
            start_word_index: 4,
            end_word_index: 4,
            sentence_index: 1,
            surface: 'bank',
            lemma: 'bank',
            candidate_word_sense_ids: [81, 95],
            candidate_word_senses: [
                { word_sense_id: 81, sense_zh: '银行' },
                { word_sense_id: 95, sense_zh: '河岸' },
            ],
        },
        {
            occurrence_id: 'occ-phrase',
            kind: 'phrase',
            start_word_index: 8,
            end_word_index: 9,
            sentence_index: 2,
            surface: 'in light',
            candidate_word_sense_ids: [999],
            candidate_word_senses: [{ word_sense_id: 999 }],
        },
    ],
};

test('server-issued reading-session id is stored only as a chapter recovery pointer', () => {
    const storage = memoryStorage();
    assert.equal(loadReadingSessionRecoveryId(42, storage), '');
    assert.equal(saveReadingSessionRecoveryId(42, sessionPayload.reading_session_id, storage), true);
    assert.equal(loadReadingSessionRecoveryId(42, storage), sessionPayload.reading_session_id);
    assert.equal(loadReadingSessionRecoveryId(43, storage), '');
    assert.equal(clearReadingSessionRecoveryId(42, storage), true);
    assert.equal(loadReadingSessionRecoveryId(42, storage), '');
});

test('session response preserves server target identity and strips phrase rating candidates', () => {
    const normalized = normalizeReadingSessionResponse(sessionPayload, 42);
    assert.equal(normalized.readingSessionId, sessionPayload.reading_session_id);
    assert.equal(normalized.sourceRevision, 'rev-1');
    assert.equal(normalized.resumed, true);
    assert.equal(normalized.targets.length, 2);
    assert.deepEqual(normalized.targets[0].candidate_word_sense_ids, [81, 95]);
    assert.deepEqual(normalized.targets[1].candidate_word_senses, []);
    assert.equal(normalizeReadingSessionResponse(sessionPayload, 99), null);
});

test('opened word maps by server position and known-sense details can only narrow to server candidate ids', () => {
    const normalized = normalizeReadingSessionResponse(sessionPayload, 42);
    const target = findReadingTargetForOpenedSelection(normalized.targets, { start_word_index: 4, end_word_index: 4 });
    assert.equal(target.occurrence_id, 'occ-bank');
    const narrowed = filterCandidatesToReadingTarget(target, [
        { sense_id: 81, review_card_id: 181 },
        { sense_id: 95, review_card_id: 195 },
        { sense_id: 777, review_card_id: 777 },
    ]);
    assert.deepEqual(narrowed.map(item => item.word_sense_id), [81, 95]);
});

test('interaction request is invalid without both server session and occurrence identity', () => {
    assert.deepEqual(buildReadingInteractionRequest(sessionPayload.reading_session_id, 'occ-bank', 'opened'), {
        reading_session_id: sessionPayload.reading_session_id,
        interaction_type: 'opened',
        occurrence_id: 'occ-bank',
    });
    assert.equal(buildReadingInteractionRequest('', 'occ-bank', 'opened'), null);
    assert.equal(buildReadingInteractionRequest(sessionPayload.reading_session_id, '', 'opened'), null);
    assert.equal(buildReadingInteractionRequest(sessionPayload.reading_session_id, 'occ-bank', 'rated'), null);
});

test('unfamiliar snapshot keeps server snapshot version for V2 freshness', () => {
    assert.deepEqual(normalizeReaderUnfamiliarSnapshot({
        snapshot_version: 'snapshot-5',
        targets: [{ occurrence_id: 'mark-1', kind: 'word', start_word_index: 3, end_word_index: 3 }],
    }), {
        snapshotVersion: 'snapshot-5',
        targets: [{ occurrence_id: 'mark-1', kind: 'word', start_word_index: 3, end_word_index: 3 }],
    });
});

test('server target normalizers reject missing or unknown kind instead of guessing word', () => {
    const response = normalizeReadingSessionResponse({
        ...sessionPayload,
        reading_targets: [
            { occurrence_id: 'missing-kind', start_word_index: 1, end_word_index: 1 },
            { occurrence_id: 'unknown-kind', kind: 'other', start_word_index: 2, end_word_index: 2 },
        ],
    }, 42);
    assert.deepEqual(response.targets, []);

    assert.deepEqual(normalizeReaderUnfamiliarSnapshot({
        snapshot_version: 'snapshot-6',
        targets: [
            { occurrence_id: 'mark-1', kind: 'word', start_word_index: 3, end_word_index: 3 },
            { occurrence_id: 'mark-2', start_word_index: 4, end_word_index: 4 },
            { occurrence_id: 'mark-3', kind: 'other', start_word_index: 5, end_word_index: 5 },
            { kind: 'word', start_word_index: 6, end_word_index: 6 },
        ],
    }), {
        snapshotVersion: 'snapshot-6',
        targets: [{ occurrence_id: 'mark-1', kind: 'word', start_word_index: 3, end_word_index: 3 }],
    });
});

test('evidence completeness follows explicit pagination metadata and fails closed on malformed pages', () => {
    assert.deepEqual(normalizeReaderEvidencePage({ source_revision: 'rev-1', items: [{ occurrence_id: 'a' }], total: 2, offset: 0, limit: 1, has_more: true, next_offset: 1 }), {
        items: [{ occurrence_id: 'a' }], sourceRevision: 'rev-1', total: 2, offset: 0, limit: 1, hasMore: true, nextOffset: 1,
    });
    assert.equal(normalizeReaderEvidencePage({ source_revision: 'rev-1', items: [], total: 2, offset: 0, limit: 200, has_more: true, next_offset: null }), null);
});

test('Finish request supports only preflight/commit and consumes server completion fields', () => {
    assert.deepEqual(buildReaderFinishRequest({ chapterId: 42 }, sessionPayload.reading_session_id, 'preflight'), {
        chapterId: 42,
        reading_session_id: sessionPayload.reading_session_id,
        settlement_mode: 'preflight',
    });
    assert.equal(buildReaderFinishRequest({}, sessionPayload.reading_session_id, 'trust'), null);
    const result = normalizeReaderFinishResult({
        completed: false,
        preflight_required: true,
        can_commit: false,
        passive_good_count: 4,
        unresolved_count: 2,
        excluded_count: 3,
        already_settled_count: 1,
        unresolved_occurrence_ids: ['x', 'y'],
        conflict_codes: ['READING_FINISH_UNRESOLVED'],
    });
    assert.equal(result.passiveGoodCount, 4);
    assert.equal(result.unresolvedCount, 2);
    assert.equal(result.canCommit, false);
    assert.equal(normalizeReaderFinishResult({
        passive_good_count: 0,
        planned_passive_good_count: 4,
    }).passiveGoodCount, 0);
    assert.equal(normalizeReaderFinishResult({
        planned_passive_good_count: 4,
    }).passiveGoodCount, 4);
});

test('production Reader keeps recovery, explicit interaction, manual continuation, undo and reachable two-stage settlement without client session minting', () => {
    const reader = fs.readFileSync('resources/js/components/TextReader/TextReader.vue', 'utf8');
    const recovery = fs.readFileSync('resources/js/services/ReaderRecoveryPolicy.js', 'utf8');
    assert.match(reader, /resume_reading_session_id/);
    assert.match(reader, /reading-sessions\/interactions/);
    assert.match(reader, /known-sense-lookup/);
    assert.match(reader, /\/senses\/manual/);
    assert.match(reader, /reading-occurrence-evidence/);
    assert.match(reader, /undoSenseReviewAction/);
    assert.match(recovery, /settlement_mode/);
    assert.match(reader, /@click="preflightFinishSettlement"[^>]*>确认完成<\/v-btn>/);
    assert.doesNotMatch(reader, /@click="finish\(\)"/);
    assert.match(reader, /preflightFinishSettlement\(\)/);
    assert.match(reader, /buildReaderFinishRequest\(basePayload, this\.readingSessionId, 'preflight'\)/);
    assert.match(reader, /commitFinish\(\)/);
    assert.match(reader, /buildReaderFinishRequest\(basePayload, this\.readingSessionId, 'commit'\)/);
    assert.doesNotMatch(recovery, /readingSessionId\s*=\s*createReaderRequestId/);
});

test('completed session replay still requires matching server identity and source revision', () => {
    const completed = {
        completed: true,
        chapter_id: 42,
        reading_session_id: '0bb9b36e-10a7-46c2-8aaa-686f9f398f45',
        source_revision: 'rev-1',
        settlement_mode: 'commit',
    };
    assert.equal(normalizeReadingSessionResponse(completed, 42).completed, true);
    assert.equal(normalizeReadingSessionResponse({ ...completed, reading_session_id: '' }, 42), null);
    assert.equal(normalizeReadingSessionResponse({ ...completed, source_revision: '' }, 42), null);
    assert.equal(normalizeReadingSessionResponse({ ...completed, chapter_id: 99 }, 42), null);
});

test('evidence page rejects dishonest final completeness and discontinuous offsets', () => {
    assert.equal(normalizeReaderEvidencePage({
        source_revision: 'rev-1', items: [{ occurrence_id: 'a' }], total: 2, offset: 0, limit: 200, has_more: false, next_offset: null,
    }), null);
    assert.equal(normalizeReaderEvidencePage({
        source_revision: 'rev-1', items: [{ occurrence_id: 'a' }], total: 2, offset: 0, limit: 1, has_more: true, next_offset: 2,
    }), null);
});

test('production Reader fails closed on stale source and scopes interaction acknowledgements to the active server session', () => {
    const reader = fs.readFileSync('resources/js/components/TextReader/TextReader.vue', 'utf8');
    assert.match(reader, /code === 'READING_SESSION_STALE_SOURCE'[\s\S]{0,180}invalidateStaleReadingSession/);
    assert.match(reader, /文章内容已在服务器发生变化/);
    assert.match(reader, /const sessionId = this\.readingSessionId/);
    assert.match(reader, /const sourceRevision = this\.readingSourceRevision/);
    assert.match(reader, /resolveReaderInteractionAttempt\(/);
    assert.match(reader, /current\.sessionId === sessionId && current\.sourceRevision === sourceRevision/);
    assert.match(reader, /this\.readingInteractionPromises\[key\] === promise/);
    assert.match(reader, /allItems\.length !== expectedTotal/);
});

test('production Finish accepts only matching server identity and expected settlement mode', () => {
    const reader = fs.readFileSync('resources/js/components/TextReader/TextReader.vue', 'utf8');
    assert.match(reader, /normalized\.chapterId === Number\(this\.chapterId\)/);
    assert.match(reader, /normalized\.readingSessionId === this\.readingSessionId/);
    assert.match(reader, /normalized\.sourceRevision === this\.readingSourceRevision/);
    assert.match(reader, /normalized\.settlementMode === expectedMode/);
    assert.match(reader, /readerFinishContractInvalid/);
});

test('stale reading source disables server-mutating unfamiliar and AI paths until page refresh', () => {
    const reader = fs.readFileSync('resources/js/components/TextReader/TextReader.vue', 'utf8');
    assert.match(reader, /readingSourceStale = true/);
    assert.match(reader, /markedUnfamiliarSnapshotVersion = ''/);
    assert.match(reader, /:disabled="readingSourceStale" @click="openAiAssistDialog"/);
    assert.match(reader, /文章内容已经变化，请先刷新本章/);
});


test('evidence page is invalid without a server source revision', () => {
    assert.equal(normalizeReaderEvidencePage({ items: [], total: 0, offset: 0, limit: 200, has_more: false, next_offset: null }), null);
});

test('manual-sense continuation survives a page refresh inside the chapter session and can be cleared after reconciliation', () => {
    const storage = memoryStorage();
    const pending = {
        occurrenceId: 'occ-bank',
        rating: 'good',
        outcomeUnknown: true,
        sourceRevision: 'rev-1',
        readingSessionId: 'session-1',
    };
    assert.equal(saveReaderManualSenseContinuation(42, pending, storage), true);
    assert.deepEqual(loadReaderManualSenseContinuation(42, storage), {
        occurrenceId: 'occ-bank',
        rating: 'good',
        senseId: null,
        reviewCardId: null,
        outcomeUnknown: true,
        sourceRevision: 'rev-1',
        readingActionId: '',
        readingSessionId: 'session-1',
    });
    assert.equal(loadReaderManualSenseContinuation(43, storage), null);
    assert.equal(clearReaderManualSenseContinuation(42, storage), true);
    assert.equal(loadReaderManualSenseContinuation(42, storage), null);
});

test('manual-sense continuation persistence rejects malformed or incomplete state', () => {
    const storage = memoryStorage();
    assert.equal(saveReaderManualSenseContinuation(42, { occurrenceId: 'occ-bank', rating: 'good' }, storage), false);
    assert.equal(saveReaderManualSenseContinuation(42, { occurrenceId: 'occ-bank', rating: 'good', outcomeUnknown: true, sourceRevision: 'rev-1' }, storage), false);
    assert.equal(saveReaderManualSenseContinuation(42, { occurrenceId: 'occ-bank', rating: 'good', outcomeUnknown: true, sourceRevision: 'rev-1', readingSessionId: 'session-1' }, storage), true);
    assert.equal(saveReaderManualSenseContinuation(42, { occurrenceId: 'occ-bank', rating: 'later', outcomeUnknown: true }, storage), false);
    storage.setItem('linguacafe-reader-manual-sense-continuation:42', '{not-json');
    assert.equal(loadReaderManualSenseContinuation(42, storage), null);
});

test('production Reader restores and persists manual-sense continuation rather than dropping it on refresh or unrelated review', () => {
    const reader = fs.readFileSync('resources/js/components/TextReader/TextReader.vue', 'utf8');
    assert.match(reader, /loadReaderManualSenseContinuation\(this\.chapterId\)/);
    assert.match(reader, /setPendingManualSenseContinuation\(continuation\)/);
    assert.match(reader, /saveReaderManualSenseContinuation\(this\.chapterId, continuation\)/);
    assert.match(reader, /clearReaderManualSenseContinuation\(this\.chapterId\)/);
    assert.match(reader, /原评分已保存在本章会话中/);
    assert.match(reader, /manual-create-blocked="manualSenseCreateBlocked"/);
    assert.doesNotMatch(reader, /pendingManualSenseContinuation\.occurrenceId !== this\.inlineReviewOccurrence\.occurrence_id\)[\s\S]{0,120}pendingManualSenseContinuation = null/);
});

test('Reader action ids use secure browser randomness and fail closed without a secure source', () => {
    const direct = 'ed45a9e1-f2fe-4a2f-97fc-9bf819f6f2c1';
    assert.equal(createReaderActionId({ randomUUID: () => direct }), direct);

    const fallback = createReaderActionId({
        getRandomValues(bytes) {
            for (let index = 0; index < bytes.length; index += 1) bytes[index] = index;
            return bytes;
        },
    });
    assert.match(fallback, /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    assert.equal(createReaderActionId({}), '');

    const sequence = [
        'd69193d6-cf47-45d1-8ca5-e85b4007a090',
        '3203554b-a6d6-4d52-9bb8-8c6c7301dd75',
    ];
    const sequenceSource = { randomUUID: () => sequence.shift() };
    const firstActionId = createReaderActionId(sequenceSource);
    const secondActionId = createReaderActionId(sequenceSource);
    assert.notEqual(firstActionId, secondActionId);

    const recovery = fs.readFileSync('resources/js/services/ReaderRecoveryPolicy.js', 'utf8');
    assert.doesNotMatch(recovery, /Math\.random/);
});

test('explicit rating action preparation binds identity, reuses retries, and mints a new id for a new intent', () => {
    const base = {
        reviewCardId: 195,
        wordSenseId: 95,
        occurrenceId: 'occ-bank',
        sourceRevision: 'rev-1',
        payload: {
            rating: 'hard',
            reading_session_id: sessionPayload.reading_session_id,
            occurrence_id: 'occ-bank',
        },
    };
    assert.equal(readerExplicitRatingCommandMatchesSession(base, sessionPayload.reading_session_id, 'rev-1'), true);
    assert.equal(readerExplicitRatingCommandMatchesSession(base, 'other-session', 'rev-1'), false);
    assert.equal(readerExplicitRatingCommandMatchesSession(base, sessionPayload.reading_session_id, 'rev-2'), false);
    assert.equal(readerExplicitRatingCommandMatchesSession({
        ...base,
        payload: { ...base.payload, occurrence_id: 'other-occurrence' },
    }, sessionPayload.reading_session_id, 'rev-1'), false);

    const actionIds = [
        '5bf75efa-1b63-4b1a-907d-3f6019be0f13',
        'dde353e8-54b2-4420-8cc0-4bcf78b5c83e',
    ];
    const cryptoSource = { randomUUID: () => actionIds.shift() };
    const first = buildReaderExplicitRatingActionCommand(base, '', cryptoSource);
    assert.equal(first.payload.reading_action_id, '5bf75efa-1b63-4b1a-907d-3f6019be0f13');

    const exactRetry = buildReaderExplicitRatingActionCommand(first, '', {});
    assert.deepEqual(exactRetry, first);
    const recoveredRetry = buildReaderExplicitRatingActionCommand(base, first.payload.reading_action_id, {});
    assert.equal(recoveredRetry.payload.reading_action_id, first.payload.reading_action_id);

    const newIntent = buildReaderExplicitRatingActionCommand(base, '', cryptoSource);
    assert.equal(newIntent.payload.reading_action_id, 'dde353e8-54b2-4420-8cc0-4bcf78b5c83e');
    assert.notEqual(newIntent.payload.reading_action_id, first.payload.reading_action_id);
    assert.equal(buildReaderExplicitRatingActionCommand(base, 'predictable-id', cryptoSource), null);
});

test('explicit action conflict parser recognizes only the two server error_code values', () => {
    assert.equal(readerExplicitActionConflictCode({ error_code: 'READING_EXPLICIT_ACTION_UNDONE' }), 'READING_EXPLICIT_ACTION_UNDONE');
    assert.equal(readerExplicitActionConflictCode({ error_code: 'READING_EXPLICIT_ACTION_ACTIVE' }), 'READING_EXPLICIT_ACTION_ACTIVE');
    assert.equal(readerExplicitActionConflictCode({ code: 'READING_EXPLICIT_ACTION_ACTIVE' }), '');
    assert.equal(readerExplicitActionConflictCode({ error_code: 'READING_SESSION_STALE_SOURCE' }), '');
    assert.equal(readerExplicitActionConflictCode({}), '');
});

test('outcome-unknown explicit rating recovery preserves the exact action and semantic command across refresh', () => {
    const storage = memoryStorage();
    const command = {
        reviewCardId: 195,
        wordSenseId: 95,
        occurrenceId: 'occ-bank',
        sourceRevision: 'rev-1',
        payload: {
            rating: 'hard',
            reading_session_id: sessionPayload.reading_session_id,
            occurrence_id: 'occ-bank',
            reading_action_id: '23cb8132-11db-43f7-a5c7-a5a52ae14d19',
        },
    };
    assert.equal(saveReaderExplicitRatingRetry(42, command, storage), true);
    assert.deepEqual(loadReaderExplicitRatingRetry(42, storage), command);
    assert.equal(clearReaderExplicitRatingRetry(42, storage), true);
    assert.equal(loadReaderExplicitRatingRetry(42, storage), null);
});

test('explicit retry storage fails closed when sessionStorage writes or clears throw', () => {
    const command = {
        reviewCardId: 195,
        wordSenseId: 95,
        occurrenceId: 'occ-bank',
        sourceRevision: 'rev-1',
        payload: {
            rating: 'hard',
            reading_session_id: sessionPayload.reading_session_id,
            occurrence_id: 'occ-bank',
            reading_action_id: '23cb8132-11db-43f7-a5c7-a5a52ae14d19',
        },
    };
    assert.equal(saveReaderExplicitRatingRetry(42, command, {
        setItem() { throw new Error('quota exceeded'); },
    }), false);
    assert.equal(clearReaderExplicitRatingRetry(42, {
        removeItem() { throw new Error('storage unavailable'); },
    }), false);
});

test('explicit rating recovery rejects missing or malformed action identity instead of minting a replacement', () => {
    const storage = memoryStorage();
    const base = {
        reviewCardId: 195,
        wordSenseId: 95,
        occurrenceId: 'occ-bank',
        sourceRevision: 'rev-1',
        payload: {
            rating: 'hard',
            reading_session_id: sessionPayload.reading_session_id,
            occurrence_id: 'occ-bank',
        },
    };
    assert.equal(saveReaderExplicitRatingRetry(42, base, storage), false);
    assert.equal(saveReaderExplicitRatingRetry(42, {
        ...base,
        payload: { ...base.payload, reading_action_id: 'predictable-id' },
    }, storage), false);
});

test('manual-sense continuation keeps the formal action id only after formal rating has started', () => {
    const storage = memoryStorage();
    const continuation = {
        occurrenceId: 'occ-bank',
        rating: 'good',
        senseId: 95,
        reviewCardId: 195,
        outcomeUnknown: false,
        sourceRevision: 'rev-1',
        readingActionId: '7bc5f158-414d-4b94-9824-b8910ddf2a2d',
        readingSessionId: sessionPayload.reading_session_id,
    };
    assert.equal(saveReaderManualSenseContinuation(42, continuation, storage), true);
    assert.deepEqual(loadReaderManualSenseContinuation(42, storage), continuation);
    assert.equal(saveReaderManualSenseContinuation(42, { ...continuation, readingSessionId: '' }, storage), false);
    assert.equal(saveReaderManualSenseContinuation(42, { ...continuation, sourceRevision: '' }, storage), false);
});
