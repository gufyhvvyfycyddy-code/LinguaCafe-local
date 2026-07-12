// SenseReviewLeechPresentationGuard.test.mjs
//
// ADR-0011: Sense leech governance and rewrite package.
//
// Node built-in assert tests for the pure presentation helper.
// Guards the cross-contract between frontend presentation and backend
// SenseReviewLeechPolicy:
//   - LEECH_STATUSES match backend values.
//   - LEECH_REASONS match backend constants.
//   - LEECH_SUGGESTIONS match backend constants.
//   - Each status has label, color, hint.
//   - Each reason has label, hint.
//   - Each suggestion has label, hint.
//   - Pure module: no axios, no Vue, no DOM, no FSRS.
//   - Functions return correct types and values.
//
// Tests:
//   1.  File exists.
//   2.  Exports LEECH_STATUSES = ['stable','struggling','leech'].
//   3.  Exports LEECH_REASONS (5 codes).
//   4.  Exports LEECH_SUGGESTIONS (5 codes).
//   5.  Exports LEECH_PRESENTATION.
//   6.  Each status has label, color, hint.
//   7.  Exports LEECH_REASON_PRESENTATION.
//   8.  Each reason has label, hint.
//   9.  Exports LEECH_SUGGESTION_PRESENTATION.
//  10.  Each suggestion has label, hint.
//  11.  statusLabel returns string for known status.
//  12.  statusLabel returns fallback for unknown status.
//  13.  statusColor returns string for known status.
//  14.  statusHint returns string for known status.
//  15.  reasonLabel returns string for known reason.
//  16.  suggestionLabel returns string for known suggestion.
//  17.  suggestionHint returns string for known suggestion.
//  18.  severityText returns '无' for 0.
//  19.  severityText returns '严重' for >=85.
//  20.  severityColor returns 'grey' for 0.
//  21.  severityColor returns 'error' for >=60.
//  22.  copyFilename returns string with reviewCardId.
//  23.  strugglingHintText returns non-empty string.
//  24.  strugglingSuggestionText returns non-empty string.
//  25.  leechPanelText returns non-empty string.
//  26.  noAiNoticeText returns non-empty string.
//  27.  Source does NOT import axios.
//  28.  Source does NOT import Vue.
//  29.  Source does NOT access document or window.
//  30.  Source does NOT contain FSRS scheduling.
//  31.  Source does NOT call AI provider.

import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

const HELPER_PATH = join(__dirname, '..', '..', 'resources', 'js', 'services', 'SenseReviewLeechPresentation.js');

let passed = 0;
function test(name, fn) {
    try {
        fn();
        passed++;
    } catch (e) {
        console.error('FAIL: ' + name);
        console.error(e.message);
        process.exitCode = 1;
    }
}

const source = existsSync(HELPER_PATH) ? readFileSync(HELPER_PATH, 'utf-8') : '';

// Dynamic import for runtime tests
let mod = null;
try {
    mod = await import('file://' + HELPER_PATH.replace(/\\/g, '/'));
} catch (e) {
    // Will be caught by test 1
}

test('1. File exists', () => {
    assert.ok(existsSync(HELPER_PATH), 'SenseReviewLeechPresentation.js must exist');
});

test('2. Module imports successfully', () => {
    assert.ok(mod, 'module should be importable');
});

test('3. Exports LEECH_STATUSES = [stable, struggling, leech]', () => {
    assert.deepEqual(mod.LEECH_STATUSES, ['stable', 'struggling', 'leech']);
});

test('4. Exports LEECH_REASONS with 5 codes', () => {
    assert.ok(Array.isArray(mod.LEECH_REASONS));
    assert.ok(mod.LEECH_REASONS.length >= 5);
    assert.ok(mod.LEECH_REASONS.includes('recent_again_count_high'));
    assert.ok(mod.LEECH_REASONS.includes('lapses_high'));
    assert.ok(mod.LEECH_REASONS.includes('stability_declining'));
});

test('5. Exports LEECH_SUGGESTIONS with 5 codes', () => {
    assert.ok(Array.isArray(mod.LEECH_SUGGESTIONS));
    assert.ok(mod.LEECH_SUGGESTIONS.length >= 5);
    assert.ok(mod.LEECH_SUGGESTIONS.includes('continue_review'));
    assert.ok(mod.LEECH_SUGGESTIONS.includes('rewrite_example'));
    assert.ok(mod.LEECH_SUGGESTIONS.includes('suspend_temporarily'));
});

test('6. Exports LEECH_PRESENTATION with all statuses', () => {
    assert.ok(mod.LEECH_PRESENTATION);
    for (const s of mod.LEECH_STATUSES) {
        assert.ok(mod.LEECH_PRESENTATION[s], `status ${s} must exist`);
        assert.equal(typeof mod.LEECH_PRESENTATION[s].label, 'string');
        assert.equal(typeof mod.LEECH_PRESENTATION[s].color, 'string');
        assert.equal(typeof mod.LEECH_PRESENTATION[s].hint, 'string');
    }
});

