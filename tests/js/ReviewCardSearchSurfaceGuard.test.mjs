import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const root = join(__dirname, '..', '..');
const parentPath = join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardManage.vue');
const surfacePath = join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardSearchSurface.vue');
const savedSearchPath = join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardSavedSearchPanel.vue');
const overviewPath = join(root, 'resources', 'js', 'components', 'StudyOverview', 'StudyOverview.vue');
const filterStatePath = join(root, 'resources', 'js', 'services', 'ReviewCardManageFilterState.js');

assert.ok(existsSync(surfacePath), 'ReviewCardSearchSurface.vue must exist');
assert.ok(existsSync(savedSearchPath), 'ReviewCardSavedSearchPanel.vue must preserve legacy deep-link reads');
assert.ok(existsSync(filterStatePath), 'ReviewCardManageFilterState.js must remain the canonical filter-state helper');

const parent = readFileSync(parentPath, 'utf8');
const surface = readFileSync(surfacePath, 'utf8');
const savedSearch = readFileSync(savedSearchPath, 'utf8');
const overview = readFileSync(overviewPath, 'utf8');

assert.match(parent, /import ReviewCardSearchSurface from ['"]\.\/ReviewCardSearchSurface\.vue['"]/);
assert.match(parent, /<review-card-search-surface/);
assert.match(parent, /@apply="applySearchFilterState"/);
assert.match(parent, /browserFilterState/);
assert.doesNotMatch(parent, /<review-card-saved-search-panel/);
assert.doesNotMatch(parent, /v-model="searchQuery"/);
assert.doesNotMatch(parent, /v-model="advancedFilters\./);
assert.doesNotMatch(parent, /detectAdvancedTokens\(query\)/);
assert.doesNotMatch(parent, /stripIsTokens\(query\)/);
assert.doesNotMatch(parent, /removeToken\(token\)/);
assert.doesNotMatch(parent, /v-model="searchHelpDialog"/);

assert.match(surface, /import ReviewCardSavedSearchPanel from ['"]\.\/ReviewCardSavedSearchPanel\.vue['"]/);
assert.match(surface, /buildReviewCardManageFilterState/);
assert.match(surface, /applyReviewCardManageFilterState/);
assert.match(surface, /<review-card-saved-search-panel/);
assert.match(surface, /v-model="searchQuery"/);
assert.match(surface, /v-model="advancedFilters\./);
assert.match(surface, /detectAdvancedTokens\(query\)/);
assert.match(surface, /stripIsTokens\(query\)/);
assert.match(surface, /removeToken\(token\)/);
assert.match(surface, /v-model="searchHelpDialog"/);
assert.match(surface, /class="d-flex flex-wrap align-end"/, 'advanced-filter actions must wrap on narrow screens');
assert.match(surface, /this\.\$emit\(['"]apply['"],/);
assert.doesNotMatch(surface, /axios\./, 'search surface must not own list, export, or mutation requests');
assert.doesNotMatch(surface, /\/review-cards\/manage\/(?:data|export|export-anki-tsv|export-csv|bulk|\$\{)/);
assert.doesNotMatch(surface, /new RegExp/);
assert.doesNotMatch(surface, /governanceStatus|lifecycleStatus/);

assert.match(savedSearch, /axios\.get\(['"]\/review-cards\/manage\/saved-searches['"]\)/);
assert.match(savedSearch, /initialSavedSearchId/);
assert.match(savedSearch, /\$emit\(['"]apply['"]/);
assert.match(savedSearch, /render\(createElement\)/);
assert.doesNotMatch(savedSearch, /<template>|保存的搜索|搜索名称|保存当前|axios\.(?:post|patch|delete)|axios\[method\]/);

assert.doesNotMatch(overview, /savedSearchId|savedSearchOptions|saved_search_id|保存的搜索|统计范围|查看范围内卡片/);
assert.match(overview, /全部已确认词义卡/);
assert.match(overview, /axios\.get\(['"]\/study-overview\/data['"],\{params:\{period:this\.period\}\}\)/);

const parentLineCount = (parent.match(/\n/g) || []).length;
assert.ok(parentLineCount < 2500, `ReviewCardManage.vue must fall below 2,500 lines after Phase 3B-1 extraction; got ${parentLineCount}`);

console.log('ReviewCardSearchSurface guard passed.');
