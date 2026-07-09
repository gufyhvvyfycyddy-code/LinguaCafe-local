# Sense Review Understanding Helper Playbook

> **Status**: Active.
> **Date**: 2026-07-09.
> **Task**: SenseReviewUnderstandingAid-1000-7.
> **Use when**: Any change to the `understanding_aid` JSON column on `word_senses`, `SenseReviewCardSerializerService::normalizeUnderstandingAid`, or the "理解这个词义" collapsible block in `SenseReview.vue`.

---

## 1. Why understanding aid exists

When reviewing a sense, the learner sees the Chinese gloss and one example sentence. This is enough to recall the meaning, but it does not help the learner form a **sense boundary** — i.e. understand *why* this example represents this sense and how to distinguish it from near-synonyms.

The understanding aid layer adds four optional, sense-level (not occurrence-level) hints:

| Field | Purpose |
|---|---|
| `explanation` | A short note explaining why the displayed example represents this sense. |
| `meaning_boundary` | A contrast with near-synonyms to sharpen the sense boundary. |
| `context_hint` | What contextual cues in the sentence signal this sense. |
| `usage_keywords` | Common collocations / trigger phrases associated with this sense. |

All four are optional and may be null/empty. When all are empty, the collapsible block is hidden entirely (`hasUnderstandingAid === false`).

---

## 2. Data source

- **Column**: `word_senses.understanding_aid` (JSON, nullable).
- **Migration**: `2026_07_09_000001_add_understanding_aid_to_word_senses_table.php`.
- **Model**: `WordSense` casts `understanding_aid` to `array`, listed in `$fillable`.
- **Level**: Sense-level — the same aid is shown regardless of which occurrence is currently displayed during rotation. This is intentional: the aid describes the *sense*, not a specific sentence.
- **Population**: Currently manual (set via tinker / script / future edit dialog). No auto-generation, no external AI call. This respects the project's "no auto-call external AI" boundary.

---

## 3. Display logic

The block lives in the **left column** of the answer side, after "搭配" (collocations):

```
中文释义    [sense_zh]
英文释义    [sense_en]
近义译法    [aliases_zh chips]
搭配        [collocations chips]
理解这个词义  ▶  (collapsed by default)
  ┌─────────────────────────────────┐
  │ explanation text                │
  │ 词义边界：meaning_boundary       │
  │ 上下文提示：context_hint         │
  │ 常见搭配关键词：[kw1] [kw2] ...  │
  └─────────────────────────────────┘
```

- **Default**: Collapsed (`understandingAidOpen = false`). Resets to collapsed on every card change (in `loadCards`).
- **Toggle**: User clicks the "理解这个词义" header. No network call is made.
- **Visibility guard**: `hasUnderstandingAid` computed returns false when all sub-fields are empty/null, so the entire block (including the header) is hidden. This keeps the answer side clean for senses without aid data.
- **Read-only**: Expanding/collapsing never triggers an API call, never writes a ReviewLog, never touches FSRS.

---

## 4. Serialization

`SenseReviewCardSerializerService::serialize()` returns:

```json
{
  "understanding_aid": {
    "explanation": "string|null",
    "meaning_boundary": "string|null",
    "context_hint": "string|null",
    "usage_keywords": ["string", ...]
  }
}
```

`normalizeUnderstandingAid(?array $value)` guarantees a stable structure even when the column is null or only partially populated. Missing keys default to `null` (strings) or `[]` (usage_keywords). `usage_keywords` is re-indexed with `array_values` to avoid sparse-array JSON encoding issues.

---

## 5. FSRS boundary (critical)

| Action | ReviewLog | ReviewCard | FSRS fields | WordSense |
|---|---|---|---|---|
| Serialize card (load `/reviews/senses`) | unchanged | unchanged | unchanged | unchanged |
| Expand "理解这个词义" | unchanged | unchanged | unchanged | unchanged |
| Collapse "理解这个词义" | unchanged | unchanged | unchanged | unchanged |
| Rate `again` / `hard` / `good` / `easy` | +1 | target card only | target card only | unchanged |

**Only user rating** (`POST /reviews/senses/{id}/rate`) writes a ReviewLog and updates FSRS. Expanding the understanding aid block is a pure client-side state change (`understandingAidOpen` boolean) with zero server interaction.

---

## 6. Occurrence safety

The understanding aid is **sense-level**, not occurrence-level. When the review queue rotates to a different occurrence (via `WordSenseExamplePoolService::pickQuestionIndex`), the `understanding_aid` payload stays identical because it is read from `$sense->understanding_aid`, not from the occurrence. This is verified by `test_understanding_aid_is_sense_level_not_occurrence_level`.

---

## 7. Test command matrix

```
# Core understanding aid tests (must be all green)
php artisan test --filter=SenseReviewUnderstandingAidTest --stop-on-failure

# Sense review full suite
php artisan test --filter=SenseReview --stop-on-failure

# WordSense + FSRS regression
php artisan test --filter=WordSense --stop-on-failure
php artisan test --filter=ReviewFsrsTest --stop-on-failure
php artisan test --filter=FsrsSchedulingServiceTest --stop-on-failure

# Multi-example + source context regression
php artisan test --filter=SenseMultiExample --stop-on-failure
php artisan test --filter=SenseSourceContext --stop-on-failure

# Full regression sweep
php artisan test --filter="SenseReview|WordSense|ReviewFsrsTest|FsrsSchedulingServiceTest|SenseMultiExample|SenseSourceContext|UnderstandingAid"

# Frontend build
npm run development
```

