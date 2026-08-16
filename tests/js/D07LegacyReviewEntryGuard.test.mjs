import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const testDir = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(testDir, '../..');
const webSource = fs.readFileSync(path.join(repoRoot, 'routes/web.php'), 'utf8');
const appSource = fs.readFileSync(path.join(repoRoot, 'resources/js/app.js'), 'utf8');

const legacyPhpPath = '/review/{practiceMode?}/{bookId?}/{chapterId?}';
const legacyVuePath = '/review/:practiceMode?/:bookId?/:chapterId?';

function escapeRegex(value) {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function phpRoute(method, routePath) {
    const match = webSource.match(
        new RegExp(
            `Route::${method}\\s*\\(\\s*['"]${escapeRegex(routePath)}['"][\\s\\S]*?\\);`,
            'm',
        ),
    );
    assert.ok(match, `Expected Route::${method} for ${routePath}`);
    return match[0];
}

function vueRoute(routePath) {
    const match = appSource.match(
        new RegExp(
            `\\{\\s*path:\\s*['"]${escapeRegex(routePath)}['"]\\s*,[\\s\\S]*?\\}`,
            'm',
        ),
    );
    assert.ok(match, `Expected explicit Vue route for ${routePath}`);
    return match[0];
}

test('legacy Laravel review entry is a server redirect to exact /reviews/senses', () => {
    const route = phpRoute('redirect', legacyPhpPath);

    assert.match(
        route,
        new RegExp(
            `^Route::redirect\\s*\\(\\s*['"]${escapeRegex(legacyPhpPath)}['"]\\s*,\\s*['"]/reviews/senses['"](?:\\s*,\\s*\\d+)?\\s*\\);$`,
        ),
        'Legacy Laravel review entry must redirect exactly to /reviews/senses',
    );
    assert.doesNotMatch(route, /HomeController::class|['"]index['"]/);
    assert.doesNotMatch(
        webSource,
        new RegExp(`Route::get\\s*\\(\\s*['"]${escapeRegex(legacyPhpPath)}['"]`),
        'Legacy Laravel review entry must no longer be a HomeController GET route',
    );
});

test('canonical server GET /reviews/senses remains present', () => {
    const route = phpRoute('get', '/reviews/senses');
    assert.match(route, /SenseReviewController::class/);
    assert.match(route, /['"]index['"]/);
});

test('legacy Vue Review frontend is fully retired', () => {
    assert.doesNotMatch(
        appSource,
        new RegExp(`path:\\s*['"]${escapeRegex(legacyVuePath)}['"]`),
        'legacy Vue /review route must stay absent',
    );
    assert.doesNotMatch(
        appSource,
        /components\/Review\/Review\.vue/,
        'Review.vue require must stay absent',
    );
    assert.doesNotMatch(appSource, /ReviewHotkeyInformationDialog/);
    assert.doesNotMatch(
        appSource,
        /import\s+ReviewSettings\s+from\s+['"]\.\/components\/Review\/ReviewSettings['"]/,
    );
    assert.doesNotMatch(appSource, /review-hotkey-information-dialog/);
    assert.doesNotMatch(appSource, /Vue\.component\(\s*['"]review-settings['"]/);

    for (const relativePath of [
        'resources/js/components/Review/Review.vue',
        'resources/js/components/Review/ReviewSettings.vue',
        'resources/js/components/Review/ReviewHotkeyInformationDialog.vue',
    ]) {
        assert.equal(fs.existsSync(path.join(repoRoot, relativePath)), false, `${relativePath} must stay retired`);
    }

    assert.ok(
        fs.existsSync(path.join(repoRoot, 'resources/js/components/Review/ReviewApiClient.js')),
        'shared ReviewApiClient.js must remain present',
    );
});

test('/vocabulary/search Vue and server routes remain active', () => {
    const vue = vueRoute('/vocabulary/search');
    assert.match(vue, /component:\s*Vocabulary\b/);

    phpRoute('get', '/vocabulary/search');
    phpRoute('post', '/vocabulary/search');
});

test('modern Vue review and sense-management routes remain present', () => {
    const expectedRoutes = new Map([
        ['/reviews/senses', 'SenseReview'],
        ['/word-senses', 'WordSenseLibrary'],
        ['/review-cards/manage', 'ReviewCardManage'],
    ]);

    for (const [routePath, component] of expectedRoutes) {
        const route = vueRoute(routePath);
        assert.match(
            route,
            new RegExp(`component:\\s*${component}\\b`),
            `${routePath} must keep component ${component}`,
        );
    }
});