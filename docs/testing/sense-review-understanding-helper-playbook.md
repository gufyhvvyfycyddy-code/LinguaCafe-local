# Sense Review Understanding Helper Playbook

> **Status**: Active.
> **Date**: 2026-07-09 (Task 4 update: contextual / occurrence-level merge).
> **Task**: SenseReviewUnderstandingAid-1000-7 → SenseReviewContextualUnderstanding-1000-10.
> **Use when**: Any change to the `understanding_aid` JSON column on `word_senses`, the `evidence` JSON column on `word_sense_occurrences`, `SenseReviewCardSerializerService::normalizeUnderstandingAid` / `mergeOccurrenceEvidence`, `WordSenseExamplePoolService::pickQuestionIndexWithContext`, or the "理解这个词义" collapsible block in `SenseReview.vue`.

---

## 1. Why understanding aid exists

When reviewing a sense, the learner sees the Chinese gloss and one example sentence. This is enough to recall the meaning, but it does not help the learner form a **sense boundary** — i.e. understand *why* this example represents this sense and how to distinguish it from near-synonyms.

The understanding aid layer is **two-level**:

- **Sense-level** (stored on `word_senses.understanding_aid`): describes the sense itself.
- **Occurrence-level override** (stored on `word_sense_occurrences.evidence`): describes why *this specific displayed occurrence* illustrates the sense. When present, occurrence-level fields override the sense-level ones for the displayed example.

Sense-level fields:

| Field | Purpose |
|---|---|
| `explanation` | A short note explaining why the displayed example represents this sense. |
| `meaning_boundary` | A contrast with near-synonyms to sharpen the sense boundary. |
| `context_hint` | What contextual cues in the sentence signal this sense. |
| `usage_keywords` | Common collocations / trigger phrases associated with this sense. |
| `related_collocations` | Similar usages / phrases to anchor the sense boundary ("类似使用"). |

Occurrence-level override fields (subset, stored inside `evidence` JSON):

| Field | Purpose |
|---|---|
| `context_hint` | Overrides sense-level `context_hint` for the current occurrence. |
| `judgment_basis` | Overrides sense-level `usage_keywords` ("判断依据" for this occurrence). |
| `related_collocations` | Overrides sense-level `related_collocations` for this occurrence. |

All fields are optional and may be null/empty. When all rendered sub-fields are empty, the collapsible block is hidden entirely (`hasUnderstandingAid === false`).

---

## 2. Data source

### Sense-level

- **Column**: `word_senses.understanding_aid` (JSON, nullable).
- **Migration**: `2026_07_09_000001_add_understanding_aid_to_word_senses_table.php`.
- **Model**: `WordSense` casts `understanding_aid` to `array`, listed in `$fillable`.

### Occurrence-level

- **Column**: `word_sense_occurrences.evidence` (JSON, nullable). **No new migration** — this column pre-exists and is reused.
- **Model**: `WordSenseOccurrence` casts `evidence` to `array`.
- **Reused keys**: `context_hint`, `judgment_basis`, `related_collocations`. Other keys inside `evidence` (e.g. `raw_payload` siblings) are untouched by the serializer.

### Population

Currently manual (set via tinker / script / future edit dialog). No auto-generation, no external AI call. This respects the project's "no auto-call external AI" boundary.

---

## 3. Display logic

The block lives in the **left column** of the answer side, after "搭配" (collocations):

```
中文释义    [sense_zh]
英文释义    [sense_en]
近义译法    [aliases_zh chips]
搭配        [collocations chips]
理解这个词义  ▶  (collapsed by default)
  ┌─────────────────────────────────────────────┐
  │ explanation text                            │
  │ 词义边界：meaning_boundary                   │
  │ 上下文提示：context_hint  (occurrence-aware) │
  │ 判断依据：[kw1] [kw2] ...  (occurrence-aware)│
  │ 类似使用：[c1] [c2] ...  (occurrence-aware)  │
  └─────────────────────────────────────────────┘
```

- **Default**: Collapsed (`understandingAidOpen = false`). Resets to collapsed on every card change (in `loadCards`).
- **Toggle**: User clicks the "理解这个词义" header. No network call is made.
- **Visibility guard**: `hasUnderstandingAid` computed returns false when all sub-fields (`explanation`, `meaning_boundary`, `context_hint`, `usage_keywords`, `related_collocations`) are empty/null, so the entire block (including the header) is hidden. This keeps the answer side clean for senses without aid data.
- **Read-only**: Expanding/collapsing never triggers an API call, never writes a ReviewLog, never touches FSRS.
- **Follows current occurrence**: When the queue rotates to a different occurrence (or the user switches examples via the source-context dialog), the serializer re-merges occurrence-level evidence, so `context_hint`, `判断依据`, `类似使用` reflect the *currently displayed* example.

---

## 4. Serialization

`SenseReviewCardSerializerService::serialize()` returns:

