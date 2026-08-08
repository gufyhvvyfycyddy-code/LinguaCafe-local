import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

import {
    buildReadingSenseResolutionIntent,
    isTrustAiVerified,
    isReadingSenseWordTarget,
    mergeReadingSenseVerificationItems,
    readingSenseVerificationState,
    readingSenseVerificationSummary,
} from '../../resources/js/services/ReadingSenseVerificationPolicy.js';

const item = {
    occurrence_id: 'occ2_bank',
    result: 'matched_existing',
    confidence: 'high',
    candidate_word_senses: [
        { word_sense_id: 81, sense_zh: '银行', sense_en: 'financial institution', pos: 'NOUN' },
        { word_sense_id: 95, sense_zh: '河岸', sense_en: 'land along a river', pos: 'NOUN' },
    ],
};

test('verification state is driven by server evidence, not AI confidence alone', () => {
    assert.equal(readingSenseVerificationState(item), 'pending');
    assert.equal(readingSenseVerificationState({ ...item, evidence: { resolution: 'matched_existing' } }), 'verified');
    assert.equal(readingSenseVerificationState({ ...item, evidence: { resolution: 'new_sense' } }), 'verified');
    assert.equal(readingSenseVerificationState({ ...item, evidence: { resolution: 'excluded' } }), 'excluded');
});

test('trust-AI badge requires server evidence plus high matched-existing result', () => {
    const trusted = { ...item, evidence: { resolution: 'matched_existing', resolution_source: 'trust_ai' } };
    assert.equal(isTrustAiVerified(trusted), true);
    assert.equal(isTrustAiVerified({ ...trusted, confidence: 'medium' }), false);
    assert.equal(isTrustAiVerified({ ...trusted, result: 'ambiguous' }), false);
});

test('existing-sense resolution accepts only server-provided candidate ids', () => {
    assert.deepEqual(buildReadingSenseResolutionIntent(item, 'match_existing', 95), {
        occurrence_id: 'occ2_bank',
        resolution: 'matched_existing',
        word_sense_id: 95,
    });
    assert.equal(buildReadingSenseResolutionIntent(item, 'match_existing', 999), null);
});

test('new sense and exclude remain non-rating evidence intents for word targets', () => {
    assert.deepEqual(buildReadingSenseResolutionIntent(item, 'new_sense'), {
        occurrence_id: 'occ2_bank', resolution: 'new_sense', word_sense_id: null,
    });
    assert.deepEqual(buildReadingSenseResolutionIntent(item, 'exclude'), {
        occurrence_id: 'occ2_bank', resolution: 'excluded', word_sense_id: null,
    });
});

test('phrase targets cannot create WordSense binding or evidence intents', () => {
    const phrase = {
        ...item,
        occurrence_id: 'occ2_phrase',
        target_type: 'phrase',
        phrase: 'in light of',
    };
    assert.equal(isReadingSenseWordTarget(phrase), false);
    assert.equal(buildReadingSenseResolutionIntent(phrase, 'match_existing', 81), null);
    assert.equal(buildReadingSenseResolutionIntent(phrase, 'new_sense'), null);
    assert.equal(buildReadingSenseResolutionIntent(phrase, 'exclude'), null);
});

test('summary handles 20–50 bounded chapter rows without per-row network semantics', () => {
    const items = Array.from({ length: 50 }, (_, index) => ({
        occurrence_id: `occ-${index}`,
        evidence: index < 20 ? { resolution: 'matched_existing' }
            : (index < 25 ? { resolution: 'excluded' } : null),
    }));
    assert.deepEqual(readingSenseVerificationSummary(items), {
        total: 50, pending: 25, verified: 20, excluded: 5,
    });
});

test('verification dialog is chapter-level and never mounts InlineSensePreviewPanel per row', () => {
    const source = fs.readFileSync('resources/js/components/TextReader/ReadingSenseVerificationDialog.vue', 'utf8');
    assert.doesNotMatch(source, /InlineSensePreviewPanel|inline-sense-preview-panel/);
    assert.match(source, /本次不计入被动复习/);
    assert.match(source, /改选已学词义/);
    assert.match(source, /词组只展示 AI 释义，不进入 WordSense 绑定或被动复习/);
});


test('verification merge keeps server target candidates while overlaying persisted evidence', () => {
    const merged = mergeReadingSenseVerificationItems({
        targets: [{
            occurrence_id: 'occ2_bank',
            kind: 'word',
            start_word_index: 4,
            candidate_word_senses: item.candidate_word_senses,
        }],
        assistItems: [{ occurrence_id: 'occ2_bank', result: 'matched_existing', confidence: 'high' }],
        evidenceItems: [{
            occurrence_id: 'occ2_bank',
            resolution: 'matched_existing',
            word_sense_id: 95,
            binding_current: true,
            candidate_word_senses: item.candidate_word_senses,
        }],
    });
    assert.equal(merged.length, 1);
    assert.equal(merged[0].evidence.resolution, 'matched_existing');
    assert.equal(merged[0].evidence.word_sense_id, 95);
    assert.equal(merged[0].candidate_word_senses.length, 2);
    assert.equal(readingSenseVerificationState(merged[0]), 'verified');
});
