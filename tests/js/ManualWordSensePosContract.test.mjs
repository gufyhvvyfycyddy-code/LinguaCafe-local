import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname.replace(/^\/(.:)/, '$1')), '../..');
const helperPath = path.join(root, 'resources/js/services/ManualWordSenseFormService.js');

assert.ok(fs.existsSync(helperPath), 'manual sense form contract helper must exist');

const helperSource = fs.readFileSync(helperPath, 'utf8');
const helper = await import(`data:text/javascript;base64,${Buffer.from(helperSource).toString('base64')}`);

for (const [input, expected] of Object.entries({
    n: 'noun',
    v: 'verb',
    adj: 'adjective',
    adv: 'adverb',
    prep: 'preposition',
    conj: 'conjunction',
    adjective: 'adjective',
    other: 'other',
})) {
    assert.equal(helper.normalizeWordSensePos(input), expected, `${input} must normalize to ${expected}`);
}
assert.equal(helper.normalizeWordSensePos('mystery-pos'), '', 'unknown POS must stay invalid');

const pos422 = { response: { status: 422, data: { errors: { pos: ['The selected pos is invalid.'] } } } };
const sense422 = { response: { status: 422, data: { errors: { sense_zh: ['The sense zh field is required.'] } } } };
const other422 = { response: { status: 422, data: { errors: { lemma: ['The lemma field is required.'] } } } };

assert.equal(helper.manualSenseErrorMessage(pos422, 'fallback'), '词性格式无效，请重新选择词性。');
assert.equal(helper.manualSenseErrorMessage(sense422, 'fallback'), '请先填写中文释义。');
assert.equal(helper.manualSenseErrorMessage(other422, 'fallback'), 'The lemma field is required.');
assert.equal(helper.manualSenseErrorMessage({ response: { status: 500, data: '<html>secret</html>' } }, 'fallback'), 'fallback');

const component = fs.readFileSync(path.join(root, 'resources/js/components/Text/WordSensesList.vue'), 'utf8');
assert.match(component, /normalizeWordSensePos/, 'AI, dictionary, create, and edit paths must use the shared POS normalizer');
assert.match(component, /manualSenseErrorMessage/, 'create and edit catches must use structured validation errors');
assert.match(component, /response\.data\.updated_word/, 'successful create must keep consuming updated_word');
assert.match(component, /\$emit\(['"]word-learning-updated['"]/, 'successful create must keep the reader event chain');
assert.doesNotMatch(component, /catch[\s\S]{0,240}closeAddForm\s*\(/, 'failed saves must keep the add form open');
assert.doesNotMatch(component, /setStage|ReviewLog|fsrs/i, 'frontend contract must not copy stage, ReviewLog, or FSRS logic');

console.log('Manual WordSense POS and error UX contract passed.');