```json
{
  "understanding_aid": {
    "explanation": "string|null",
    "meaning_boundary": "string|null",
    "context_hint": "string|null",
    "usage_keywords": ["string", ...],
    "related_collocations": ["string", ...]
  }
}
```

### Helper functions

- `normalizeUnderstandingAid(?array $value)` guarantees a stable structure even when the column is null or only partially populated. Missing keys default to `null` (strings) or `[]` (arrays). Arrays are re-indexed with `array_values` to avoid sparse-array JSON encoding issues.
- `mergeOccurrenceEvidence(array $senseAid, ?array $occurrenceEvidence)` overlays occurrence-level keys on top of the sense-level payload:
  - `context_hint` ← occurrence `context_hint` if non-empty, else sense-level.
  - `usage_keywords` (rendered as "判断依据") ← occurrence `judgment_basis` if non-empty array, else sense-level `usage_keywords`.
  - `related_collocations` ← occurrence `related_collocations` if non-empty array, else sense-level.
  - `explanation` and `meaning_boundary` are always sense-level (occurrence never overrides them).

### Smart example selection

`WordSenseExamplePoolService::pickQuestionIndexWithContext()` chooses the displayed example with this priority:

1. `preferred_occurrence_id` (when the user opened the source-context dialog and pinned the current example).
2. Linear rotation fallback: `(reviewCardId + fsrsReps + fsrsLapses) % total`.

The serializer passes `preferred_occurrence_id` from the request into the pool service, then merges the chosen occurrence's `evidence` into the sense-level understanding aid.

---

## 5. FSRS boundary (critical)

| Action | ReviewLog | ReviewCard | FSRS fields | WordSense |
|---|---|---|---|---|
| Serialize card (load `/reviews/senses`) | unchanged | unchanged | unchanged | unchanged |
| Expand "理解这个词义" | unchanged | unchanged | unchanged | unchanged |
| Collapse "理解这个词义" | unchanged | unchanged | unchanged | unchanged |
| Switch example via source-context dialog | unchanged | unchanged | unchanged | unchanged |
| Rate `again` / `hard` / `good` / `easy` | +1 | target card only | target card only | unchanged |

**Only user rating** (`POST /reviews/senses/{id}/rate`) writes a ReviewLog and updates FSRS. Expanding the understanding aid block, switching examples, and viewing source context are pure client-side / read-only server interactions with zero ReviewLog writes.

---

## 6. Occurrence-aware behaviour

The understanding aid is **sense-level + occurrence-level merged**, not occurrence-isolated.

- Sense-level `explanation` and `meaning_boundary` describe the sense as a whole and are stable across rotations.
- Occurrence-level `context_hint`, `judgment_basis`, `related_collocations` override the sense-level counterparts so the aid "follows the current occurrence".
- When `word_sense_occurrences.evidence` is null/empty, the merged payload falls back to the pure sense-level values, so behaviour for senses without occurrence data is identical to the pre-Task-4 feature.

This is verified by `test_understanding_aid_is_sense_level_not_occurrence_level` (sense-level fallback) and by the contextual test suite (`SenseReviewContextualUnderstandingTest`).

---

## 7. Test command matrix

```
# Core understanding aid tests (must be all green)
php artisan test --filter=SenseReviewUnderstandingAidTest --stop-on-failure

# Contextual / occurrence-level merge tests (Task 4)
php artisan test --filter=SenseReviewContextualUnderstandingTest --stop-on-failure

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
php artisan test --filter="SenseReview|WordSense|ReviewFsrsTest|FsrsSchedulingServiceTest|SenseMultiExample|SenseSourceContext|UnderstandingAid|ContextualUnderstanding"

# Frontend build
npm run development
```

**Expected counts** (as of 2026-07-09, Task 4):
- `SenseReviewUnderstandingAidTest`: 6 passed (21 assertions).
- `SenseReviewContextualUnderstandingTest`: 8 passed (23 assertions).
- `ReviewFsrsTest`: 63 passed (374 assertions).
- `FsrsSchedulingServiceTest`: 9 passed (46 assertions).
- Sense-review full sweep: 1 skipped, 244 passed (1001 assertions).

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
       'related_collocations' => ['bank account', 'central bank'],
   ];
   $sense->save();
   ```
3. (Optional, for occurrence-level verification) Populate `evidence` on at least one occurrence of the same sense:
   ```php
   $occ = WordSenseOccurrence::where('word_sense_id', ID)->first();
   $occ->evidence = [
       'context_hint' => 'this occurrence specifically signals the financial-institution sense',
       'judgment_basis' => ['go to', 'money'],
       'related_collocations' => ['bank account'],
   ];
   $occ->save();
   ```

### Steps

1. Login to `http://127.0.0.1:8000` (account: `1816529781@qq.com`).
2. Navigate to `/reviews/senses`.
3. Confirm the page loads and the target card is displayed (check `lemma`).
4. Click "显示答案" (or press Space).
5. **Verify collapsed**: The "理解这个词义" header is visible but the explanation text is NOT in the DOM. Check `understandingAidOpen === false` via Vue inspector.
6. **Expand**: Click the "理解这个词义" header. Verify all populated sub-fields render, including "类似使用" chips when `related_collocations` is non-empty.
7. **Occurrence switch**: Open the source-context dialog (via "更多" → "查看原文"). Confirm "已定位到当前复习例句" appears and the displayed example matches. Close the dialog and confirm the understanding aid still reflects the displayed example.
8. **Rating buttons**: Confirm all 4 buttons (忘了 / 勉强记得 / 记得 / 很熟) are present and NOT disabled.
9. **More menu**: Confirm the "更多" button is present and enabled.
10. **FSRS unchanged**: Query `review_cards` and `review_logs` for the card before and after expanding/switching. Counts and FSRS fields must be identical (only the eventual rating writes a log).

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
- `POST /reviews/senses/{id}/rate` (only when user rates)

