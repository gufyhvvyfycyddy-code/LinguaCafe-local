import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

import {
    normalizeReaderAiAssistPreview,
    normalizeReaderAiAssistSourceMeta,
    readerAiAssistErrorMessage,
    readerAiAssistResultLabel,
} from '../../resources/js/services/ReaderAiAssistV2Policy.js';

test('source metadata preserves server-authoritative target and package counts', () => {
    assert.deepEqual(normalizeReaderAiAssistSourceMeta({
        schema_version: 'linguacafe_ai_reading_assist_v2',
        target_count: 50,
        package_count: 2,
        prompt: 'prompt',
    }), {
        schemaVersion: 'linguacafe_ai_reading_assist_v2',
        targetCount: 50,
        packageCount: 2,
        packages: [],
        prompt: 'prompt',
    });
});

test('normalizes V2 word results while preserving V1-compatible detail aliases', () => {
    const normalized = normalizeReaderAiAssistPreview({
        schema_version: 'linguacafe_ai_reading_assist_v2',
        package_id: 'pkg-1',
        word_results: [{
            occurrence_id: 'occ2-bank', surface: 'bank', lemma: 'bank', pos: 'NOUN',
            result: 'matched_existing', matched_word_sense_id: 95,
            sense_zh: '河岸', sense_en: 'land along a river', confidence: 'high',
        }],
        phrase_results: [],
        sentence_translations: [],
        warnings: [],
    });
    assert.equal(normalized.items.vocabulary_items[0].meaning_zh, '河岸');
    assert.equal(normalized.items.vocabulary_items[0].suggested_lemma, 'bank');
    assert.equal(normalized.summary.target_count, 1);
    assert.equal(readerAiAssistResultLabel('matched_existing'), '匹配已学词义');
});

test('keeps V1 preview payload readable during additive migration', () => {
    const normalized = normalizeReaderAiAssistPreview({
        summary: { vocabulary_item_count: 1, phrase_item_count: 0, sentence_translation_count: 0, warning_count: 0 },
        items: { vocabulary_items: [{ surface: 'bank', suggested_lemma: 'bank', meaning_zh: '银行' }], phrase_items: [], sentence_translations: [], warnings: [] },
    });
    assert.equal(normalized.items.vocabulary_items[0].sense_zh, '银行');
    assert.equal(normalized.summary.vocabulary_item_count, 1);
});

test('maps stable V2 machine errors to user-understandable messages', () => {
    assert.match(readerAiAssistErrorMessage({ code: 'V2_STALE_SOURCE' }), /文章内容已经变化/);
    assert.match(readerAiAssistErrorMessage({ code: 'V2_CANDIDATE_MISMATCH' }), /当前候选/);
});

test('Reader AI Assist requests V2 source contract and shows new result fields', () => {
    const source = fs.readFileSync('resources/js/components/TextReader/TextReaderAiAssist.vue', 'utf8');
    assert.match(source, /buildReaderAiAssistV2SourceRequest\(this\.chapterId, this\.markedTargets\)/);
    assert.match(source, /sourceMeta\.targetCount/);
    assert.match(source, /sourceMeta\.packageCount/);
    assert.match(source, /resultLabel\(vi\.result\)/);
    assert.match(source, /vi\.sense_en/);
});
