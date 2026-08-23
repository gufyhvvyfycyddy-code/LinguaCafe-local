import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const book = readFileSync(new URL('../../resources/js/components/Library/Book.vue', import.meta.url), 'utf8');
const health = readFileSync(new URL('../../resources/js/components/Health/ArticleHealth.vue', import.meta.url), 'utf8');
const settings = readFileSync(new URL('../../resources/js/components/UserSettings/UserSettingsLayout.vue', import.meta.url), 'utf8');
const app = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');

assert.match(book, /path:\s*['"]\/article-health['"][\s\S]*?book_id:\s*book\.id/);
assert.match(book, />检查内容<\/v-btn>/);
assert.match(health, /requestedBookId\s*=\s*this\.\$route\?\.query\?\.book_id/);
assert.match(health, /axios\.get\(['"]\/article-health\/data['"],\s*\{ params \}\)/);
assert.match(health, /report\.scope\.book_name/);
assert.doesNotMatch(settings, /文章检查|url:\s*['"]\/article-health['"]/);
assert.match(app, /path:\s*['"]\/article-health['"],\s*component:\s*ArticleHealth/);

console.log('Article Health material-scope UI guard passed.');
