import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname.replace(/^\/(.:)/, '$1')), '../..');
const helperPath = path.join(root, 'resources/js/services/ManualWordSenseFormService.js');

function methodBody(source, signature) {
    const start = source.indexOf(signature);
    assert.notEqual(start, -1, `${signature} must exist`);
    let depth = 0;
    for (let index = source.indexOf('{', start); index < source.length; index += 1) {
        if (source[index] === '{') depth += 1;
        if (source[index] === '}') depth -= 1;
        if (depth === 0) return source.slice(start, index + 1);
    }
    assert.fail(`${signature} body must be readable`);
}

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
    ADJ: 'adjective',
    NOUN: 'noun',
    adjective: 'adjective',
    other: 'other',
})) {
    assert.equal(helper.normalizeWordSensePos(input), expected, `${input} must normalize to ${expected}`);
}
assert.equal(helper.normalizeWordSensePos('mystery-pos'), '', 'unknown POS must stay invalid');

const pos422 = { response: { status: 422, data: { errors: { pos: ['The selected pos is invalid.'] } } } };
const sense422 = { response: { status: 422, data: { errors: { sense_zh: ['The sense zh field is required.'] } } } };
const other422 = { response: { status: 422, data: { errors: { lemma: ['The lemma field is required.'] } } } };

assert.deepEqual(helper.manualSenseValidationState(pos422, 'fallback'), {
    fieldErrors: { pos: '词性格式无效，请重新选择词性。', sense_zh: '' },
    generalError: '',
});
assert.deepEqual(helper.manualSenseValidationState(sense422, 'fallback'), {
    fieldErrors: { pos: '', sense_zh: '请先填写中文释义。' },
    generalError: '',
});
assert.deepEqual(helper.manualSenseValidationState(other422, 'fallback'), {
    fieldErrors: { pos: '', sense_zh: '' },
    generalError: 'The lemma field is required.',
});
assert.equal(
    helper.manualSenseValidationState({ response: { status: 422, data: { errors: { lemma: ['<html>secret</html>'] } } } }, 'fallback').generalError,
    'fallback',
);
assert.equal(
    helper.manualSenseValidationState({ response: { status: 500, data: '<html>secret</html>' } }, 'fallback').generalError,
    'fallback',
);

const component = fs.readFileSync(path.join(root, 'resources/js/components/Text/WordSensesList.vue'), 'utf8');
assert.match(component, /normalizeWordSensePos/, 'AI, dictionary, create, and edit paths must use the shared POS normalizer');
assert.match(component, /manualSenseValidationState/, 'create and edit catches must use structured validation errors');
assert.match(component, /normalizeWordSensePos\(prefill\.pos\)\s*\|\|\s*prefill\.pos/, 'unknown prefill POS must stay invalid instead of pretending to be canonical');
assert.match(component, /response\.data\.updated_word/, 'successful create must keep consuming updated_word');
assert.match(component, /\$emit\(['"]word-learning-updated['"]/, 'successful create must keep the reader event chain');
assert.doesNotMatch(component, /catch[\s\S]{0,240}closeAddForm\s*\(/, 'failed saves must keep the add form open');
const saveMethods = methodBody(component, '        createSense() {') + methodBody(component, '        saveEdit(sense) {');
assert.doesNotMatch(saveMethods, /ReviewLog|fsrs|stage\s*[:=]\s*-\d/i, 'save methods must not copy ReviewLog, FSRS, or stage-transition logic');

console.log('Manual WordSense POS and error UX contract passed.');
