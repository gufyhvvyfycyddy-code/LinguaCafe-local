import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const root = join(__dirname, '..', '..');
const parentPath = join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardManage.vue');
const surfacePath = join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardTableSurface.vue');

assert.ok(existsSync(surfacePath), 'ReviewCardTableSurface.vue must exist');

const parent = readFileSync(parentPath, 'utf8');
const surface = readFileSync(surfacePath, 'utf8');

assert.match(parent, /import ReviewCardTableSurface from ['"]\.\/ReviewCardTableSurface\.vue['"]/);
assert.match(parent, /<review-card-table-surface/);
assert.match(parent, /@page-change="changePage"/);
assert.match(parent, /@per-page-change="changePerPage"/);
assert.match(parent, /@sort-change="changeSort"/);
assert.match(parent, /@bulk-delete="confirmBulkDelete"/);
assert.match(parent, /@notify="showSnackbar"/);
assert.match(parent, /syncTableCurrentCard\(reviewCardId\)/, 'parent must synchronize parent-opened Card Info targets into the table current-card state');
assert.match(parent, /onDetailLoaded\(reviewCardId\)[\s\S]*?this\.syncTableCurrentCard\(reviewCardId\)/, 'detail load must synchronize the visible current row before deep-link-only handling');
assert.match(parent, /axios\.get\(['"]\/review-cards\/manage\/data['"]/, 'parent remains the canonical list request owner');

assert.match(surface, /selectedIds:\s*\[\]/);
assert.match(surface, /currentCardId:\s*null/);
assert.match(surface, /markCurrentCard\(item\)/);
assert.match(surface, /markCurrentCardById\(reviewCardId\)/, 'table surface must expose a narrow current-card synchronization method');
assert.match(surface, /clearSelection\(\)/);
assert.match(surface, /v-model="compactMode"/);
assert.match(surface, /reviewCardManageColumnSettings/);
assert.match(surface, /reviewCardManageCompactMode/);
assert.match(surface, /<v-pagination/);
assert.match(surface, /toggleSort\(column\)/);
assert.match(surface, /toggleSelectAll\(\)/);
assert.match(surface, /toggleItem\(id\)/);
assert.match(surface, /exportCurrentFilter\(\)/);
assert.match(surface, /exportAnkiTsv\(\)/);
assert.match(surface, /exportCsv\(\)/);
assert.match(surface, /axios\.get\(['"]\/review-cards\/manage\/export['"]/, 'JSON export belongs to the table surface');
assert.match(surface, /axios\.get\(['"]\/review-cards\/manage\/export-anki-tsv['"]/, 'Anki TSV export belongs to the table surface');
assert.match(surface, /axios\.get\(['"]\/review-cards\/manage\/export-csv['"]/, 'CSV export belongs to the table surface');
assert.match(surface, /emitBulk\(['"]bulk-delete['"]\)/);
assert.match(surface, /this\.\$emit\(['"]bulk-lifecycle['"],/);
assert.match(surface, /emitRow\(['"]detail['"],/);
assert.match(surface, /this\.\$emit\(['"]notify['"],/);

assert.doesNotMatch(surface, /axios\.(?:post|patch|delete)\(/, 'table surface must not own mutation requests');
assert.doesNotMatch(surface, /\/lifecycle-actions|\/bulk-lifecycle|\/bulk-delete|\/due-now|\/reset/, 'table surface emits mutation intents instead of calling mutation endpoints');
assert.doesNotMatch(surface, /Vuex|mapState|mapActions|eventBus|EventBus/, 'table surface must not introduce global state or an event bus');
assert.doesNotMatch(surface, /invalid_browser_search|detectAdvancedTokens|stripIsTokens|new RegExp/, 'table surface must not parse search grammar');

assert.doesNotMatch(parent, /<table class="manage-table"/);
assert.doesNotMatch(parent, /v-model="compactMode"/);
assert.doesNotMatch(parent, /reviewCardManageColumnSettings/);
assert.doesNotMatch(parent, /reviewCardManageCompactMode/);
assert.doesNotMatch(parent, /exportCurrentFilter\(\)/);
assert.doesNotMatch(parent, /exportAnkiTsv\(\)/);
assert.doesNotMatch(parent, /exportCsv\(\)/);
assert.doesNotMatch(parent, /axios\.get\(['"]\/review-cards\/manage\/export(?:-anki-tsv|-csv)?['"]/);
assert.doesNotMatch(parent, /toggleSelectAll\(\)/);
assert.doesNotMatch(parent, /toggleItem\(id\)/);
assert.doesNotMatch(parent, /toggleColumnVisibility\(key\)/);
assert.doesNotMatch(parent, /toggleSort\(column\)/);

const parentLineCount = (parent.match(/\n/g) || []).length;
assert.ok(parentLineCount < 1900, `ReviewCardManage.vue must fall below 1,900 lines after Phase 3B-2 extraction; got ${parentLineCount}`);

console.log('ReviewCardTableSurface guard passed.');
