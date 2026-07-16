// ReviewCardManageLeechGuard.test.mjs
//
// ADR-0011 (Task A-7): Leech governance UI in ReviewCardManage.
//
// Node built-in assert tests for ReviewCardManage.vue leech contract:
//   - 'leech' and 'struggling' filter buttons.
//   - GET /review-cards/manage/leech-summary endpoint.
//   - POST /review-cards/manage/bulk-leech-rewrite-packages endpoint.
//   - POST /review-cards/{id}/lifecycle-actions endpoint.
//   - Per-row '生成重写包' action and batch '批量生成重写包' / '批量暂停高遗忘卡'.
//   - Imports SenseReviewLeechRewritePackageDialog and leech presentation helpers.
//   - Leech status badge in the table.
//   - Detail drawer '遗忘诊断' diagnostics section.
//   - Uses crypto.randomUUID for request_id.
//   - Handles partial failure in batch (failed array per item).
//   - No provider-preview, no auto-creation, no FSRS.
//
// Tests:
//   1.  File exists.
//   2.  Source includes 'leech' filter.
//   3.  Source includes 'struggling' filter.
//   4.  Source includes /review-cards/manage/leech-summary endpoint.
//   5.  Source includes /review-cards/manage/bulk-leech-rewrite-packages endpoint.
//   6.  Source includes /lifecycle-actions endpoint.
//   7.  Source includes '生成重写包' per-row action.
//   8.  Source includes '批量生成重写包' batch action.
//   9.  Source includes '批量暂停高遗忘卡' batch action.
//  10.  Source imports SenseReviewLeechRewritePackageDialog.
//  11.  Source imports SenseReviewLeechPresentation functions.
//  12.  Source has leech status badge in table.
//  13.  Source has detail '遗忘诊断' diagnostics section.
//  14.  Source does NOT call provider-preview.
//  15.  Source does NOT create ReviewLog / WordSense / ReviewCard.
//  16.  Source does NOT contain FSRS scheduling.
//  17.  Source uses crypto.randomUUID for request_id.
//  18.  Source handles partial failure in batch.

import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

