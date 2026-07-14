import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const page = readFileSync(
    new URL('../../resources/js/components/CustomStudy/CustomStudy.vue', import.meta.url),
    'utf8',
);
const app = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');
const layout = readFileSync(new URL('../../resources/js/components/Layout.vue', import.meta.url), 'utf8');

assert.match(page, /\/custom-study\/chapter-options/);
assert.match(page, /\/custom-study\/sessions/);
assert.match(page, /today_forgotten/);
assert.match(page, /overdue/);
assert.match(page, /source_chapter/);
assert.match(page, /leech_attention/);
assert.match(page, /leech_only/);
assert.match(page, /leech_plus_struggling/);
assert.match(page, /card_limit/);
assert.match(page, /sessionStorage/);
assert.doesNotMatch(page, /localStorage/);
assert.doesNotMatch(page, /\$store/);
assert.match(app, /path: '\/custom-study', component: CustomStudy/);
assert.match(layout, /url: '\/custom-study'/);

console.log('CustomStudy page guard passed.');
