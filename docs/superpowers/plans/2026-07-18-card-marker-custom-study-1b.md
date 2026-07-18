# Card Marker and Custom Study 1B Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete roadmap Phase 4 by adding Anki-style card-level Markers to manageable sense ReviewCards and a read-only `marked` Custom Study mode.

**Architecture:** Extend the existing ReviewCard management seam for persistence, access control, mutation, and serialization. Keep one Vue mutation owner for single/bulk Marker writes. Extend the existing Custom Study criteria/query/order pipeline with one parameterless mode that reuses canonical sense eligibility and queue ordering.

**Tech Stack:** Laravel/PHP 8, Eloquent/MySQL, Vue 2, Vuex, Vuetify, Axios, PHPUnit, Node test runner, Laravel Mix.

## Global Constraints

- Authoritative contract: `docs/superpowers/specs/2026-07-18-card-marker-custom-study-1b-design.md`.
- Write only the exact files named by a task. Stop before expanding to a new Controller, Service, model, store module, endpoint shape, or database table.
- Never read or modify `.env`; never run destructive database commands. Migration execution is permitted only through the isolated testing MySQL test harness.
- Preserve all pre-existing worktree changes and generated `.playwright-cli/` files; stage only exact Phase 4 paths.
- Marker writes change only `review_cards.marker`. Preview study never writes ReviewLog, FSRS, lifecycle, WordSense, or Marker.
- Use TDD for every behavior: observe the targeted test fail for the intended reason before implementation, then make the smallest passing change.
- Before Feature tests run `php artisan test --filter=TestingDatabaseHealthTest` and stop on failure.
- After every implementation slice, inspect `git diff --check` and `git status --short` before exact-path commit.

---

## Task 1: Freeze the accepted Phase 4 contract in an ADR

**Files:**

- Create: `docs/adr/ADR-0029-card-marker-and-custom-study-1b.md`
- Modify: `docs/DOCUMENTATION_INDEX.md`
- Modify: `docs/plans/anki-aligned-product-and-architecture-roadmap.md`
- Test: `tests/js/CardMarkerCustomStudyDocsGuard.test.mjs`

- [ ] Add a failing documentation guard that asserts ADR-0029 exists and contains the durable contracts:

```js
import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const adr = fs.readFileSync('docs/adr/ADR-0029-card-marker-and-custom-study-1b.md', 'utf8');

test('card marker contract is frozen', () => {
    assert.match(adr, /review_cards\.marker/);
    assert.match(adr, /0[^\n]+7/);
    assert.match(adr, /PATCH \/review-cards\/manage\/\{reviewCard\}\/marker/);
    assert.match(adr, /POST \/review-cards\/manage\/bulk-marker/);
    assert.match(adr, /mode.?=.?marked/);
    assert.match(adr, /preview/i);
});
```

- [ ] Run `node --test tests/js/CardMarkerCustomStudyDocsGuard.test.mjs`; expect failure because ADR-0029 does not exist.
- [ ] Write ADR-0029 from the approved design, including decision, alternatives rejected, schema/index/constraint, APIs, ownership, Custom Study semantics, protected write boundaries, rollout, rollback, and verification.
- [ ] Add ADR-0029 to `docs/DOCUMENTATION_INDEX.md`; mark Phase 4 as `In Progress` in `docs/plans/anki-aligned-product-and-architecture-roadmap.md` without claiming implementation completion.
- [ ] Run `node --test tests/js/CardMarkerCustomStudyDocsGuard.test.mjs`; expect pass.
- [ ] Run `git diff --check -- docs/adr/ADR-0029-card-marker-and-custom-study-1b.md docs/DOCUMENTATION_INDEX.md docs/plans/anki-aligned-product-and-architecture-roadmap.md tests/js/CardMarkerCustomStudyDocsGuard.test.mjs`.
- [ ] Commit exact paths with `git commit -m "docs: freeze card marker custom study contract"`.

## Task 2: Add Marker persistence and model contract

**Files:**

- Create: `database/migrations/2026_07_18_000001_add_marker_to_review_cards.php`
- Modify: `app/Models/ReviewCard.php`
- Create: `tests/Feature/ReviewCardMarkerMigrationTest.php`
- Create: `tests/Unit/ReviewCardMarkerContractTest.php`

- [ ] Add unit tests for the stable values and cast:

```php
$this->assertSame(0, ReviewCard::MARKER_NONE);
$this->assertSame(7, ReviewCard::MARKER_PURPLE);
$this->assertSame(range(0, 7), ReviewCard::MARKERS);
$this->assertSame('integer', (new ReviewCard())->getCasts()['marker']);
```

