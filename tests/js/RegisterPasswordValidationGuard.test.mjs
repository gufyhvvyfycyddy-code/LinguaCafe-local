import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const source = fs.readFileSync(
    path.join(root, 'resources/js/components/Login/LoginForm.vue'),
    'utf8',
);

test('registration confirmation owns cross-field validity instead of relying on stale Vuetify rules', () => {
    assert.match(source, /v-form ref="form" v-model="baseFormValid"/);
    assert.match(source, /@input="updatePassword"/);
    assert.match(source, /@input="updatePasswordConfirmation"/);
    assert.match(source, /:error-messages="passwordConfirmationError"/);
    assert.match(source, /livePassword:\s*this\.password/);
    assert.match(source, /livePasswordConfirmation:\s*this\.passwordConfirmation/);
    assert.match(source, /passwordsMatch\(\)\s*\{[\s\S]*this\.livePasswordConfirmation === this\.livePassword/);
    assert.match(source, /Boolean\(this\.baseFormValid && this\.passwordsMatch\)/);
    assert.match(source, /return Boolean\(baseValid && this\.passwordsMatch\)/);
    assert.doesNotMatch(source, /rules\.passwordMatch\(password\)/);
    assert.doesNotMatch(source, /passwordConfirmationRules/);
});

test('registration still posts the confirmation value to the existing endpoint contract', () => {
    assert.match(source, /password_confirmation:\s*form\.passwordConfirmation/);
    assert.match(source, /axios\.post\('\/users\/create'/);
});
