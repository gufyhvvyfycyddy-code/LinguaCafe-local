import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const settings = fs.readFileSync(path.join(root, 'resources/js/components/UserSettings/UserSettingsLayout.vue'), 'utf8');
const layout = fs.readFileSync(path.join(root, 'resources/js/components/Layout.vue'), 'utf8');

const advancedRoutes = [
    ['词汇搜索', '/vocabulary/search'],
    ['自定义学习', '/custom-study'],
    ['复习卡管理', '/review-cards/manage'],
    ['学习总览', '/study-overview'],
    ['备份与恢复', '/admin/dashboard'],
    ['文章检查', '/article-health'],
];

test('My settings owns one user-facing advanced entry', () => {
    assert.match(settings, /<v-tab>高级<\/v-tab>/);
    assert.match(settings, /高级功能/);
    assert.match(settings, /这些工具不会出现在主导航中，需要时可以从这里进入。/);
    assert.match(settings, /v-for="item in advancedItems"/);
});

test('advanced entry reuses the existing route families without engineering labels', () => {
    for (const [title, url] of advancedRoutes) {
        assert.match(settings, new RegExp(`title:\\s*['"]${title}['"][\\s\\S]*?url:\\s*['"]${url.replaceAll('/', '\\/')}['"]`));
    }

    assert.doesNotMatch(settings, /FSRS|Leech|ReviewCard|target_type|生命周期/);
    assert.doesNotMatch(layout, /高级复习卡管理/);
    assert.match(layout, /name:\s*['"]复习卡管理['"][\s\S]*?url:\s*['"]\/review-cards\/manage['"]/);
});

test('admin settings remains a single role-gated drawer entry', () => {
    assert.doesNotMatch(settings, /管理员设置|url:\s*['"]\/admin['"]/);
    assert.equal((layout.match(/name:\s*['"]管理员设置['"]/g) ?? []).length, 1);
    assert.match(layout, /if\s*\(this\.\$store\.getters\[['"]shared\/userAdmin['"]\]\)/);
});

test('advanced descriptions remain mobile-wrap safe', () => {
    assert.match(settings, /\.advanced-feature-description\s*\{[\s\S]*?white-space:\s*normal/);
    assert.match(settings, /overflow-wrap:\s*anywhere/);
});