- [ ] Add a migration test proving: existing rows become `0`; inserts default to `0`; `0` and `7` are accepted; `-1` and `8` fail the named check; index `review_cards_user_language_id_target_marker_index` exists with columns `(user_id, language_id, target_type, marker)`; `down()` removes only Marker artifacts; `up()` restores them in `finally`.
- [ ] Run `php artisan test --filter=TestingDatabaseHealthTest`; expect pass.
- [ ] Run `php artisan test --filter='ReviewCardMarker(Migration|Contract)Test'`; expect failure because constants, cast, column, constraint, and index do not exist.
- [ ] Add model constants without adding Marker to generic mass assignment:

```php
public const MARKER_NONE = 0;
public const MARKER_RED = 1;
public const MARKER_ORANGE = 2;
public const MARKER_GREEN = 3;
public const MARKER_BLUE = 4;
public const MARKER_PINK = 5;
public const MARKER_TURQUOISE = 6;
public const MARKER_PURPLE = 7;
public const MARKERS = [0, 1, 2, 3, 4, 5, 6, 7];
```

Add `'marker' => 'integer'` to casts only.

- [ ] Implement the additive migration using `unsignedTinyInteger('marker')->default(0)`, named composite index, and a named MySQL check:

```php
Schema::table('review_cards', function (Blueprint $table) {
    $table->unsignedTinyInteger('marker')->default(0)->after('lifecycle_changed_at');
    $table->index(
        ['user_id', 'language_id', 'target_type', 'marker'],
        'review_cards_user_language_id_target_marker_index'
    );
});

DB::statement(
    'ALTER TABLE review_cards ADD CONSTRAINT review_cards_marker_range_check CHECK (marker BETWEEN 0 AND 7)'
);
```

In `down()`, drop the constraint first, then the named index and column.

- [ ] Run the two targeted test classes; expect pass.
- [ ] Run `git diff --check` and commit exact paths with `git commit -m "feat: add review card marker persistence"`.

## Task 3: Add access-safe single and bulk Marker APIs

**Files:**

- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/ReviewCardManageController.php`
- Modify: `app/Services/ReviewCardManageMutationService.php`
- Create: `tests/Feature/ReviewCardMarkerApiTest.php`

- [ ] Add failing Feature tests for single success, idempotence, clear-to-zero, missing/out-of-range/non-integer validation, unknown/cross-user/cross-language/legacy/non-confirmed `404`, bulk distinct/max-100 validation, bulk affected/skipped counts, and transaction behavior.
- [ ] Snapshot protected columns and ReviewLog count before each valid write, then assert only Marker changed:

```php
$before = $card->only([
    'fsrs_state', 'fsrs_due_at', 'fsrs_stability', 'fsrs_difficulty',
    'fsrs_reps', 'fsrs_lapses', 'fsrs_last_reviewed_at', 'lifecycle_state',
]);
$reviewLogCount = ReviewLog::count();

$this->patchJson("/review-cards/manage/{$card->id}/marker", ['marker' => 4])
    ->assertOk()
    ->assertExactJson(['review_card_id' => $card->id, 'marker' => 4]);

