# ADR-0011: Sense Leech Governance and Rewrite Package

Date: 2026-07-12
Status: Accepted
Supersedes: —
Related: ADR-0004 (AI Study Card V6 real AI boundary), ADR-0009 (review action ledger), ADR-0010 (review card lifecycle state machine)

## Context

LinguaCafe's sense review system currently tracks per-card forgetting metrics
(forget_rate, trend, again/hard/good/easy counts, fsrs_lapses) through
`SenseReviewLearningFeedbackService` and `SenseReviewReportMetricsService`.
However, there is no higher-level classification that distinguishes a card
that occasionally fails from one that is persistently forgotten ("leech" in
Anki terminology).

Users have no way to:
1. See which senses they keep forgetting.
2. Get actionable suggestions (rewrite example, suspend, edit sense).
3. Generate a structured "rewrite prompt package" to take to an external AI
   for improving the example sentence or Chinese definition — without
   LinguaCafe automatically calling any AI provider.

The review card lifecycle state machine (ADR-0010) provides `suspend` as a
manual action, but leech detection is orthogonal: a leech is not a lifecycle
state, it is a read-only classification computed from review history.

## Decision

### 1. Leech scope: sense cards only

Leech governance applies ONLY to:
- `ReviewCard.target_type = 'sense'`
- `WordSense.status = 'confirmed'`

Legacy word cards are excluded. Rejected/draft senses are excluded.

### 2. Three classification states (read-only, not lifecycle)

```
stable      — normal learning, no significant difficulty
struggling  — recent difficulty, but not yet persistent failure
leech       — repeated forgetting, governance recommended
```

These are **computed** values, not stored. They are derived from:
- `ReviewLog` history (excluding reset and undone logs)
- `ReviewCard.fsrs_lapses` (FSRS failure count)
- `ReviewCard.fsrs_stability` (FSRS stability)
- `learning_feedback` aggregate (forget_rate, trend)

### 3. Classification thresholds

```
leech:
  - again_count >= 3 AND total_reviews >= 5
  - OR last 7 reviews: (again + hard) >= 4

struggling:
  - last 5 reviews: (again + hard) >= 3
  - OR fsrs_lapses >= 2 AND forgetting_pattern.trend = 'declining'

stable:
  - all other cards (including new cards with < 3 reviews)
```

### 4. Pure policy, no side effects

`SenseReviewLeechPolicy` is a pure function:
- Input: ReviewCard, WordSense, feedback descriptor, lifecycle descriptor, now
- Output: `{status, severity, reasons[], suggestions[], blocked_actions[]}`
- Does NOT query DB, write DB, call AI, call lifecycle mutation, or modify FSRS.

### 5. Rewrite package: no AI call

`SenseReviewLeechRewritePackageService` generates a
`sense-leech-rewrite-package-v1` JSON + Markdown package containing:
- schema_version, generated_at
- review_card_id, word_sense_id, lemma, part_of_speech
- sense_zh, sense_en, current_example, source_context
- recent_review_summary, forgetting_reasons
- user_goal, output_contract, safety_rules

The package explicitly sets:
- `provider_called = false`
- `card_created = false`
- `review_log_created = false`

The user copies this package to an external AI manually. LinguaCafe does NOT:
- call provider-preview
- call any AI HTTP endpoint
- auto-create WordSense / ReviewCard / ReviewLog
- auto-modify sense definitions

### 6. Lifecycle boundary

Leech is NOT a lifecycle_state. Interaction with lifecycle:
- Leech can SUGGEST `suspend` — but suspend must go through
  `ReviewCardLifecycleCommandService::act($card, 'suspend', ...)`.
- Leech never directly writes lifecycle fields.
- Suspended / archived cards still show leech_status on the management page
  (read-only), but do NOT appear in the review queue.
- `blocked_actions[]` prevents suggesting suspend on an already-suspended card.

### 7. No new migration

All leech data is computed from existing tables:
- `review_logs` (rating, reviewed_at, source, undone_at)
- `review_cards` (fsrs_lapses, fsrs_stability, fsrs_reps, lifecycle_state)
- `word_senses` (lemma, sense_zh, sense_en, example_sentence_en, status)

No new columns, no new tables.

### 8. ReviewLog exclusion rules

Leech computation uses the same exclusion as all product analytics:
- `review_logs.source = 'sense_review'` (POSITIVE filter — only real sense-review ratings)
- `review_logs.undone_at IS NULL` (exclude undone)
- `review_logs.source != 'reset'` AND `review_logs.rating != 'reset'` (exclude reset)
- Via `SenseReviewQueryService::nonResetCardReviewLogQuery` and
  `SenseReviewQueryService::nonResetSenseReviewLogQuery`

**Task 2000-4 fix (2026-07-12):** The positive `source = 'sense_review'`
filter was added to ensure that only real sense-review ratings participate
in leech classification, learning feedback, and product analytics. Other
sources (e.g. `review` for word reviews, `acceptance_test` for test data)
are excluded. Audit interfaces that query ReviewLog directly are NOT
affected — they continue to see all sources.

### 9. Management page integration

The management page list payload is extended with optional leech fields:
- `leech_status` (stable|struggling|leech)
- `leech_severity` (0-100)
- `leech_reasons[]`
- `leech_suggestions[]`

