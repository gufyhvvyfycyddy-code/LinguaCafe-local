import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const testDir = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(testDir, '../..');

function read(relativePath) {
    return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

const appSource = read('resources/js/app.js');
const layoutSource = read('resources/js/components/Layout.vue');
const librarySource = read('resources/js/components/Library/Library.vue');
const bookSource = read('resources/js/components/Library/Book.vue');
const bookChaptersSource = read('resources/js/components/Library/BookChapters.vue');
const bookListDetailedSource = read('resources/js/components/Library/BookListLayout/BookListDetailed.vue');
const bookListTableSource = read('resources/js/components/Library/BookListLayout/BookListTable.vue');
const dialogPath = path.join(repoRoot, 'resources/js/components/Dialogs/StartReviewDialog.vue');

const scopedReviewIdentifiers = /showStartReviewDialog|show-start-review-dialog/;

test('application-level StartReviewDialog plumbing is retired', () => {
    assert.doesNotMatch(appSource, /\.\/components\/Dialogs\/StartReviewDialog/);
    assert.doesNotMatch(
        appSource,
        /Vue\.component\(\s*['"]start-review-dialog['"]\s*,\s*StartReviewDialog\s*\)/,
    );
    assert.equal(fs.existsSync(dialogPath), false, 'StartReviewDialog.vue must be removed once orphaned');
});

test('Layout removes only the retired scoped-review dialog chain', () => {
    assert.doesNotMatch(layoutSource, /<start-review-dialog\b/);
    assert.doesNotMatch(layoutSource, /\bstartReviewDialog\b/);
    assert.doesNotMatch(
        layoutSource,
        /if\s*\(\s*itemName\s*={2,3}\s*['"]Review['"]\s*\)/,
        'Legacy Review-click interception must stay retired',
    );
});

test('Library surfaces no longer expose scoped-review dialog plumbing', () => {
    for (const source of [librarySource, bookSource, bookChaptersSource, bookListDetailedSource, bookListTableSource]) {
        assert.doesNotMatch(source, scopedReviewIdentifiers);
    }

    assert.doesNotMatch(librarySource, /<start-review-dialog\b|\bstartReviewDialog\b/);
    assert.doesNotMatch(bookChaptersSource, /<start-review-dialog\b|\bstartReviewDialog\b/);
});

test('canonical review navigation remains /reviews/senses', () => {
    assert.match(
        layoutSource,
        /\{[^{}]*\bname\s*:\s*['"]复习['"][^{}]*\burl\s*:\s*['"]\/reviews\/senses['"][^{}]*\bmainNav\s*:\s*true\b[^{}]*\}/,
    );
});

test('deferred legacy Vue Review route and import remain present', () => {
    assert.match(
        appSource,
        /const\s+Review\s*=\s*require\(\s*['"]\.\/components\/Review\/Review\.vue['"]\s*\)\.default;/,
    );
    assert.match(
        appSource,
        /\{\s*path\s*:\s*['"]\/review\/:practiceMode\?\/:bookId\?\/:chapterId\?['"]\s*,\s*component\s*:\s*Review\b[^}]*\}/,
    );
});
