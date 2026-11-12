import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
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

// Exercise the real parent/child form methods: a generic Add must require a POS choice.
const parentMethods = ['emptyForm() {', 'emptyValidationState() {', 'hasValidationErrors(validation) {',
    "openAddForm(pos = '', prefill = null) {", 'onFormSubmit(formData) {', 'createSense() {']
    .map(signature => methodBody(component, `        ${signature}`)).join(',\n');
const parent = vm.runInNewContext(`({${parentMethods}})`, {
    normalizeWordSensePos: helper.normalizeWordSensePos,
    validateManualSenseForm: helper.validateManualSenseForm,
    axios: { post() { assert.fail('Invalid POS must not trigger a save request'); } },
});
const instance = { ...parent, createFormSession: 0, chapterId: null, sentenceIndex: null,
    sentenceText: '', encounteredWordId: null, readingContext: null };
instance.openAddForm();
assert.equal(instance.newForm.pos, '', 'generic Add must not silently preselect verb');
instance.onFormSubmit({ pos: '', sense_zh: '技术性' });
assert.ok(instance.createValidation.fieldErrors.pos, 'missing POS must block save');
instance.openAddForm('noun');
assert.equal(instance.newForm.pos, 'noun', 'group Add must still prefill its POS');
instance.openAddForm('adj', { pos: 'n', sense_zh: '名词义' });
assert.equal(instance.newForm.pos, 'noun', 'trusted candidate POS must still normalize');

const manualFormSource = fs.readFileSync(path.join(root, 'resources/js/components/Text/ManualSenseForm.vue'), 'utf8');
const child = vm.runInNewContext(`({${methodBody(manualFormSource, '        buildLocalForm(source) {')},
${methodBody(manualFormSource, '        submitForm() {')}})`, {
    validateManualSenseForm: helper.validateManualSenseForm,
});
assert.equal(child.buildLocalForm({}).pos, '', 'child must preserve unselected POS');
assert.equal(child.buildLocalForm({ pos: 'verb' }).pos, 'verb', 'child must preserve explicit POS');
let submitted = 0;
const childInstance = { ...child, localForm: child.buildLocalForm({ sense_zh: '技术性' }),
    $nextTick(callback) { callback(); }, focusFirstError() {}, $emit() { submitted += 1; } };
childInstance.submitForm();
assert.ok(childInstance.localFieldErrors.pos, 'child save button must expose missing POS');
assert.equal(submitted, 0, 'child save button must not submit without POS');
childInstance.localForm.pos = 'noun';
childInstance.submitForm();
assert.equal(submitted, 1, 'explicitly selected POS must permit submission');

console.log('Manual WordSense POS and error UX contract passed.');