**Task 2000-4 fix (2026-07-12):** The leech/struggling filters now use
REAL Policy classification (via `SenseReviewLeechQueryService::filterCardIdsByLeechStatus`)
instead of SQL proxy rules. This ensures the filter, pagination total,
and in-row badges all use the same classification source. The classification
considers ALL lifecycle states (active, suspended, archived, buried) so
that suspended/archived leech cards remain findable in the management filter.

New filter values: `leech`, `struggling` (additive to existing lifecycle filters).

### 10. Batch governance

- `POST /review-cards/manage/bulk-leech-rewrite-packages` — generates a
  combined JSON/Markdown package for multiple cards. Each card gets its own
  package entry. Partial failures are reported per-card.
- Bulk suspend uses the existing `POST /review-cards/manage/bulk-lifecycle`
  endpoint (action=suspend) — NOT a new endpoint.

### 11. Audit and statistics boundary

- Leech classification is NOT audited (it is a computed read-only value).
- Leech does not write to `review_card_state_events`.
- Leech does not affect daily report, 7-day trend, or 30-day calendar.
- Leech does not modify FSRS scheduling or review queue.

## Consequences

### Positive

- Users can identify persistently forgotten senses.
- Actionable suggestions without auto-modifying data.
- Rewrite package provides a safe, structured prompt for external AI use.
- No database changes required.
- Clean separation: leech is read-only classification, lifecycle is state.

### Negative

- Leech computation requires ReviewLog batch query (mitigated by batch loading).
- Classification thresholds are heuristic and may need tuning.
- Rewrite package is manual (user must copy to external AI).

### Rollback

If leech governance needs to be rolled back:
1. Remove leech routes from `routes/web.php`.
2. Remove leech fields from `ReviewCardManageItemSerializerService`.
3. Remove leech filter from `ReviewCardManageQueryService`.
4. Remove `SenseReviewLeech*` services.
5. No database rollback needed (no migration was added).

## Implementation

### New files (Task B)

```
app/Services/SenseReviewLeechPolicy.php
app/Services/SenseReviewLeechQueryService.php
app/Services/SenseReviewLeechRewritePackageService.php
app/Http/Controllers/SenseReviewLeechController.php
tests/Unit/SenseReviewLeechPolicyTest.php
tests/Feature/SenseReviewLeechQueryTest.php
tests/Feature/SenseReviewLeechRewritePackageTest.php
tests/Feature/ReviewCardManageLeechTest.php
tests/Feature/SenseReviewLeechLifecycleBoundaryTest.php
```

### Modified files (Task B)

```
routes/web.php                                      (4 new routes)
app/Services/ReviewCardManageQueryService.php       (leech/struggling filters)
app/Services/ReviewCardManageItemSerializerService.php (leech fields in payload)
app/Http/Controllers/ReviewCardManageController.php  (leech-summary endpoint)
```

### New files (Task A)

```
resources/js/services/SenseReviewLeechPresentation.js
resources/js/components/Senses/SenseReviewLeechPanel.vue
resources/js/components/Senses/SenseReviewLeechRewritePackageDialog.vue
tests/js/SenseReviewLeechPresentationGuard.test.mjs
tests/js/SenseReviewLeechPanelGuard.test.mjs
tests/js/ReviewCardManageLeechGuard.test.mjs
tests/js/SenseReviewLeechRewritePackageGuard.test.mjs
```

### Modified files (Task A)

```
resources/js/components/Senses/SenseReview.vue       (integrate leech panel)
resources/js/components/ReviewCards/ReviewCardManage.vue (filters, badges, batch)
```

### Task 2000-4 fix: source boundary, batch query, filter consistency

**Date:** 2026-07-12

Three issues fixed:

1. **Source boundary:** Added positive `source = 'sense_review'` filter to
   `SenseReviewQueryService::nonResetCardReviewLogQuery()` and
   `nonResetSenseReviewLogQuery()`. Other sources (`review`, `reset`,
   `acceptance_test`, etc.) are now excluded from leech/feedback/analytics.

2. **Batch query N+1:** Added `describeForCardWithFeedback()` and
   `describeForCardsWithFeedbackMap()` to `SenseReviewLeechQueryService`.
   Controller's `rewritePackage()` and `bulkRewritePackages()` now build
   feedback once and reuse it — 1 ReviewLog query regardless of card count
   (was N+1 for batch, 2 for single).

3. **Filter consistency:** Replaced SQL proxy filters in
   `ReviewCardManageQueryService::applyFilters()` with real Policy
   classification via `getLeechFilteredCardIds()`. The filter, pagination
   total, and in-row badges now all use the same classification source.

```
Modified files (Task 2000-4):
app/Services/SenseReviewQueryService.php             (source=sense_review boundary)
app/Services/SenseReviewLeechQueryService.php        (describeWithFeedback methods)
app/Http/Controllers/SenseReviewLeechController.php  (reuse feedback, no N+1)
app/Services/ReviewCardManageQueryService.php        (real Policy filter)

New test files (Task 2000-4):
tests/Feature/SenseReviewLeechQueryBudgetTest.php    (5 query-budget tests)
tests/Feature/SenseReviewLeechFilterConsistencyTest.php (12 filter-consistency tests)
```
