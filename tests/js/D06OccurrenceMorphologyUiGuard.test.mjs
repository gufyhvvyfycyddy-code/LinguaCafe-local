import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(here, '../../resources/js/components/Senses/SenseMappingReview.vue'), 'utf8');

test('review page shows occurrence and current-sense morphology', () => {
    assert.ok(source.includes('{{ occurrence.surface }}'));
    assert.ok(source.includes('{{ occurrence.lemma }}'));
    assert.ok(source.includes("{{ occurrence.pos || 'no pos' }}"));
    assert.ok(source.includes("{{ occurrence.sense.lemma || '—' }}"));
    assert.ok(source.includes("{{ occurrence.sense.pos || 'no pos' }}"));
});

test('pending existing-sense hint is gated by status, decision, and current sense', () => {
    assert.ok(source.includes(
        "v-if=\"occurrence.status === 'pending' && occurrence.decision === 'match_existing_sense' && occurrence.sense\""
    ));
    assert.ok(source.includes('请核对本次词元/词性与当前词义后再确认。'));
});

test('UI adds no morphology API or client-side morphology machinery', () => {
    assert.doesNotMatch(source, /(?:axios\.(?:get|post|put|patch|delete)|fetch)\s*\([^\n]*morpholog/i);
    assert.doesNotMatch(
        source,
        /ECDICT|dictionary|tokenizer|localStorage|sessionStorage|cache|retry|watchdog|needs_review|morphology_conflict|hasMorphologyConflict/i
    );
});

test('occurrence action row can wrap on narrow screens', () => {
    assert.ok(source.includes('class="d-flex align-center flex-wrap mb-2"'));
});

test('existing explicit review actions remain available', () => {
    const required = [
        '@click="confirmOccurrence(occurrence)"',
        '@click="openBind(occurrence)"',
        '@click="openCreate(occurrence)"',
        '@click="rejectOccurrence(occurrence)"',
        '@click="ignoreOccurrence(occurrence)"',
        'confirmOccurrence(occurrence) {',
        'bindOccurrence() {',
        'createSense() {',
        'rejectOccurrence(occurrence) {',
        'ignoreOccurrence(occurrence) {',
        '/confirm`',
        '/bind`',
        '/create-sense`',
    ];

    for (const marker of required) {
        assert.ok(source.includes(marker), `missing existing action marker: ${marker}`);
    }
});