---

## 9. Refuse conditions

Mark Refuse if any of these occur:

1. Serialize (loading `/reviews/senses`) writes a ReviewLog.
2. Serialize changes any FSRS field.
3. Expanding/collapsing "理解这个词义" triggers a network request.
4. Switching examples via the source-context dialog writes a ReviewLog or changes FSRS.
5. The block renders an empty state (all sub-fields null) instead of being hidden.
6. The block is expanded by default (must be collapsed).
7. Rating buttons are disabled or hidden by the understanding aid block.
8. The More menu or "查看原文" is broken by the understanding aid block.
9. Browser calls an external provider domain.
10. `normalizeUnderstandingAid` or `mergeOccurrenceEvidence` writes to the database (they must be read-only).
11. The migration is not reversible (the `understanding_aid` `down()` method must drop the column). `evidence` has no migration of its own (pre-existing column).
12. `preferred_occurrence_id` is ignored and the queue randomly overrides the user's pinned occurrence.
13. Occurrence-level evidence permanently overwrites the sense-level value in the database (override must be in-memory only, during serialization).

---

## 10. Accept / Refuse / Incomplete

- **Accept**: All tests green, MCP Chrome confirms collapsed-by-default + expand + content render + occurrence switch keeps aid in sync + rating buttons enabled + More menu enabled + source context works + Network only localhost + FSRS unchanged.
- **Refuse**: Any Refuse condition triggered, or tests fail, or FSRS/ReviewLog changed during acceptance.
- **Incomplete**: Login fails, no card with `understanding_aid` populated exists, MCP Chrome cannot inspect Network, or browser automation fails before verification.

---

## 11. Allowed file boundary

- `database/migrations/2026_07_09_000001_add_understanding_aid_to_word_senses_table.php` — `understanding_aid` column migration.
- `app/Models/WordSense.php` — fillable + casts.
- `app/Models/WordSenseOccurrence.php` — `evidence` cast (pre-existing, no schema change in Task 4).
- `app/Services/SenseReviewCardSerializerService.php` — `normalizeUnderstandingAid` + `mergeOccurrenceEvidence` + serialize passthrough + `preferred_occurrence_id` plumbing.
- `app/Services/WordSenseExamplePoolService.php` — `pickQuestionIndexWithContext` (smart selection).
- `resources/js/components/Senses/SenseReview.vue` — collapsible block + `related_collocations` display + computed + data field.
- `tests/Feature/SenseReviewUnderstandingAidTest.php` — sense-level test coverage.
- `tests/Feature/SenseReviewContextualUnderstandingTest.php` — occurrence-level + smart selection test coverage (Task 4).
- `docs/testing/sense-review-understanding-helper-playbook.md` — this file.

**Do NOT modify** without architecture review:
- `app/Services/ReviewCardService.php` (FSRS rating path).
- `app/Services/SenseSourceContextService.php` (source context logic).
- `app/Models/ReviewCard.php` (data model).
- Any FSRS-related service or test.

---

## 12. Rollback

To roll back the understanding aid feature (sense-level + Task 4 contextual layer):

1. `php artisan migrate:rollback` (drops the `understanding_aid` column). The `evidence` column on `word_sense_occurrences` is left untouched (pre-existing).
2. Revert `SenseReviewCardSerializerService.php` (remove `normalizeUnderstandingAid`, `mergeOccurrenceEvidence`, the `understanding_aid` key, and the `preferred_occurrence_id` option plumbing).
3. Revert `WordSenseExamplePoolService.php` (remove `pickQuestionIndexWithContext`; the original `pickQuestionIndex` remains).
4. Revert `SenseReview.vue` (remove the collapsible block, computed properties, data field, and `related_collocations` chips).
5. Revert `WordSense.php` (remove from `$fillable` and `casts()`).
6. Delete `SenseReviewUnderstandingAidTest.php` and `SenseReviewContextualUnderstandingTest.php`.

Rollback does NOT affect FSRS, ReviewLog, ReviewCard, WordSenseOccurrence data, or source context — the feature is purely additive and read-only.