$this->assertSame($before, $card->fresh()->only(array_keys($before)));
$this->assertSame($reviewLogCount, ReviewLog::count());
```

- [ ] Run `php artisan test --filter=ReviewCardMarkerApiTest`; expect route-not-found failures.
- [ ] Register routes beside existing ReviewCard manage mutations:

```php
Route::patch('/review-cards/manage/{reviewCard}/marker', [ReviewCardManageController::class, 'marker']);
Route::post('/review-cards/manage/bulk-marker', [ReviewCardManageController::class, 'bulkMarker']);
```

- [ ] Implement controller validation exactly:

```php
$validated = $request->validate([
    'marker' => ['required', 'integer', 'between:0,7'],
]);
```

```php
$validated = $request->validate([
    'ids' => ['required', 'array', 'min:1', 'max:100'],
    'ids.*' => ['required', 'integer', 'distinct', 'min:1'],
    'marker' => ['required', 'integer', 'between:0,7'],
]);
```

Single access must call `ReviewCardManageAccessService::findManageableSenseCardOrFail()` before mutation.

- [ ] Add explicit mutation methods; do not use `fill()` or `update($request->all())`:

```php
public function setMarker(ReviewCard $card, int $marker): ReviewCard
{
    $card->marker = $marker;
    $card->save();

    return $card->refresh();
}
```

Bulk mutation must run inside `DB::transaction`, re-query each ID through existing manageable sense-card isolation, update only Marker, and return `['affected' => ..., 'skipped' => ..., 'marker' => $marker]` without disclosing skipped identities.

- [ ] Run `php artisan test --filter=ReviewCardMarkerApiTest`; expect pass.
- [ ] Run existing `php artisan test --filter=ReviewCardManageTest`; expect pass.
- [ ] Run `git diff --check` and commit exact paths with `git commit -m "feat: add review card marker api"`.

## Task 4: Serialize Marker in Browser and Card Info

**Files:**

- Modify: `app/Services/ReviewCardManageItemSerializerService.php`
- Modify: `tests/Feature/ReviewCardManageTest.php`
- Modify: `tests/Feature/ReviewCardInfoTest.php`

- [ ] Add failing list and detail assertions for exact numeric Marker values, including `0`.
- [ ] Run `php artisan test --filter='ReviewCard(Manage|Info)Test'`; expect missing `marker` assertions.
- [ ] Add the same additive field to both serializer branches:

```php
'marker' => (int) $card->marker,
```

- [ ] Run both targeted tests; expect pass. Confirm no nested `card_info.marker` duplicate was added.
- [ ] Run `git diff --check` and commit exact paths with `git commit -m "feat: expose review card markers"`.

## Task 5: Build shared Marker presentation and mutation owner

**Files:**

- Create: `resources/js/services/ReviewCardMarkerPresentation.js`
- Create: `resources/js/components/ReviewCards/ReviewCardMarkerPicker.vue`
- Create: `tests/js/ReviewCardMarkerPresentation.test.mjs`
- Create: `tests/js/ReviewCardMarkerSurfaceGuard.test.mjs`

- [ ] Add a Node test asserting all eight stable options and fallback-to-zero behavior.
- [ ] Add a surface guard asserting the picker contains both Marker endpoints and that no other ReviewCard Vue file contains `/marker` or `bulk-marker` write URLs.
- [ ] Run both Node tests; expect missing-module/component failures.
- [ ] Implement the presentation module as immutable data:

```js
export const REVIEW_CARD_MARKERS = Object.freeze([
    { value: 0, label: '无标记', color: 'grey', icon: 'mdi-flag-outline' },
    { value: 1, label: '红色', color: 'red', icon: 'mdi-flag' },
    { value: 2, label: '橙色', color: 'orange', icon: 'mdi-flag' },
    { value: 3, label: '绿色', color: 'green', icon: 'mdi-flag' },
    { value: 4, label: '蓝色', color: 'blue', icon: 'mdi-flag' },
    { value: 5, label: '粉色', color: 'pink', icon: 'mdi-flag' },
    { value: 6, label: '青色', color: 'cyan', icon: 'mdi-flag' },
    { value: 7, label: '紫色', color: 'purple', icon: 'mdi-flag' },
]);

export function markerOption(value) {
    return REVIEW_CARD_MARKERS.find(option => option.value === Number(value))
        || REVIEW_CARD_MARKERS[0];
}
```

- [ ] Implement one picker supporting either `cardId` or non-empty `ids`. It must keep the canonical prop value until success, disable while saving, show local error, and emit `updated` only from server response. Use:

```js
const request = this.ids.length
    ? axios.post('/review-cards/manage/bulk-marker', { ids: this.ids, marker })
    : axios.patch(`/review-cards/manage/${this.cardId}/marker`, { marker });
```

- [ ] Run the Node tests; expect pass.
- [ ] Run `npm run development`; expect successful build.
- [ ] Commit exact paths with `git commit -m "feat: add review card marker picker"`.

## Task 6: Integrate Marker into Browser and Card Info

**Files:**

- Modify: `resources/js/components/ReviewCards/ReviewCardTableSurface.vue`
- Modify: `resources/js/components/ReviewCards/ReviewCardInfoDrawer.vue`
- Modify: `resources/js/components/ReviewCards/ReviewCardManage.vue`
- Modify: `tests/js/ReviewCardMarkerSurfaceGuard.test.mjs`

- [ ] Extend the guard first to require: row picker, bulk picker, detail picker, `marker-updated` reconciliation, and navigation `{ mode: 'marked' }` with no `ids` query key.
- [ ] Run the guard; expect failure on missing integration.
- [ ] Embed `ReviewCardMarkerPicker` in row/detail/bulk surfaces. Children may emit `marker-updated`, `bulk-marker-updated`, and `study-marked`; they must not issue Axios writes.
- [ ] Reconcile canonical server results in `ReviewCardManage.vue`:

```js
onMarkerUpdated({ review_card_id: id, marker }) {
    this.items = this.items.map(item => item.id === id ? { ...item, marker } : item);
}

