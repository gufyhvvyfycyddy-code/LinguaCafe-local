import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const app = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');
const page = readFileSync(new URL('../../resources/js/components/Home/LearningHistory.vue', import.meta.url), 'utf8');
const calendar = readFileSync(new URL('../../resources/js/components/Home/Calendar.vue', import.meta.url), 'utf8');

assert.match(app, /path:\s*['"]\/learning-history['"],\s*component:\s*LearningHistory/, 'Learning History must be registered in Vue Router.');
assert.match(page, /axios\.get\(['"]\/learning-history\/data['"]/, 'The page must use the canonical paginated endpoint.');
assert.match(page, /filterOptions:[\s\S]*new_learning[\s\S]*reading_review[\s\S]*formal_review/, 'All frozen timeline filters must be visible.');
assert.match(page, /v-skeleton-loader/, 'The page must expose a loading state.');
assert.match(page, /v-if="error"/, 'The page must expose an error state.');
assert.match(page, /这个范围内还没有学习记录/, 'The page must expose an empty state.');
assert.match(page, /当前记忆状态/, 'Current card state must be labelled as a present-day snapshot.');
assert.match(page, /\['csv',\s*'txt',\s*'pdf'\]/, 'The page must expose all frozen export formats.');
assert.match(page, /\/learning-history\/export\/\$\{format\}/, 'Exports must preserve the current canonical date/filter scope.');
assert.match(page, /value: 'new_learning', label: '新学'/, 'The frozen new-learning label must remain exact.');
assert.match(page, /value: 'reading_review', label: '阅读复习'/, 'The frozen reading-review label must remain exact.');
assert.doesNotMatch(page, /新词进入学习|阅读中复习/, 'Superseded filter copy must not return.');

assert.match(calendar, /axios\.get\(['"]\/learning-history\/data['"]/, 'Calendar must derive new-learning counts from the canonical timeline endpoint.');
assert.match(calendar, /daily_reading_counts/, 'Calendar must consume canonical daily counts from timeline metadata.');
assert.match(calendar, /path:\s*['"]\/learning-history['"][\s\S]*date_from:\s*day\.fullDate[\s\S]*date_to:\s*day\.fullDate/, 'New-learning calendar days must deep-link to the matching history day.');
assert.match(calendar, /achievement\.type === ['"]learn_words['"]/, 'Derived new-learning achievements must remain read-only.');

console.log('Learning History UI and Calendar architecture guard passed.');
