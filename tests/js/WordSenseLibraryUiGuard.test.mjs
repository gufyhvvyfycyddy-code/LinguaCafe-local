import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const testDir = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(testDir, '../..');
const appSource = fs.readFileSync(path.join(repoRoot, 'resources/js/app.js'), 'utf8');
const pageSource = fs.readFileSync(
    path.join(repoRoot, 'resources/js/components/Senses/WordSenseLibrary.vue'),
    'utf8',
);

function escapeRegex(value) {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function normalizeHandler(expression) {
    return expression.trim().replace(/\s+/g, '').replace(/\(\)$/g, '');
}

function extractMethodBody(name) {
    const pattern = new RegExp(
        `(?:async\\s+)?${escapeRegex(name)}\\s*\\([^)]*\\)\\s*\\{([\\s\\S]*?)\\n\\s*\\},`,
        'm',
    );
    const match = pageSource.match(pattern);
    return match ? match[1] : null;
}

function requestBodiesFor(handlerExpression) {
    const handler = normalizeHandler(handlerExpression);
    assert.match(handler, /^[A-Za-z_$][\w$]*$/, 'Search action must point directly to one handler');

    const body = extractMethodBody(handler);
    assert.ok(body, `Unable to find method body for search handler: ${handler}`);

    const bodies = [body];
    for (const call of body.matchAll(/this\.([A-Za-z_$][\w$]*)\s*\(/g)) {
        const calledBody = extractMethodBody(call[1]);
        if (calledBody) {
            bodies.push(calledBody);
        }
    }
    return bodies;
}

function assertTemplateBinding(field) {
    const pattern = new RegExp(
        `(?:\\{\\{[^}]*\\b${escapeRegex(field)}\\b[^}]*\\}\\}|v-(?:text|html)\\s*=\\s*["'][^"']*\\b${escapeRegex(field)}\\b[^"']*["'])`,
    );
    assert.match(pageSource, pattern, `${field} must be bound into the rendered item UI`);
}

test('router owns canonical /word-senses page', () => {
    assert.match(
        appSource,
        /(?:require\(\s*['"]\.\/components\/Senses\/WordSenseLibrary\.vue['"]\s*\)|from\s+['"]\.\/components\/Senses\/WordSenseLibrary\.vue['"])/,
    );

    const route = appSource.match(
        /\{\s*path:\s*['"]\/word-senses['"]\s*,\s*component:\s*([A-Za-z_$][\w$]*)\s*\}/,
    );
    assert.ok(route, 'Expected an explicit /word-senses Vue Router entry');
    assert.equal(route[1], 'WordSenseLibrary');
    assert.notEqual(route[1], 'Vocabulary');
    assert.notEqual(route[1], 'ReviewCardManage');
});

test('page identity and read-only data path match the frozen contract', () => {
    assert.match(pageSource, />\s*生词\s*</);
    assert.ok(pageSource.includes('这里是你已经保存并确认的词义。同一个词可以有多个词义。'));
    assert.ok(pageSource.includes('搜索生词或释义'));

    const axiosGets = [...pageSource.matchAll(/axios\.get\s*\(/g)];
    const canonicalGets = [...pageSource.matchAll(/axios\.get\s*\(\s*['"]\/word-senses\/data['"]/g)];
    assert.ok(axiosGets.length > 0, 'Expected an axios GET for the WordSense library');
    assert.equal(canonicalGets.length, axiosGets.length, 'Every axios GET must use /word-senses/data');

    assertTemplateBinding('lemma');
    assertTemplateBinding('pos');
    assertTemplateBinding('sense_zh');
    assertTemplateBinding('sense_en');

    assert.match(
        pageSource,
        /<[^>]*@click(?:\.[\w-]+)*\s*=\s*"[^"]*\bsense_id\b[^"]*"[^>]*>[\s\S]{0,400}?查看[\s\S]{0,200}?<\//,
        '查看 must use sense_id',
    );
    assert.match(
        pageSource,
        /v-(?:if|show)\s*=\s*"[^"]*\bsense_id\b[^"]*"/,
        'Expanded details must render inline from sense_id state',
    );
});

test('search Enter and visible action share one real request path', () => {
    const placeholderIndex = pageSource.indexOf('搜索生词或释义');
    assert.notEqual(placeholderIndex, -1);
    const searchArea = pageSource.slice(
        Math.max(0, placeholderIndex - 1200),
        Math.min(pageSource.length, placeholderIndex + 2200),
    );

    const enter = searchArea.match(/@(?:keyup|keydown)\.enter(?:\.[\w-]+)*\s*=\s*"([^"]+)"/);
    assert.ok(enter, 'Search field must submit on Enter');

    const enterHandler = normalizeHandler(enter[1]);
    const clickHandlers = [...searchArea.matchAll(/<(?:v-btn|button|v-icon)\b[^>]*@click(?:\.[\w-]+)*\s*=\s*"([^"]+)"[^>]*>/g)]
        .map((match) => normalizeHandler(match[1]));
    assert.ok(clickHandlers.includes(enterHandler), 'Visible search action must use the Enter handler');

    const requestBodies = requestBodiesFor(enter[1]);
    assert.ok(
        requestBodies.some((body) => /axios\.get\(\s*['"]\/word-senses\/data['"]/.test(body)),
        'Search handler must reach GET /word-senses/data',
    );
    assert.ok(
        requestBodies.some((body) => /\/word-senses\/data/.test(body) && /\bq\b/.test(body)),
        'Search request must send q to the canonical endpoint',
    );
});

test('loading, empty, error, no-result, and clear-search branches stay visible', () => {
    assert.ok(pageSource.includes('正在加载生词…'));
    assert.ok(pageSource.includes('还没有保存的生词。你在阅读中保存并确认的词义会出现在这里。'));
    assert.ok(pageSource.includes('生词加载失败，请重试。'));

    const noResultIndex = pageSource.indexOf('没有找到匹配的生词。');
    assert.notEqual(noResultIndex, -1);
    const noResultArea = pageSource.slice(
        Math.max(0, noResultIndex - 800),
        Math.min(pageSource.length, noResultIndex + 1200),
    );
    assert.match(
        noResultArea,
        /<[^>]*@click(?:\.[\w-]+)*\s*=\s*"[^"]+"[^>]*>[\s\S]{0,300}?清除搜索[\s\S]{0,150}?<\//,
        'No-result state must expose a clear-search action',
    );
});

test('page stays inside the basic WordSense boundary', () => {
    assert.doesNotMatch(
        pageSource,
        /(?:import|require)[^\n]*(?:Vocabulary|ReviewCardManage)|<\/?(?:Vocabulary|ReviewCardManage)\b/,
    );

    const forbiddenFields = [
        'has_review_card',
        'review_card_id',
        'occurrence_count',
        'sense_key',
        'fsrs_state',
        'fsrs_due_at',
        'fsrs_stability',
        'fsrs_difficulty',
        'fsrs_reps',
        'fsrs_lapses',
        'lifecycle',
        'Leech',
    ];
    for (const field of forbiddenFields) {
        assert.doesNotMatch(pageSource, new RegExp(`\\b${escapeRegex(field)}\\b`, 'i'), `${field} is outside C-05`);
    }

    assert.doesNotMatch(pageSource, /\bstage\b|\bphrase\b|\bcsv\b|batch.{0,30}delete|delete.{0,30}batch|批量删除/i);
    assert.doesNotMatch(pageSource, /axios\.(?:post|put|patch|delete)\s*\(/i);
    assert.doesNotMatch(pageSource, /\$http\.(?:get|post|put|patch|delete)\s*\(/i);
    assert.doesNotMatch(pageSource, /\bfetch\s*\(/i);
    assert.doesNotMatch(pageSource, /method\s*:\s*['"](?:POST|PUT|PATCH|DELETE)['"]/i);
    assert.doesNotMatch(pageSource, /\blocalStorage\b|\bsessionStorage\b/);
});
