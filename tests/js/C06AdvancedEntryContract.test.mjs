import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const settings = fs.readFileSync(path.join(root, 'resources/js/components/UserSettings/UserSettingsLayout.vue'), 'utf8');
const layout = fs.readFileSync(path.join(root, 'resources/js/components/Layout.vue'), 'utf8');
const settingsTemplate = settings.match(/<template>([\s\S]*?)<\/template>/)?.[1] ?? '';

const advancedRoutes = [
    ['自定义学习', '/custom-study'],
    ['学习总览', '/study-overview'],
    ['备份与恢复', '/admin/dashboard'],
];

const layoutNavigation = layout.match(/navigation:\s*\[([\s\S]*?)\]\s*,/)?.[1];
assert.ok(layoutNavigation, 'Missing Layout navigation array');

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

    assert.doesNotMatch(settings, /词汇搜索/);
    assert.doesNotMatch(settings, /url:\s*['"]\/vocabulary\/search['"]/);
    assert.doesNotMatch(settings, /复习卡管理|url:\s*['"]\/review-cards\/manage['"]/);
    assert.doesNotMatch(settings, /文章检查|url:\s*['"]\/article-health['"]/);
    assert.doesNotMatch(settingsTemplate, /FSRS|Leech|ReviewCard|target_type|生命周期/);
    assert.doesNotMatch(layout, /高级复习卡管理/);
    for (const [title, url] of advancedRoutes) {
        assert.doesNotMatch(
            layoutNavigation,
            new RegExp(`\\burl:\\s*['"]${url.replaceAll('/', '\\/')}['"]`),
            `${title} must not be duplicated in Layout navigation`,
        );
    }
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
