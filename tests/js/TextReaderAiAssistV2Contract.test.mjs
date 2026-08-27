import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

import {
    buildReaderAiAssistV2ImportRequest,
    normalizeReaderAiAssistPreview,
    normalizeReaderAiAssistSourceMeta,
    readerAiAssistErrorMessage,
    readerAiAssistCandidatesForOccurrence,
    readerAiAssistPackageKey,
    readerAiAssistResultLabel,
    readerAiAssistV2InputsComplete,
} from '../../resources/js/services/ReaderAiAssistV2Policy.js';

test('source metadata preserves server-authoritative target and package counts', () => {
    assert.deepEqual(normalizeReaderAiAssistSourceMeta({
        schema_version: 'linguacafe_ai_reading_assist_v2',
        target_count: 50,
        package_count: 2,
        prompt: 'legacy-top-level-prompt',
    }), {
        targetCount: 50,
        packageCount: 2,
        packages: [],
    });
});

test('builds V2 multi-part import body from server-issued manifests and fails closed while a part is missing', () => {
    const sourceMeta = normalizeReaderAiAssistSourceMeta({
        schema_version: 'linguacafe_ai_reading_assist_v2',
        target_count: 51,
        package_count: 2,
        packages: [
            { part_index: 1, manifest_token: 'manifest-1', prompt: 'prompt-1' },
            { part_index: 2, manifest_token: 'manifest-2', prompt: 'prompt-2' },
        ],
    });

    assert.equal(readerAiAssistPackageKey(sourceMeta.packages[1]), '2');
    assert.equal(readerAiAssistV2InputsComplete(sourceMeta, { 1: '{"part":1}', 2: '' }), false);
    assert.equal(readerAiAssistV2InputsComplete(sourceMeta, { 1: '{"part":1}', 2: '{"part":2}' }), true);

    assert.deepEqual(buildReaderAiAssistV2ImportRequest(7, sourceMeta, {
        1: '{"part":1}',
        2: '{"part":2}',
    }, true), {
        chapterId: 7,
        schema_version: 'linguacafe_ai_reading_assist_v2',
        parts: [
            { manifest_token: 'manifest-1', ai_text: '{"part":1}' },
            { manifest_token: 'manifest-2', ai_text: '{"part":2}' },
        ],
        apply_trust_ai: true,
    });
});

test('resolves server-issued candidate senses by occurrence identity without a second source', () => {
    const sourceMeta = normalizeReaderAiAssistSourceMeta({
        schema_version: 'linguacafe_ai_reading_assist_v2',
        packages: [
            {
                part_index: 1,
                source_payload: {
                    word_targets: [{
                        occurrence_id: 'occ2-bank',
                        candidate_word_senses: [
                            { word_sense_id: 95, sense_zh: '河岸', sense_en: 'land along a river', pos: 'NOUN' },
                        ],
                    }],
                },
            },
            {
                part_index: 2,
                source_payload: {
                    word_targets: [{
                        occurrence_id: 'occ2-run',
                        candidate_word_senses: [
                            { word_sense_id: 96, sense_zh: '运行', sense_en: 'operate', pos: 'VERB' },
                        ],
                    }],
                },
            },
        ],
    });

    assert.deepEqual(readerAiAssistCandidatesForOccurrence(sourceMeta, 'occ2-run'), [
        { word_sense_id: 96, sense_zh: '运行', sense_en: 'operate', pos: 'VERB' },
    ]);
    assert.deepEqual(readerAiAssistCandidatesForOccurrence(sourceMeta, 'occ2-missing'), []);
});

test('normalizes V2 preview using only the V2 result fields', () => {
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
    assert.equal(normalized.items.word_results[0].sense_zh, '河岸');
    assert.equal(normalized.items.word_results[0].lemma, 'bank');
    assert.equal(normalized.items.vocabulary_items, undefined);
    assert.equal(normalized.items.phrase_items, undefined);
    assert.equal(normalized.summary.target_count, 1);
    assert.equal(readerAiAssistResultLabel('matched_existing'), '匹配已学词义');
});