openMarkedStudy() {
    this.$router.push({ path: '/custom-study', query: { mode: 'marked' } });
}
```

For bulk success, update only currently loaded items whose IDs were submitted. The next refresh remains authoritative for skipped or off-page rows.

- [ ] Add accessible labels, loading state, empty selection disablement, and a visible `学习已标记卡片` action in both Browser and Card Info.
- [ ] Run the Node guard; expect pass.
- [ ] Run `npm run development`; expect successful build.
- [ ] Commit exact paths with `git commit -m "feat: integrate card marker controls"`.

## Task 7: Add the parameterless `marked` criteria and candidate query

**Files:**

- Modify: `app/Services/CustomStudy/CustomStudyCriteria.php`
- Create: `app/Services/CustomStudy/Queries/MarkedQuery.php`
- Modify: `app/Services/CustomStudy/CustomStudyQueryService.php`
- Modify: `tests/Unit/CustomStudyCriteriaTest.php`
- Create: `tests/Feature/CustomStudyMarkedQueryTest.php`
- Modify: `tests/Unit/CustomStudyQueryServiceTest.php`

- [ ] Add failing tests that `marked` accepts no parameters and ignores unknown parameter keys; query includes only current-user/current-language confirmed, eligible sense cards with `marker > 0`; query service dispatches once and returns unique positive IDs.
- [ ] Include negative fixtures: marker `0`, suspended, archived, unexpired buried, disabled, unconfirmed sense, legacy target, other user, and other language.
- [ ] Run the three targeted test classes; expect unknown-mode/missing-class failures.
- [ ] Add `MODE_MARKED = 'marked'` to `ALLOWED_MODES` and return `[]` beside the two other parameterless cases.
- [ ] Implement the query without a new abstraction:

```php
final class MarkedQuery
{
    public function __construct(
        private readonly SenseReviewQueryService $senseReviewQueryService
    ) {
    }

    public function build(int $userId, string $language, Carbon $now): Builder
    {
        return $this->senseReviewQueryService
            ->confirmedSenseCardQuery($userId, $language)
            ->senseReviewEligible($userId, $language, $now)
            ->where('review_cards.marker', '>', ReviewCard::MARKER_NONE);
    }
}
```

- [ ] Inject `MarkedQuery` into `CustomStudyQueryService` and add one dispatch case that terminates with `pluck('review_cards.id')->all()`.
- [ ] Run targeted tests; expect pass.
- [ ] Commit exact paths with `git commit -m "feat: add marked custom study query"`.

## Task 8: Carry `marked` through ordering, session, and no-write boundaries

**Files:**

- Modify: `app/Services/CustomStudy/CustomStudySessionOrder.php`
- Modify: `tests/Unit/CustomStudySessionOrderTest.php`
- Modify: `tests/Feature/CustomStudyOpenSessionTest.php`
- Modify: `tests/Feature/CustomStudyNoWriteAcceptanceTest.php`
- Modify: `tests/Feature/CustomStudySecurityTest.php`

- [ ] Add failing order test proving `marked` returns canonical Review Queue order with no marker-color priority.
- [ ] Add open/resume/answer test for a marked token and assert token shape/version is unchanged.
- [ ] Add no-write assertions comparing ReviewCard scheduling/lifecycle/Marker, WordSense, and ReviewLog before and after preview answers.
- [ ] Add strict isolation tests for forged IDs and eligibility changes between open/resume/answer.
- [ ] Run the targeted tests; expect ordering or fixture failures before implementation.
- [ ] Route `MODE_MARKED` to the existing canonical fallback:

```php
case CustomStudyCriteria::MODE_MARKED:
case CustomStudyCriteria::MODE_SOURCE_CHAPTER:
default:
    return $canonicalOrdered->map->id->values()->all();