const MANAGE_PATH = join(__dirname, '..', '..', 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardManage.vue');
const DRAWER_PATH = join(__dirname, '..', '..', 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardInfoDrawer.vue');
const SEARCH_SURFACE_PATH = join(__dirname, '..', '..', 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardSearchSurface.vue');

const name = 'ReviewCardManageLeechGuard';
let passed = 0;
function test(name, fn) {
    try {
        fn();
        passed++;
    } catch (e) {
        console.error('FAIL: ' + name);
        console.error(e.message);
        process.exitCode = 1;
    }
}

const source = existsSync(MANAGE_PATH) ? readFileSync(MANAGE_PATH, 'utf-8') : '';
const searchSurfaceSource = existsSync(SEARCH_SURFACE_PATH) ? readFileSync(SEARCH_SURFACE_PATH, 'utf-8') : '';
const browserSource = `${source}\n${searchSurfaceSource}`;
const drawerSource = existsSync(DRAWER_PATH) ? readFileSync(DRAWER_PATH, 'utf-8') : '';

// 1. File exists
test('File exists', () => {
    assert.ok(existsSync(MANAGE_PATH), 'ReviewCardManage.vue must exist');
});

// 2. Source includes 'leech' filter
test("Source includes 'leech' filter", () => {
    assert.ok(browserSource.includes('value="leech"'), "Must have a 'leech' filter button");
});

// 3. Source includes 'struggling' filter
test("Source includes 'struggling' filter", () => {
    assert.ok(browserSource.includes('value="struggling"'), "Must have a 'struggling' filter button");
});

// 4. Source includes /review-cards/manage/leech-summary endpoint
test('Source includes /review-cards/manage/leech-summary endpoint', () => {
    assert.ok(source.includes('/review-cards/manage/leech-summary'), 'Must use the leech-summary endpoint');
});

// 5. Source includes /review-cards/manage/bulk-leech-rewrite-packages endpoint
test('Source includes /review-cards/manage/bulk-leech-rewrite-packages endpoint', () => {
    assert.ok(source.includes('/review-cards/manage/bulk-leech-rewrite-packages'), 'Must use the bulk-leech-rewrite-packages endpoint');
});

// 6. Source includes /lifecycle-actions endpoint
test('Source includes /lifecycle-actions endpoint', () => {
    assert.ok(source.includes('/lifecycle-actions'), 'Must use the lifecycle-actions endpoint');
});

// 7. Source includes '生成重写包' per-row action
test("Source includes '生成重写包' per-row action", () => {
    assert.ok(source.includes('生成重写包'), "Must include '生成重写包' per-row action");
});

// 8. Source includes '批量生成重写包' batch action
test("Source includes '批量生成重写包' batch action", () => {
    assert.ok(source.includes('批量生成重写包'), "Must include '批量生成重写包' batch action");
});

// 9. Source includes '批量暂停高遗忘卡' batch action
test("Source includes '批量暂停高遗忘卡' batch action", () => {
    assert.ok(source.includes('批量暂停高遗忘卡'), "Must include '批量暂停高遗忘卡' batch action");
});

// 10. Source imports SenseReviewLeechRewritePackageDialog
test('Source imports SenseReviewLeechRewritePackageDialog', () => {
    assert.ok(source.includes('SenseReviewLeechRewritePackageDialog'), 'Must import SenseReviewLeechRewritePackageDialog');
});

// 11. Source imports SenseReviewLeechPresentation functions
test('Source imports SenseReviewLeechPresentation functions', () => {
    assert.ok(source.includes('SenseReviewLeechPresentation'), 'Must import SenseReviewLeechPresentation functions');
});

// 12. Source has leech status badge in table
test('Source has leech status badge in table', () => {
    assert.ok(
        source.includes('leech_status') || source.includes('leechStatusLabel'),
        'Table must show a leech status badge (leech_status / leechStatusLabel)'
    );
});

// 13. Source has detail '遗忘诊断' diagnostics section
test("Source has detail '遗忘诊断' diagnostics section", () => {
    assert.ok(drawerSource.includes('遗忘诊断'), "Detail drawer must include a '遗忘诊断' diagnostics section");
});

// 14. Source does NOT call provider-preview
test('Source does NOT call provider-preview', () => {
    assert.ok(!source.includes('provider-preview'), 'Source must not call provider-preview');
});

// 15. Source does NOT create ReviewLog / WordSense / ReviewCard
test('Source does NOT create ReviewLog / WordSense / ReviewCard', () => {
    assert.ok(!source.includes('createReviewLog'), 'Source must not create ReviewLog');
    assert.ok(!source.includes('createWordSense'), 'Source must not create WordSense');
    assert.ok(!source.includes('createReviewCard'), 'Source must not create ReviewCard');
});

// 16. Source does NOT contain FSRS scheduling
test('Source does NOT contain FSRS scheduling', () => {
    assert.ok(!source.includes('FsrsScheduling'), 'Source must not contain FSRS scheduling');
    assert.ok(!source.includes('fsrs_schedule'), 'Source must not call fsrs_schedule');
});

// 17. Source uses crypto.randomUUID for request_id
test('Source uses crypto.randomUUID for request_id', () => {
    assert.ok(source.includes('randomUUID'), 'Must use crypto.randomUUID for request_id');
    assert.ok(source.includes('request_id') || source.includes('requestId'), 'Must send a request_id');
});

// 18. Source handles partial failure in batch
test('Source handles partial failure in batch', () => {
    assert.ok(
        source.includes('failed') || source.includes('bulkRewriteFailed'),
        'Batch rewrite must handle partial failure (failed array per item)'
    );
});

console.log(`\n${name}: ${passed} passed`);
