import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const layout = fs.readFileSync(path.join(root, 'resources/js/components/Layout.vue'), 'utf8');
const app = fs.readFileSync(path.join(root, 'resources/js/app.js'), 'utf8');
const webRoutes = fs.readFileSync(path.join(root, 'routes/web.php'), 'utf8');

const expectedMain = [
    ['阅读', '/books'],
    ['复习', '/reviews/senses'],
    ['生词', '/word-senses'],
    ['我的', '/user-settings'],
];

const expectedSecondary = [
    ['首页', '/'],
    ['自定义学习', '/custom-study'],
    ['复习卡管理', '/review-cards/manage'],
    ['学习总览', '/study-overview'],
    ['备份', '/admin/dashboard'],
    ['内容健康', '/article-health'],
    ['用户手册', '/user-manual'],
];

function extractBalanced(source, marker, openChar, closeChar) {
    const markerIndex = source.indexOf(marker);
    assert.notEqual(markerIndex, -1, `Missing marker: ${marker}`);

    const openIndex = source.indexOf(openChar, markerIndex);
    assert.notEqual(openIndex, -1, `Missing ${openChar} after ${marker}`);

    let depth = 0;
    for (let index = openIndex; index < source.length; index += 1) {
        if (source[index] === openChar) depth += 1;
        if (source[index] === closeChar) depth -= 1;
        if (depth === 0) return source.slice(openIndex + 1, index);
    }

    assert.fail(`Unclosed ${openChar}${closeChar} block after ${marker}`);
}

function parseNavigationEntries(source) {
    const body = extractBalanced(source, 'navigation:', '[', ']');
    return [...body.matchAll(/\{([\s\S]*?)\}/g)].map((match) => {
        const object = match[1];
        return {
            name: object.match(/\bname\s*:\s*['"]([^'"]+)['"]/)?.[1] ?? null,
            url: object.match(/\burl\s*:\s*['"]([^'"]+)['"]/)?.[1] ?? null,
            mainNav: object.match(/\bmainNav\s*:\s*(true|false)\b/)?.[1] ?? null,
        };
    });
}

function extractTag(source, tagName, attributePattern = '') {
    const pattern = new RegExp(`<${tagName}\\b${attributePattern}[^>]*>[\\s\\S]*?<\\/${tagName}>`);
    const match = source.match(pattern);
    assert.ok(match, `Missing <${tagName}> block`);
    return match[0];
}

function extractIfBlock(source, conditionPattern) {
    const match = source.match(new RegExp(`if\\s*\\(\\s*${conditionPattern}\\s*\\)\\s*\\{`));
    assert.ok(match, `Missing conditional block: ${conditionPattern}`);
    const openIndex = source.indexOf('{', match.index);

    let depth = 0;
    for (let index = openIndex; index < source.length; index += 1) {
        if (source[index] === '{') depth += 1;
        if (source[index] === '}') depth -= 1;
        if (depth === 0) return source.slice(openIndex + 1, index);
    }

    assert.fail(`Unclosed conditional block: ${conditionPattern}`);
}

function assertComputedFilter(name, predicatePattern) {
    const signature = new RegExp(`\\b${name}\\s*(?::\\s*function\\s*\\([^)]*\\)|\\([^)]*\\))\\s*\\{`);
    const match = layout.match(signature);
    assert.ok(match, `Missing computed ${name}`);
    const body = extractBalanced(layout.slice(match.index), match[0], '{', '}');
    assert.match(body, new RegExp(`return\\s+this\\.navigation\\.filter\\(\\s*item\\s*=>\\s*${predicatePattern}\\s*\\)\\s*;?`));
}

function assertVueRoute(pattern, label) {
    assert.match(app, new RegExp(`\\{\\s*path\\s*:\\s*['"]${pattern}['"]\\s*,`), `Missing Vue route family ${label}`);
}

function assertWebRoute(pattern, label) {
    assert.match(webRoutes, new RegExp(`Route::get\\s*\\(\\s*['"]${pattern}['"]`), `Missing web route family ${label}`);
}

test('Layout has one canonical four-main navigation fact', () => {
    const entries = parseNavigationEntries(layout);
    const mainEntries = entries.filter((entry) => entry.mainNav === 'true');

    assert.deepEqual(mainEntries.map(({ name, url }) => [name, url]), expectedMain);
    assert.equal(mainEntries.length, 4);
    assert.doesNotMatch(layout, /\bbottomNav\b/, 'Legacy bottomNav classification must be removed');
});