```

No token field or version change is allowed.

- [ ] Run all targeted tests; expect pass.
- [ ] Run `php artisan test --filter=CustomStudy`; expect pass.
- [ ] Commit exact paths with `git commit -m "feat: support marked custom study sessions"`.

## Task 9: Add the Custom Study UI mode and route preselection

**Files:**

- Modify: `resources/js/components/CustomStudy.vue`
- Modify: `tests/js/CustomStudyPageGuard.test.mjs`

- [ ] Extend the guard first to require a fifth `marked` choice, zero parameter payload, validated route-query preselection, and fallback to `today_forgotten` for unknown query values.
- [ ] Run `node --test tests/js/CustomStudyPageGuard.test.mjs`; expect failure.
- [ ] Add the fifth radio option `已标记的词义` and validate preselection locally:

```js
const allowedModes = [
    'today_forgotten',
    'overdue',
    'source_chapter',
    'leech_attention',
    'marked',
];
const requestedMode = this.$route.query.mode;
if (allowedModes.includes(requestedMode)) {
    this.mode = requestedMode;
}
```

Build `{ mode: 'marked', parameters: {} }`; never include Browser IDs. The user must still press the existing start button.

- [ ] Run the guard; expect pass.
- [ ] Run `npm run development`; expect successful build.
- [ ] Commit exact paths with `git commit -m "feat: add marked custom study mode"`.

## Task 10: Complete automated Phase 4 regression

**Files:**

- Modify only failing Phase 4 files already named above when the failure proves an implementation defect.

- [ ] Run testing DB health: `php artisan test --filter=TestingDatabaseHealthTest`.
- [ ] Run Marker tests:

```powershell
php artisan test --filter='ReviewCardMarker|ReviewCardManageTest|ReviewCardInfoTest'
```

- [ ] Run Custom Study tests: `php artisan test --filter=CustomStudy`.
- [ ] Run protected regressions:

```powershell
php artisan test --filter=ReviewFsrsTest
php artisan test --filter=FsrsSchedulingServiceTest
php artisan test --filter=WordSense
```

- [ ] Run Node guards:

```powershell
node --test tests/js/ReviewCardMarkerPresentation.test.mjs tests/js/ReviewCardMarkerSurfaceGuard.test.mjs tests/js/CustomStudyPageGuard.test.mjs tests/js/CardMarkerCustomStudyDocsGuard.test.mjs
```

- [ ] Run `npm run development` and `git diff --check`.
- [ ] Fix only evidenced in-scope failures using the same red/green sequence. Record unrelated environmental failures verbatim; do not weaken assertions.
- [ ] Inspect `git status --short` and `git diff --stat`; verify no `.env`, generated assets, `.playwright-cli/`, `nul`, or pre-existing unrelated changes are staged.

## Task 11: Perform authenticated browser acceptance and database delta proof

**Files:**

- Create only if needed for durable evidence: `docs/history/phase-4-card-marker-custom-study-1b-acceptance.md`

- [ ] Capture a read-only baseline for the acceptance card: Marker, FSRS fields, lifecycle, WordSense fields, and ReviewLog count. Store no credentials.
- [ ] In authenticated Chrome at `1920x1080`: set and clear each Marker value, bulk set selected rows, refresh for persistence, open Card Info, and enter `/custom-study?mode=marked` from both entry points.
- [ ] Verify empty marked state and exclusion of suspended, archived, buried, disabled, unconfirmed, cross-user, and cross-language fixtures.
- [ ] Complete the preview answer loop and verify no formal-rating request is sent.
- [ ] Repeat layout acceptance at `900x900`; inspect Console and failed Network requests.
- [ ] Capture the post-run database snapshot and prove ReviewLog count, FSRS, lifecycle, and WordSense delta are zero; only intentional Marker values may differ.
- [ ] Restore acceptance Marker values through the public Marker API, not direct SQL. Re-check the protected delta.
- [ ] Write the acceptance report only if it records reproducible evidence not already captured by tests.

## Task 12: Close Phase 4 and audit the slice

**Files:**

- Modify: `docs/plans/anki-aligned-product-and-architecture-roadmap.md`
- Modify: `docs/DOCUMENTATION_INDEX.md` only if a new acceptance report was created
- Modify: `docs/history/phase-4-card-marker-custom-study-1b-acceptance.md` only if created in Task 11

- [ ] Use `code-review-and-quality` for a task-scoped review of API isolation, write boundaries, Vue mutation ownership, test adequacy, and scope.
- [ ] Use a fresh-context adversarial review for behavior, safety, authority, and acceptance issues. Fix only material in-scope findings; stop after the next round contains no behavioral issue.
- [ ] Re-run every command in Task 10 after the final code change.
- [ ] Mark Phase 4 complete in the roadmap only when automated and browser evidence passes; otherwise record the exact incomplete acceptance item and leave it `In Progress`.
- [ ] Run:

```powershell
git diff --check
git status --short
git log -8 --oneline
```

- [ ] Stage only the exact closure-document paths and commit with `git commit -m "docs: close card marker custom study milestone"`.
- [ ] Confirm Phase 5 is the next roadmap milestone; do not begin it until the Phase 4 completion audit reports no unresolved material issue.
