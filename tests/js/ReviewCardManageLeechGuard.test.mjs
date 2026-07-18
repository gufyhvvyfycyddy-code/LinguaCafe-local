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
const TABLE_SURFACE_PATH = join(__dirname, '..', '..', 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardTableSurface.vue');
const LIFECYCLE_SURFACE_PATH = join(__dirname, '..', '..', 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardLifecycleMutationSurface.vue');
const LEECH_SURFACE_PATH = join(__dirname, '..', '..', 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardLeechGovernanceMutationSurface.vue');

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
const tableSurfaceSource = existsSync(TABLE_SURFACE_PATH) ? readFileSync(TABLE_SURFACE_PATH, 'utf-8') : '';
const browserSource = `${source}\n${searchSurfaceSource}\n${tableSurfaceSource}`;
const drawerSource = existsSync(DRAWER_PATH) ? readFileSync(DRAWER_PATH, 'utf-8') : '';
const lifecycleSurfaceSource = existsSync(LIFECYCLE_SURFACE_PATH) ? readFileSync(LIFECYCLE_SURFACE_PATH, 'utf-8') : '';
const leechSurfaceSource = existsSync(LEECH_SURFACE_PATH) ? readFileSync(LEECH_SURFACE_PATH, 'utf-8') : '';
const governanceSource = `${source}\n${leechSurfaceSource}`;

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
test('Leech owner includes /review-cards/manage/leech-summary endpoint', () => {
    assert.ok(leechSurfaceSource.includes('/review-cards/manage/leech-summary'), 'Leech owner must use the leech-summary endpoint');
    assert.ok(!source.includes('/review-cards/manage/leech-summary'), 'Parent must not duplicate the leech-summary endpoint');
});

// 5. Source includes /review-cards/manage/bulk-leech-rewrite-packages endpoint
test('Leech owner includes /review-cards/manage/bulk-leech-rewrite-packages endpoint', () => {
    assert.ok(leechSurfaceSource.includes('/review-cards/manage/bulk-leech-rewrite-packages'), 'Leech owner must use the bulk rewrite endpoint');
    assert.ok(!source.includes('/review-cards/manage/bulk-leech-rewrite-packages'), 'Parent must not duplicate the bulk rewrite endpoint');
});

// 6. Leech UI delegates to the lifecycle request owner
test('Leech UI delegates to lifecycle request owner', () => {
    assert.ok(leechSurfaceSource.includes('this.runLifecycleAction'), 'Single leech suspend must use the injected lifecycle bridge');
    assert.ok(leechSurfaceSource.includes('this.runBulkLifecycle'), 'Bulk leech suspend must use the injected lifecycle bridge');
    assert.ok(source.includes('runLeechLifecycleAction'), 'Parent must bridge single lifecycle intent');
    assert.ok(source.includes('runLeechBulkLifecycle'), 'Parent must bridge bulk lifecycle intent');
    assert.ok(!governanceSource.includes('/lifecycle-actions'), 'Leech owner and parent must not duplicate lifecycle endpoints');
    assert.ok(lifecycleSurfaceSource.includes('/lifecycle-actions'), 'Lifecycle owner must use lifecycle-actions endpoint');
});

// 7. Source includes '生成重写包' per-row action
test("Source includes '生成重写包' per-row action", () => {
    assert.ok(browserSource.includes('生成重写包'), "Must include '生成重写包' per-row action");
});

// 8. Source includes '批量生成重写包' batch action
test("Source includes '批量生成重写包' batch action", () => {
    assert.ok(browserSource.includes('批量生成重写包'), "Must include '批量生成重写包' batch action");
});

// 9. Source includes '批量暂停高遗忘卡' batch action
test("Source includes '批量暂停高遗忘卡' batch action", () => {
    assert.ok(browserSource.includes('批量暂停高遗忘卡'), "Must include '批量暂停高遗忘卡' batch action");
});

// 10. Source imports SenseReviewLeechRewritePackageDialog
test('Leech owner imports SenseReviewLeechRewritePackageDialog', () => {
    assert.ok(leechSurfaceSource.includes('SenseReviewLeechRewritePackageDialog'), 'Leech owner must reuse SenseReviewLeechRewritePackageDialog');
    assert.ok(!source.includes('SenseReviewLeechRewritePackageDialog'), 'Parent must not retain the dialog import');
});

// 11. Source imports SenseReviewLeechPresentation functions
test('Browser regions reuse SenseReviewLeechPresentation functions', () => {
    assert.ok(browserSource.includes('SenseReviewLeechPresentation'), 'Leech badges must reuse presentation helpers');
});

// 12. Source has leech status badge in table
test('Source has leech status badge in table', () => {
    assert.ok(
        tableSurfaceSource.includes('leech_status') || tableSurfaceSource.includes('leechStatusLabel'),
        'Table must show a leech status badge (leech_status / leechStatusLabel)'
    );
});

// 13. Source has detail '遗忘诊断' diagnostics section
test("Source has detail '遗忘诊断' diagnostics section", () => {
    assert.ok(drawerSource.includes('遗忘诊断'), "Detail drawer must include a '遗忘诊断' diagnostics section");
});

// 14. Source does NOT call provider-preview
test('Source does NOT call provider-preview', () => {
    assert.ok(!governanceSource.includes('provider-preview'), 'Leech governance must not call provider-preview');
});

// 15. Source does NOT create ReviewLog / WordSense / ReviewCard
test('Source does NOT create ReviewLog / WordSense / ReviewCard', () => {
    assert.ok(!governanceSource.includes('createReviewLog'), 'Leech governance must not create ReviewLog');
    assert.ok(!governanceSource.includes('createWordSense'), 'Leech governance must not create WordSense');
    assert.ok(!governanceSource.includes('createReviewCard'), 'Leech governance must not create ReviewCard');
});

// 16. Source does NOT contain FSRS scheduling
test('Source does NOT contain FSRS scheduling', () => {
    assert.ok(!governanceSource.includes('FsrsScheduling'), 'Leech governance must not contain FSRS scheduling');
    assert.ok(!governanceSource.includes('fsrs_schedule'), 'Leech governance must not call fsrs_schedule');
});

// 17. Lifecycle request owner uses crypto.randomUUID for request_id
test('Lifecycle owner uses crypto.randomUUID for request_id', () => {
    assert.ok(lifecycleSurfaceSource.includes('randomUUID'), 'Lifecycle owner must use crypto.randomUUID for request_id');
    assert.ok(lifecycleSurfaceSource.includes('request_id'), 'Lifecycle owner must send a request_id');
});

// 18. Source handles partial failure in batch
test('Source handles partial failure in batch', () => {
    assert.ok(
        leechSurfaceSource.includes('failed') || leechSurfaceSource.includes('bulkRewriteFailed'),
        'Batch rewrite must handle partial failure (failed array per item)'
    );
});

console.log(`\n${name}: ${passed} passed`);