test('desktop and mobile consume the same derived navigation fact', () => {
    assertComputedFilter('mainNavigation', 'item\\.mainNav');
    assertComputedFilter('secondaryNavigation', '!\\s*item\\.mainNav');

    const drawer = extractTag(layout, 'v-navigation-drawer');
    assert.match(drawer, /v-for\s*=\s*['"]\s*\(item,\s*index\)\s+in\s+mainNavigation\s*['"]/);
    assert.match(drawer, /v-for\s*=\s*['"]\s*\(item,\s*index\)\s+in\s+secondaryNavigation\s*['"]/);
    assert.doesNotMatch(drawer, /v-for\s*=\s*['"][^'"]*\bin\s+navigation\b[^'"]*['"]/);

    const mobile = extractTag(layout, 'v-bottom-navigation', '(?=[^>]*\\bid=[\'"]mobile-main-navigation[\'"])');
    assert.match(mobile, /v-for\s*=\s*['"]\s*\(item,\s*index\)\s+in\s+mainNavigation\s*['"]/);
    assert.doesNotMatch(mobile, /v-for\s*=\s*['"][^'"]*\bin\s+navigation\b[^'"]*['"]/);
    assert.doesNotMatch(mobile, /\/books|\/reviews\/senses|\/word-senses|\/user-settings/, 'Mobile main routes must not be hard-coded separately');
});

test('mobile bottom stays exact-four and More opens the existing drawer externally', () => {
    const mobile = extractTag(layout, 'v-bottom-navigation', '(?=[^>]*\\bid=[\'"]mobile-main-navigation[\'"])');
    assert.doesNotMatch(mobile, /更多|首页|词汇/);
    assert.doesNotMatch(mobile, /mobile-more-trigger/);

    const moreTrigger = layout.match(/<v-btn\b(?=[^>]*\bid=['"]mobile-more-trigger['"])[^>]*>[\s\S]*?<\/v-btn>/)?.[0];
    assert.ok(moreTrigger, 'Missing external mobile-more-trigger');
    assert.match(moreTrigger, /title\s*=\s*['"]更多['"]/);
    assert.match(moreTrigger, /aria-label\s*=\s*['"]更多['"]/);
    assert.match(moreTrigger, /mdi-menu/);
    assert.match(moreTrigger, />[\s\S]*更多[\s\S]*<\/v-btn>/);
    assert.match(moreTrigger, /@click\s*=\s*['"]\s*drawer\s*=\s*true\s*;?\s*['"]/);
});

test('secondary navigation compatibility remains explicit', () => {
    const entries = parseNavigationEntries(layout);
    const secondaryEntries = entries.filter((entry) => entry.mainNav === 'false');

    assert.deepEqual(secondaryEntries.map(({ name, url }) => [name, url]), expectedSecondary);
    assert.equal(entries.filter((entry) => entry.url === '/vocabulary/search').length, 0);
    assert.equal(entries.filter((entry) => entry.url === '/user-settings').length, 1);
    assert.equal(entries.find((entry) => entry.url === '/user-settings')?.name, '我的');
});

test('language and role conditional entries preserve their boundaries', () => {
    const japanese = extractIfBlock(layout, `this\\.\\$props\\._selectedLanguage\\s*={2,3}\\s*['"]japanese['"]`);
    assert.match(japanese, /name\s*:\s*['"]汉字['"]/);
    assert.match(japanese, /url\s*:\s*['"]\/kanji\/search['"]/);
    assert.match(japanese, /mainNav\s*:\s*false\b/);

    const admin = extractIfBlock(layout, `this\\.\\$store\\.getters\\[['"]shared\\/userAdmin['"]\\]`);
    assert.match(admin, /name\s*:\s*['"]管理员设置['"]/);
    assert.match(admin, /url\s*:\s*['"]\/admin['"]/);
    assert.match(admin, /mainNav\s*:\s*false\b/);
    assert.doesNotMatch(admin, /备份|\/admin\/dashboard/);

    assert.match(layout, /name\s*:\s*['"]备份['"][\s\S]*?url\s*:\s*['"]\/admin\/dashboard['"][\s\S]*?mainNav\s*:\s*false\b/);
});

test('admin navigation click has an explicit real-click route handoff', () => {
    assert.match(
        layout,
        /if\s*\(\s*itemName\s*={2,3}\s*['"]管理员设置['"][\s\S]*?this\.\$router\.currentRoute\.path\s*!={1,2}\s*['"]\/admin['"][\s\S]*?event\.preventDefault\(\)[\s\S]*?this\.\$router\.push\(\s*['"]\/admin['"]\s*\)/
    );
});

test('Vue and web route families required by navigation remain real', () => {
    const vueRoutes = [
        ['\\/books(?:\\/:bookId\\?)?', '/books'],
        ['\\/reviews\\/senses', '/reviews/senses'],
        ['\\/word-senses', '/word-senses'],
        ['\\/user-settings', '/user-settings'],
        ['\\/vocabulary\\/search', '/vocabulary/search'],
        ['\\/custom-study', '/custom-study'],
        ['\\/review-cards\\/manage', '/review-cards/manage'],
        ['\\/study-overview', '/study-overview'],
        ['\\/admin(?:\\/:page\\?)?', '/admin'],
        ['\\/article-health', '/article-health'],
        ['\\/user-manual(?:\\/:currentPage\\?)?', '/user-manual'],
    ];

    const serverRoutes = [
        ['\\/books(?:\\/\\{bookId\\?\\})?', '/books'],
        ['\\/reviews\\/senses', '/reviews/senses'],
        ['\\/word-senses', '/word-senses'],
        ['\\/user-settings', '/user-settings'],
        ['\\/vocabulary\\/search', '/vocabulary/search'],
        ['\\/custom-study', '/custom-study'],
        ['\\/review-cards\\/manage', '/review-cards/manage'],
        ['\\/study-overview', '/study-overview'],
        ['\\/admin(?:\\/\\{page\\?\\})?', '/admin'],
        ['\\/article-health', '/article-health'],
        ['\\/user-manual(?:\\/\\{currentPage\\?\\})?', '/user-manual'],
    ];

    for (const [pattern, label] of vueRoutes) assertVueRoute(pattern, label);
    for (const [pattern, label] of serverRoutes) assertWebRoute(pattern, label);
});
