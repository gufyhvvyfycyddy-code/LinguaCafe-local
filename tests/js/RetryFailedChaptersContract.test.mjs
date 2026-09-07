import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const bookSource = fs.readFileSync(
    path.join(root, 'resources/js/components/Library/Book.vue'),
    'utf8',
);
const routesSource = fs.readFileSync(path.join(root, 'routes/web.php'), 'utf8');

test('retrying failed chapters uses POST and exposes no GET or HEAD route', () => {
    assert.match(
        routesSource,
        /Route::post\('\/chapters\/retry-failed-chapters\/\{bookId\}'/,
    );
    assert.doesNotMatch(
        routesSource,
        /Route::get\s*\('\/chapters\/retry-failed-chapters\/\{bookId\}'/,
    );
    assert.match(
        bookSource,
        /axios\.post\(`\/chapters\/retry-failed-chapters\/\$\{this\.\$props\.book\.id\}`\)/,
    );
    assert.doesNotMatch(
        bookSource,
        /axios\.get\(`\/chapters\/retry-failed-chapters/,
    );
});

test('retry button is locked while a retry request is in flight', () => {
    assert.match(bookSource, /:disabled="retryFailedImportsLoading"/);
    assert.match(bookSource, /:loading="retryFailedImportsLoading"/);
    assert.match(bookSource, /if \(this\.retryFailedImportsLoading\) \{/);
    assert.match(bookSource, /this\.retryFailedImportsLoading = true;/);
    assert.match(bookSource, /this\.retryFailedImportsLoading = false;/);
});

test('failed retry requests restore the exact optimistic chapter state and show an error', () => {
    assert.match(bookSource, /const failedChapterSnapshots = \[\];/);
    assert.match(bookSource, /processingStatus: chapter\.processing_status/);
    assert.match(bookSource, /wordCountsLoaded: chapter\.wordCountsLoaded/);
    assert.match(bookSource, /snapshot\.chapter\.processing_status = snapshot\.processingStatus/);
    assert.match(bookSource, /snapshot\.chapter\.wordCountsLoaded = snapshot\.wordCountsLoaded/);
    assert.match(bookSource, /this\.retryFailedImportsError = requestErrorMessage\(/);
    assert.match(bookSource, /v-if="retryFailedImportsError"/);
});
