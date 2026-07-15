import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const componentPath = join(__dirname, '..', '..', 'resources', 'js', 'components', 'Admin', 'ReviewSettings', 'FsrsAdvancedToolsPanel.vue');
const helperPath = join(__dirname, '..', '..', 'resources', 'js', 'services', 'FsrsAdvancedToolsPresentation.js');
const component = readFileSync(componentPath, 'utf8');
const helper = readFileSync(helperPath, 'utf8');

assert.match(component, /FsrsAdvancedToolsPresentation/);
assert.match(component, /还没有可用于参数优化的正式复习记录|advancedToolsView\.primaryMessage/);
assert.match(helper, /有效记录 \$\{eligibleReviewLogs\} \/ \$\{minRequired\}/);
assert.match(component, /advancedToolsView\.progressPercent/);
assert.match(component, /查看诊断详情/);
assert.match(component, /v-model="diagnosticPanels"/);
assert.match(component, /:disabled="[^\"]*!advancedToolsView\.canPreviewOptimization/);
assert.match(component, /:disabled="[^\"]*!advancedToolsView\.canRestoreDefaults/);
assert.match(component, /if \(!this\.advancedToolsView\.canPreviewOptimization\) return;/);
assert.match(component, /if \(!this\.advancedToolsView\.canRestoreDefaults\) return;/);
assert.match(component, /重新加载诊断/);
assert.match(component, /重排已有卡片/);
assert.doesNotMatch(component, /axios\./);
assert.doesNotMatch(helper, /axios|from ['"]vue['"]|document\.|window\.|\bReviewLog\b|fsrs_stability|fsrs_difficulty|lifecycle/i);
assert.match(component, /aria-live="polite"/);
assert.match(helper, /当前已是默认参数/);
assert.match(helper, /预览不会保存参数，也不会重排已有卡片/);
assert.doesNotMatch(component, /advancedToolsView\.(progressLabel|remainingLabel|trainableLabel)/);
assert.match(component, /v-if="!\['loading', 'error'\]\.includes\(advancedToolsView\.dataState\)"/);

console.log('FsrsAdvancedToolsUxGuard: 18 UI contracts passed.');