test('maps only the server V2 error_code contract to user-understandable messages', () => {
    assert.match(readerAiAssistErrorMessage({ error_code: 'V2_STALE_SOURCE' }), /文章内容已经变化/);
    assert.match(readerAiAssistErrorMessage({ error_code: 'V2_CANDIDATE_MISMATCH' }), /当前候选/);
    assert.equal(readerAiAssistErrorMessage({ code: 'V2_STALE_SOURCE' }, 'fallback'), 'fallback');
});

test('V2 metadata keeps real aliases but does not invent missing contract fields', () => {
    const normalized = normalizeReaderAiAssistSourceMeta({
        contract_version: 'linguacafe_ai_reading_assist_v2',
        total_target_count: 99,
        part_count: 2,
        packages: [{ manifest_token: 'manifest-without-part-index' }],
    });

    assert.equal(normalized.targetCount, 0);
    assert.equal(normalized.packageCount, 2);
    assert.equal(readerAiAssistPackageKey(normalized.packages[0]), '');
    assert.equal(readerAiAssistV2InputsComplete(normalized, { 1: '{"part":1}' }), false);
});

test('Reader AI Assist requests V2 source contract, wires strict V2 import, and shows new result fields', () => {
    const source = fs.readFileSync('resources/js/components/TextReader/TextReaderAiAssist.vue', 'utf8');
    assert.match(source, /buildReaderAiAssistV2SourceRequest\(this\.chapterId, this\.markedTargets\)/);
    assert.match(source, /sourceMeta\.targetCount/);
    assert.match(source, /sourceMeta\.packageCount/);
    assert.doesNotMatch(source, /sourceMeta\.schemaVersion|schemaVersion:/);
    assert.match(source, /sourcePackages\.length > 1/);
    assert.match(source, /copyPackagePrompt\(pkg\)/);
    assert.doesNotMatch(source, /part_index \|\| index \+ 1|readerAiAssistPackageKey\(pkg, index\)/);
    assert.match(source, /buildReaderAiAssistV2ImportRequest/);
    assert.match(source, /this\.buildImportRequest\(false\)/);
    assert.match(source, /this\.buildImportRequest\(this\.trustAiReadingSenseBinding\)/);
    assert.doesNotMatch(source, /usesV2PackageImport|\baiText:\s*''|v-model="aiText"|aiText:\s*this\.aiText|sourceMeta\.prompt|items\.vocabulary_items|items\.phrase_items|suggested_lemma|meaning_zh/);
    assert.doesNotMatch(source, /axios\.post\('\/chapters\/ai-assist\/preview', \{\s*chapterId: this\.chapterId,\s*aiText:/s);
    assert.match(source, /resultLabel\(vi\.result\)/);
    assert.match(source, /vi\.sense_en/);
    assert.match(source, /vi\.result === 'new_sense' && existingCandidatesFor\(vi\)\.length/);
    assert.match(source, /AI 判断为新词义，但你已经学过这个词的其它词义。请先比较已有词义；如果意思相同或十分接近，不应新增。/);
    assert.match(source, /candidate\.sense_zh/);
    assert.match(source, /candidate\.sense_en/);
    assert.match(source, /candidate\.pos/);
    assert.match(source, /readerAiAssistCandidatesForOccurrence\(this\.sourceMeta, item\.occurrence_id\)/);
});

test('Reader AI V2 source sends the server unfamiliar-target snapshot version and fails closed before freshness is known', () => {
    const source = fs.readFileSync('resources/js/components/TextReader/TextReaderAiAssist.vue', 'utf8');
    assert.match(source, /markedTargetsSnapshotVersion/);
    assert.match(source, /marked_targets_snapshot_version:\s*this\.markedTargetsSnapshotVersion/);
    assert.match(source, /服务器标记快照尚未加载/);
});

test('successful AI confirm notifies Reader to refresh persisted evidence', () => {
    const assist = fs.readFileSync('resources/js/components/TextReader/TextReaderAiAssist.vue', 'utf8');
    const reader = fs.readFileSync('resources/js/components/TextReader/TextReader.vue', 'utf8');
    assert.match(assist, /this\.\$emit\('confirmed'\)/);
    assert.match(reader, /@confirmed="refreshReadingSenseVerification"/);
});
