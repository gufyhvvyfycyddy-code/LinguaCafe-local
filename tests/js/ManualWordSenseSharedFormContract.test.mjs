import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname.replace(/^\/(.:)/, '$1')), '../..');
const formPath = path.join(root, 'resources/js/components/Text/ManualSenseForm.vue');
const listPath = path.join(root, 'resources/js/components/Text/WordSensesList.vue');
const helperPath = path.join(root, 'resources/js/services/ManualWordSenseFormService.js');

assert.ok(fs.existsSync(formPath), 'create and edit must converge on ManualSenseForm.vue');
assert.ok(!fs.existsSync(path.join(root, 'resources/js/components/Text/AddSenseForm.vue')), 'the create-only form implementation must be removed');

const form = fs.readFileSync(formPath, 'utf8');
const list = fs.readFileSync(listPath, 'utf8');
const helperSource = fs.readFileSync(helperPath, 'utf8');
const helper = await import(`data:text/javascript;base64,${Buffer.from(helperSource).toString('base64')}`);

assert.match(form, /mode[\s\S]*create[\s\S]*edit/, 'shared form must explicitly support create and edit modes');
for (const field of ['pos', 'sense_zh', 'sense_en', 'aliases_zh', 'collocations']) {
    assert.match(form, new RegExp(`localForm\\.${field}`), `shared form must render ${field}`);
}
assert.match(form, /isCreate[\s\S]*example_sentence_en/, 'example sentence must be create-only');
assert.match(form, /isCreate[\s\S]*keep_new/, 'keep_new must be create-only');
assert.match(form, /ref="pos"[\s\S]*error-messages[\s\S]*ref="senseZh"[\s\S]*error-messages/, 'POS and Chinese meaning need focusable inline errors');
assert.match(form, /hide-details="auto"/, 'field errors must reserve only the space they need');
assert.match(form, /validateManualSenseForm\(this\.localForm\)/, 'shared form must run local validation before submit');
assert.match(form, /focusFirstError/, 'invalid forms must focus and scroll to their first invalid field');
assert.match(form, /\$emit\(['"]clear-error['"],\s*field\)/, 'editing a field must clear only that field error');
assert.doesNotMatch(form, /if\s*\([^)]*sense_zh[^)]*\)\s*\{\s*return;\s*\}/, 'empty Chinese meaning must not fail through the old silent guard');

assert.match(list, /import ManualSenseForm/, 'parent must import the shared form');
assert.equal((list.match(/<manual-sense-form\b/g) || []).length, 2, 'create and edit must both render the shared form');
assert.doesNotMatch(list, /<add-sense-form\b|import AddSenseForm/, 'parent must not retain the create-only form');
assert.doesNotMatch(list, /editingSenseId === sense\.id[\s\S]{0,1800}<v-select/, 'parent must not retain a second inline edit field template');
assert.match(list, /createValidation/, 'create must own structured validation state');
assert.match(list, /editValidation/, 'edit must own structured validation state');
assert.match(list, /:key="`create-\$\{createFormSession\}`"/, 'a new AI or dictionary prefill must start a fresh create-form session');
assert.match(list, /clearValidationField[\s\S]{0,300}generalError:\s*''[\s\S]{0,300}\[field\]:\s*''/, 'field input must clear its own field error and any stale general error');
assert.match(list, /manualSenseValidationState/, 'server failures must map to field and general errors');
assert.doesNotMatch(list, /catch[\s\S]{0,280}(closeAddForm|cancelEdit|reset)\s*\(/, 'failed saves must leave form state open and intact');
assert.match(list, /response\.data\.updated_word/, 'create success must consume updated_word');
assert.match(list, /\$emit\(['"]word-learning-updated['"]/, 'create success must preserve the reader update event');

assert.deepEqual(helper.validateManualSenseForm({ pos: '', sense_zh: 'meaning' }), {
    fieldErrors: { pos: '词性格式无效，请重新选择词性。', sense_zh: '' },
    generalError: '',
});
assert.deepEqual(helper.validateManualSenseForm({ pos: 'adj', sense_zh: '  ' }), {
    fieldErrors: { pos: '词性格式无效，请重新选择词性。', sense_zh: '请先填写中文释义。' },
    generalError: '',
});
assert.deepEqual(helper.validateManualSenseForm({ pos: 'adjective', sense_zh: ' 形容词 ' }), {
    fieldErrors: { pos: '', sense_zh: '' },
    generalError: '',
});
assert.match(helperSource, /const CANONICAL_POS = new Set\(\[[\s\S]*'other',[\s\S]*\]\)/, 'local validation must use the accepted canonical set');

console.log('Manual WordSense shared form validation contract passed.');