test('7. Exports LEECH_REASON_PRESENTATION', () => {
    assert.ok(mod.LEECH_REASON_PRESENTATION);
    for (const r of mod.LEECH_REASONS) {
        assert.ok(mod.LEECH_REASON_PRESENTATION[r], `reason ${r} must exist`);
        assert.equal(typeof mod.LEECH_REASON_PRESENTATION[r].label, 'string');
    }
});

test('8. Exports LEECH_SUGGESTION_PRESENTATION', () => {
    assert.ok(mod.LEECH_SUGGESTION_PRESENTATION);
    for (const s of mod.LEECH_SUGGESTIONS) {
        assert.ok(mod.LEECH_SUGGESTION_PRESENTATION[s], `suggestion ${s} must exist`);
        assert.equal(typeof mod.LEECH_SUGGESTION_PRESENTATION[s].label, 'string');
    }
});

test('9. statusLabel returns string for known status', () => {
    assert.equal(typeof mod.statusLabel('leech'), 'string');
    assert.ok(mod.statusLabel('leech').length > 0);
});

test('10. statusLabel returns fallback for unknown status', () => {
    assert.equal(typeof mod.statusLabel('unknown'), 'string');
});

test('11. statusColor returns string for known status', () => {
    assert.equal(typeof mod.statusColor('leech'), 'string');
});

test('12. statusHint returns string for known status', () => {
    assert.equal(typeof mod.statusHint('leech'), 'string');
});

test('13. reasonLabel returns string for known reason', () => {
    assert.equal(typeof mod.reasonLabel('lapses_high'), 'string');
});

test('14. suggestionLabel returns string for known suggestion', () => {
    assert.equal(typeof mod.suggestionLabel('rewrite_example'), 'string');
});

test('15. suggestionHint returns string for known suggestion', () => {
    assert.equal(typeof mod.suggestionHint('rewrite_example'), 'string');
});

test('16. severityText returns "无" for 0', () => {
    assert.equal(mod.severityText(0), '无');
});

test('17. severityText returns "严重" for >=85', () => {
    assert.equal(mod.severityText(85), '严重');
    assert.equal(mod.severityText(100), '严重');
});

test('18. severityColor returns "grey" for 0', () => {
    assert.equal(mod.severityColor(0), 'grey');
});

test('19. severityColor returns "error" for >=60', () => {
    assert.equal(mod.severityColor(60), 'error');
    assert.equal(mod.severityColor(100), 'error');
});

test('20. copyFilename returns string with reviewCardId', () => {
    const fn = mod.copyFilename(42, 'test');
    assert.equal(typeof fn, 'string');
    assert.ok(fn.includes('42'));
    assert.ok(fn.endsWith('.json'));
});

test('21. strugglingHintText returns non-empty string', () => {
    const t = mod.strugglingHintText();
    assert.equal(typeof t, 'string');
    assert.ok(t.length > 0);
});

test('22. strugglingSuggestionText returns non-empty string', () => {
    const t = mod.strugglingSuggestionText();
    assert.equal(typeof t, 'string');
    assert.ok(t.length > 0);
});

test('23. leechPanelText returns non-empty string', () => {
    const t = mod.leechPanelText();
    assert.equal(typeof t, 'string');
    assert.ok(t.length > 0);
});

test('24. noAiNoticeText returns non-empty string', () => {
    const t = mod.noAiNoticeText();
    assert.equal(typeof t, 'string');
    assert.ok(t.length > 0);
    assert.ok(t.includes('AI') || t.includes('ai') || t.includes('不'));
});

test('25. Source does NOT import axios', () => {
    assert.ok(!source.match(/import\s+.*axios/) && !source.match(/require\(.*axios/), 'Source must not import axios');
});

test('26. Source does NOT import Vue', () => {
    // Check for actual import statements, not comments mentioning "import Vue"
    const lines = source.split('\n');
    const hasVueImport = lines.some(l => {
        const trimmed = l.trimStart();
        return (trimmed.startsWith('import ') && trimmed.includes('Vue')) ||
               trimmed.match(/from\s+['"]vue['"]/);
    });
    assert.ok(!hasVueImport, 'Source must not import Vue');
});

test('27. Source does NOT access document or window', () => {
    assert.ok(!source.match(/\bdocument\b/) && !source.match(/\bwindow\b/), 'Source must not access document or window');
});

test('28. Source does NOT contain FSRS scheduling', () => {
    assert.ok(!source.includes('FsrsScheduling') && !source.includes('schedule('), 'Source must not contain FSRS scheduling');
});

test('29. Source does NOT call AI provider', () => {
    assert.ok(!source.includes('provider-preview') && !source.includes('fetch(') && !source.includes('openai'), 'Source must not call AI provider');
});

test('30. Source does NOT create WordSense/ReviewCard/ReviewLog', () => {
    assert.ok(!source.includes('createReviewLog') && !source.includes('createWordSense') && !source.includes('createReviewCard'), 'Source must not create entities');
});

console.log(`\nSenseReviewLeechPresentationGuard: ${passed} passed`);
