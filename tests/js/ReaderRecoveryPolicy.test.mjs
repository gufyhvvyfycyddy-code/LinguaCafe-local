import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

import {
    buildReaderFinishRequest,
    buildReadingInteractionRequest,
    clearReaderManualSenseContinuation,
    clearReadingSessionRecoveryId,
    filterCandidatesToReadingTarget,
    findReadingTargetForOpenedSelection,
    loadReaderManualSenseContinuation,
    loadReadingSessionRecoveryId,
    normalizeReaderEvidencePage,
    normalizeReaderFinishResult,
    normalizeReaderUnfamiliarSnapshot,
    normalizeReadingSessionResponse,
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
});

test('production Reader wires recovery, explicit interaction, manual continuation, undo and two-stage Finish without client session minting', () => {
    const reader = fs.readFileSync('resources/js/components/TextReader/TextReader.vue', 'utf8');
    const recovery = fs.readFileSync('resources/js/services/ReaderRecoveryPolicy.js', 'utf8');
    assert.match(reader, /resume_reading_session_id/);
    assert.match(reader, /reading-sessions\/interactions/);
    assert.match(reader, /known-sense-lookup/);
    assert.match(reader, /\/senses\/manual/);
    assert.match(reader, /reading-occurrence-evidence/);
    assert.match(reader, /undoSenseReviewAction/);
    assert.match(recovery, /settlement_mode/);
    assert.match(reader, /'preflight'/);
    assert.match(reader, /'commit'/);
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
    assert.match(reader, /existing\.sessionId === sessionId/);
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
        form: { pos: 'noun', sense_zh: '堤岸', sense_en: 'river bank' },
    };
    assert.equal(saveReaderManualSenseContinuation(42, pending, storage), true);
    assert.deepEqual(loadReaderManualSenseContinuation(42, storage), {
        occurrenceId: 'occ-bank',
        rating: 'good',
        senseId: null,
        reviewCardId: null,
        outcomeUnknown: true,
        sourceRevision: 'rev-1',
        form: { pos: 'noun', sense_zh: '堤岸', sense_en: 'river bank' },
    });
    assert.equal(loadReaderManualSenseContinuation(43, storage), null);
    assert.equal(clearReaderManualSenseContinuation(42, storage), true);
    assert.equal(loadReaderManualSenseContinuation(42, storage), null);
});

test('manual-sense continuation persistence rejects malformed or incomplete state', () => {
    const storage = memoryStorage();
    assert.equal(saveReaderManualSenseContinuation(42, { occurrenceId: 'occ-bank', rating: 'good' }, storage), false);
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
    assert.match(reader, /保护状态已保存在本章会话中/);
    assert.doesNotMatch(reader, /pendingManualSenseContinuation\.occurrenceId !== this\.inlineReviewOccurrence\.occurrence_id\)[\s\S]{0,120}pendingManualSenseContinuation = null/);
});