**Expected counts** (as of 2026-07-09):
- `SenseReviewUnderstandingAidTest`: 6 passed (21 assertions)
- Full regression sweep: 1 skipped, 379 passed (1755 assertions)

---

## 8. MCP Chrome real acceptance

### Prerequisites

1. At least one due sense review card for the test user.
2. The card's `word_sense` must have `understanding_aid` populated (otherwise the block is hidden and cannot be verified). Populate via tinker:
   ```php
   $sense = WordSense::find(ID);
   $sense->understanding_aid = [
       'explanation' => '...',
       'meaning_boundary' => '...',
       'context_hint' => '...',
       'usage_keywords' => ['go to', 'account'],
   ];
   $sense->save();
   ```

### Steps

1. Login to `http://127.0.0.1:8000` (account: `1816529781@qq.com`).
2. Navigate to `/reviews/senses`.
3. Confirm the page loads and the target card is displayed (check `lemma`).
4. Click "显示答案" (or press Space).
5. **Verify collapsed**: The "理解这个词义" header is visible but the explanation text is NOT in the DOM. Check `understandingAidOpen === false` via Vue inspector.
6. **Expand**: Click the "理解这个词义" header (or set `understandingAidOpen = true`). Verify all populated sub-fields render.
7. **Rating buttons**: Confirm all 4 buttons (忘了 / 勉强记得 / 记得 / 很熟) are present and NOT disabled.
8. **More menu**: Confirm the "更多" button is present and enabled.
9. **Source context**: Trigger `viewSource()` (or click "查看原文" from the More menu). Confirm the source context dialog opens and shows "已定位到当前复习例句".
10. **FSRS unchanged**: Query `review_cards` and `review_logs` for the card before and after. Counts and FSRS fields must be identical.

### Network acceptance

All browser requests must hit `127.0.0.1:8000` (or `localhost`). The following domains must NOT appear:

- `api.deepseek.com`
- `api.openai.com`
- `api.anthropic.com`
- `generativelanguage.googleapis.com`
- `api.x.ai`

Expected requests (all to localhost):
- `GET /reviews/senses`
- `GET /review-cards/stats`
- `GET /fonts/get-fonts-for-language/english`
- `GET /senses/{id}/source-context-list?preferred_occurrence_id=...` (only if source context opened)

---

## 9. Refuse conditions

Mark Refuse if any of these occur:

1. Serialize (loading `/reviews/senses`) writes a ReviewLog.
2. Serialize changes any FSRS field.
3. Expanding/collapsing "理解这个词义" triggers a network request.
4. `understanding_aid` is occurrence-level (changes when the displayed occurrence rotates).
5. The block renders an empty state (all sub-fields null) instead of being hidden.
6. The block is expanded by default (must be collapsed).
7. Rating buttons are disabled or hidden by the understanding aid block.
8. The More menu or "查看原文" is broken by the understanding aid block.
9. Browser calls an external provider domain.
10. `normalizeUnderstandingAid` writes to the database (it must be read-only).
11. The migration is not reversible (the `down()` method must drop the column).

---

## 10. Accept / Refuse / Incomplete

- **Accept**: All tests green, MCP Chrome confirms collapsed-by-default + expand + content render + rating buttons enabled + More menu enabled + source context works + Network only localhost + FSRS unchanged.
- **Refuse**: Any Refuse condition triggered, or tests fail, or FSRS/ReviewLog changed during acceptance.
- **Incomplete**: Login fails, no card with `understanding_aid` populated exists, MCP Chrome cannot inspect Network, or browser automation fails before verification.

---

## 11. Allowed file boundary

- `database/migrations/2026_07_09_000001_add_understanding_aid_to_word_senses_table.php` — column migration.
- `app/Models/WordSense.php` — fillable + casts.
- `app/Services/SenseReviewCardSerializerService.php` — `normalizeUnderstandingAid` + serialize passthrough.
- `resources/js/components/Senses/SenseReview.vue` — collapsible block + computed + data field.
- `tests/Feature/SenseReviewUnderstandingAidTest.php` — test coverage.
- `docs/testing/sense-review-understanding-helper-playbook.md` — this file.

**Do NOT modify** without architecture review:
- `app/Services/ReviewCardService.php` (FSRS rating path).
- `app/Services/WordSenseExamplePoolService.php` (rotation logic).
- `app/Services/SenseSourceContextService.php` (source context logic).
- `app/Models/ReviewCard.php` (data model).
- Any FSRS-related service or test.

---

## 12. Rollback

To roll back the understanding aid feature:

1. `php artisan migrate:rollback` (drops the `understanding_aid` column).
2. Revert `SenseReviewCardSerializerService.php` (remove `normalizeUnderstandingAid` and the `understanding_aid` key from the return array).
3. Revert `SenseReview.vue` (remove the collapsible block, computed properties, and data field).
4. Revert `WordSense.php` (remove from `$fillable` and `casts()`).
5. Delete `SenseReviewUnderstandingAidTest.php`.

Rollback does NOT affect FSRS, ReviewLog, ReviewCard, WordSenseOccurrence, or source context — the feature is purely additive and read-only.
