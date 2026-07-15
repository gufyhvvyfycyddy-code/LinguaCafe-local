import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const helperPath = join(__dirname, '..', '..', 'resources', 'js', 'services', 'FsrsAdvancedToolsPresentation.js');

assert.ok(existsSync(helperPath), 'FsrsAdvancedToolsPresentation.js must exist');
const { buildFsrsAdvancedToolsPresentation } = await import(pathToFileURL(helperPath).href);

function status(overrides = {}) {
    return {
        can_optimize: false,
        review_count: 0,
        min_required: 300,
        parameters_source: 'default',
        parameters_source_label: '当前使用默认参数',
        last_optimized_at: null,
        parameters_count: 19,
        diagnostics: {
            eligible_review_logs: 0,
            trainable_cards: 0,
            excluded_review_logs: 0,
            reset_review_logs: 0,
            confirmed_sense_cards: 0,
            rejected_word_senses: 0,
            min_required: 300,
            missing_review_logs: 300,
        },
        ...overrides,
    };
}

function assertState(name, response, options, expected) {
    const actual = buildFsrsAdvancedToolsPresentation(response, options);
    assert.equal(actual.dataState, expected, name);
    return actual;
}

assertState('loading', null, { loading: true }, 'loading');
assertState('error', null, { error: true }, 'error');

const empty = assertState('empty', status(), {}, 'empty');
assert.match(empty.primaryMessage, /还没有可用于参数优化的正式复习记录/);
assert.equal(empty.canPreviewOptimization, false);
assert.equal(empty.showDiagnosticDetails, false);

for (const count of [1, 117, 299]) {
    const view = assertState(`insufficient ${count}`, status({
        review_count: count,
        diagnostics: {
            ...status().diagnostics,
            eligible_review_logs: count,
            trainable_cards: Math.max(1, Math.floor(count / 3)),
            missing_review_logs: 300 - count,
        },
    }), {}, 'insufficient');
    assert.match(view.primaryMessage, new RegExp(`有效记录 ${count} / 300`));
    assert.match(view.primaryMessage, new RegExp(`还差 ${300 - count} 条`));
    assert.equal(view.canPreviewOptimization, false);
    assert.equal(view.showDiagnosticDetails, true);
    assert.equal(view.progressPercent, Math.round((count / 300) * 100));
}

for (const count of [300, 301]) {
    const view = assertState(`ready ${count}`, status({
        can_optimize: true,
        review_count: count,
        diagnostics: {
            ...status().diagnostics,
            eligible_review_logs: count,
            trainable_cards: 80,
            missing_review_logs: 0,
        },
    }), {}, 'ready');
    assert.equal(view.canPreviewOptimization, true);
    assert.equal(view.progressPercent, 100);
    assert.match(view.primaryMessage, /预览不会保存参数，也不会重排已有卡片/);
}

const defaults = buildFsrsAdvancedToolsPresentation(status(), {});
assert.equal(defaults.parameterState, 'default');
assert.equal(defaults.canRestoreDefaults, false);
assert.equal(defaults.restoreButtonLabel, '当前已是默认参数');

const optimized = buildFsrsAdvancedToolsPresentation(status({
    parameters_source: 'optimized',
    parameters_source_label: '正在优化参数',
    last_optimized_at: '2026-07-15T12:00:00+00:00',
    parameters_count: 19,
}), {});
assert.equal(optimized.parameterState, 'optimized');
assert.equal(optimized.canRestoreDefaults, true);
assert.equal(optimized.parameterCount, 19);
assert.equal(optimized.lastOptimizedAt, '2026-07-15T12:00:00+00:00');

const unknown = buildFsrsAdvancedToolsPresentation(status({ parameters_source: 'custom' }), {});
assert.equal(unknown.parameterState, 'unknown');
assert.equal(unknown.canRestoreDefaults, false);

const missingDiagnostics = buildFsrsAdvancedToolsPresentation({
    can_optimize: false,
    review_count: 117,
    min_required: 300,
    parameters_source: 'default',
}, {});
assert.equal(missingDiagnostics.dataState, 'insufficient');
assert.equal(missingDiagnostics.eligibleReviewLogs, 117);
assert.equal(missingDiagnostics.trainableCards, 0);

const invalidNumbers = buildFsrsAdvancedToolsPresentation(status({
    review_count: 'bad',
    parameters_count: 'not-a-number',
    diagnostics: {
        eligible_review_logs: -12,
        trainable_cards: Number.NaN,
        min_required: 'bad',
        excluded_review_logs: -2,
        reset_review_logs: 'bad',
    },
}), {});
assert.equal(invalidNumbers.eligibleReviewLogs, 0);
assert.equal(invalidNumbers.trainableCards, 0);
assert.equal(invalidNumbers.minRequired, 300);
assert.equal(invalidNumbers.parameterCount, 19);

const input = status({
    review_count: 117,
    diagnostics: {
        ...status().diagnostics,
        eligible_review_logs: 117,
        trainable_cards: 40,
    },
});
const before = JSON.stringify(input);
buildFsrsAdvancedToolsPresentation(input, {});
assert.equal(JSON.stringify(input), before, 'response object must not be mutated');

console.log('FsrsAdvancedToolsPresentation: 17 state contracts passed.');
