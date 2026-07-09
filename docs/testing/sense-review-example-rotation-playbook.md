# Sense Review Example Rotation Playbook

> **Status**: Active.
> **Date**: 2026-07-09.
> **Task**: GM52-SenseReviewExampleRotationMemory-1000-8.
> **Use when**: Any change to `WordSenseExamplePoolService::pickQuestionIndex`, `SenseReviewCardSerializerService::serialize`, or the sense review example rotation logic.

---

## 1. Why example rotation exists

A single WordSense may have multiple real-source example sentences (from different chapters or different sentences in the same chapter). Without rotation, the review page would always show the first example, so the learner never sees the word in different contexts. Rotation cycles through the available examples across reviews so the learner builds sense-transfer flexibility.

---

## 2. Rotation strategy

**Linear sequence rotation** (not hash, not random):

```
index = (reviewCardId + fsrsReps + fsrsLapses) % total
```

- `reviewCardId` — per-card offset so different cards start at different examples (avoids all cards syncing to the same example on the same day).
- `fsrsReps` — increments on successful review (`good` / `easy`), moves to the next example in pool order (A → B → C → A ...). This is the "first A, second B, third C" contract.
- `fsrsLapses` — increments on failed review (`again`), shifts the starting offset so a failed review shows a different example next time.

**Why not hash?** The previous strategy used `crc32(seed) % total` which produced unpredictable indices. Incrementing reps did not guarantee moving to the next example — it could jump back to the same one. The linear strategy guarantees sequential progression.

**Why not random?** Random would make the same card show different examples on page refresh within the same day, which is confusing. The linear strategy is deterministic for a given (cardId, reps, lapses) tuple.

**Why no persistence?** The rotation state is fully derived from existing FSRS fields (`fsrs_reps`, `fsrs_lapses`) — no new migration, no new table, no write path in serialize. The serialize service remains read-only.

---

## 3. Test command matrix

Run these commands before accepting any change to the rotation logic:

```
# Core rotation tests (must be all green)
php artisan test --filter=SenseReviewExampleRotationTest --stop-on-failure

# Multi-example binding tests
php artisan test --filter=SenseMultiExampleBinding --stop-on-failure

# Source context follows displayed occurrence
php artisan test --filter=SenseSourceContextDisplayedOccurrence --stop-on-failure

# Sense review full suite
php artisan test --filter=SenseReview --stop-on-failure

# WordSense + FSRS regression
php artisan test --filter=WordSense --stop-on-failure
php artisan test --filter=ReviewFsrsTest --stop-on-failure

# Frontend build
npm run development
```

**Expected counts** (as of 2026-07-09):
- `SenseReviewExampleRotationTest`: 14 passed (39 assertions)
- `SenseReview|WordSense|ReviewFsrsTest|SenseMultiExample|SenseSourceContext`: 364 passed, 1 skipped (1688 assertions)

---

## 4. MCP Chrome real acceptance

### Lightweight (when only backend logic changed)

1. Login to `http://127.0.0.1:8000`.
2. Navigate to `/reviews/senses`.
3. Confirm the page loads and shows due cards.
4. Pick a card with `occurrence_count >= 2` (visible in the "本词义已有 N 条来源例句" text).
5. Use browser `fetch('/reviews/senses', { headers: {...} })` to inspect the payload.
6. Record `example_sentence_en`, `displayed_occurrence_id`, `fsrs_reps`, `fsrs_lapses`.
7. Rate the card via `fetch('/reviews/senses/{id}/rate', { method:'POST', body: JSON.stringify({rating:'good'}) })`.
8. Re-serialize the card and confirm the example switched.

### Full (when UI or business logic changed)

1. Login.
2. Open `/reviews/senses`.
3. Find a card with `occurrence_count >= 2`.
4. Click "显示答案" (or press Space).
5. Rate with `good` (or press `2` / click the good button).
6. Continue to the next review or navigate back.
7. Confirm the same sense (if it appears again) shows a different example.
8. Check that `displayed_occurrence_id` changed.
9. Check that `example_source_status` may switch between `occurrence` and `card_fallback`.
10. Check keyboard shortcuts still work (Space, 1/2/3/4).
11. Check source context dialog still follows the displayed occurrence.

---

## 5. Database acceptance matrix

| Stage | word_senses | review_cards | review_logs | word_sense_occurrences | FSRS fields |
|---|---|---|---|---|---|
| Serialize (read-only) | unchanged | unchanged | unchanged | unchanged | unchanged |
| Rate `good` | unchanged | target card: reps++, due_at updated, stability/difficulty updated | +1 (source=sense_review) | unchanged | target card only |
| Rate `again` | unchanged | target card: lapses++, due_at updated | +1 | unchanged | target card only |
| Rate `hard` / `easy` | unchanged | target card only | +1 | unchanged | target card only |

**Critical**: Rotation itself (serialize) must NOT write anything. Only user rating writes a ReviewLog and updates the target ReviewCard.

---

## 6. Network acceptance

All browser requests must hit `127.0.0.1:8000` (or `localhost`). The following domains must NOT appear:

- `api.deepseek.com`
- `api.openai.com`
- `api.anthropic.com`
- `generativelanguage.googleapis.com`
- `api.x.ai`

---

## 7. Refuse conditions

Mark Refuse if any of these occur:

1. Rotation (serialize) writes a ReviewLog.
2. Rotation (serialize) changes any FSRS field (`fsrs_state`, `fsrs_due_at`, `fsrs_stability`, `fsrs_difficulty`, `fsrs_reps`, `fsrs_lapses`).
3. Rotation (serialize) creates a WordSense, ReviewCard, or WordSenseOccurrence.
4. Rating a card updates more than one ReviewCard.
5. Rating creates a legacy `target_type=word` ReviewCard.
6. With 3 examples, reps 0/1/2 does not produce 3 distinct examples.
7. With 1 example, reps/lapses changes cause the example to shift (there is nowhere to go).
8. Incrementing `fsrs_lapses` does not shift the example (when pool has >= 2).
9. Browser directly calls an external provider domain.
10. Backend smoke test is substituted for real browser acceptance.
11. `pickQuestionIndex` uses a hash or random strategy instead of linear sequence.
12. `serialize` writes to the database (it must remain read-only).

---

## 8. Accept / Refuse / Incomplete

- **Accept**: All tests green, MCP Chrome confirms example switches after rating, DB matrix matches, no external network, no Refuse condition triggered.
- **Refuse**: Any Refuse condition triggered, or tests fail, or DB matrix violated.
- **Incomplete**: Login fails, no card with `occurrence_count >= 2` exists, MCP Chrome cannot inspect Network, or browser automation fails before verification.

---

## 9. Allowed file boundary

- `app/Services/WordSenseExamplePoolService.php` — rotation logic.
- `app/Services/SenseReviewCardSerializerService.php` — serialize entry point.
- `tests/Feature/SenseReviewExampleRotationTest.php` — rotation tests.
- `docs/testing/sense-review-example-rotation-playbook.md` — this file.

**Do NOT modify** without architecture review:
- `app/Services/ReviewCardService.php` (FSRS rating path).
- `app/Services/SenseReviewService.php` (due card query).
- `app/Services/SenseSourceContextService.php` (source context logic).
- `app/Models/ReviewCard.php` (data model).
- `database/migrations/*` (schema).

---

## 10. Stop conditions

Stop and report if:
- Cannot login to the local instance.
- No card with `occurrence_count >= 2` is available for verification.
- MCP Chrome cannot inspect Network or DOM.
- Tests fail and cannot be safely fixed.
- `npm run development` fails and cannot be safely fixed.
- The change requires a new migration or table (violates "zero persistence" principle).
- The change requires modifying FSRS algorithm or rating logic.

---

## 11. File-to-test map

| File | Test |
|---|---|
| `WordSenseExamplePoolService::pickQuestionIndex` | `SenseReviewExampleRotationTest::test_linear_rotation_cycles_through_all_examples_with_reps` |
| `WordSenseExamplePoolService::pickQuestionIndex` | `SenseReviewExampleRotationTest::test_linear_rotation_wraps_around_after_pool_size` |
| `WordSenseExamplePoolService::pickQuestionIndex` | `SenseReviewExampleRotationTest::test_lapses_increment_shifts_example` |
| `WordSenseExamplePoolService::pickQuestionIndex` | `SenseReviewExampleRotationTest::test_single_example_stable_across_reps_and_lapses` |
| `SenseReviewCardSerializerService::serialize` | `SenseReviewExampleRotationTest::test_rotation_does_not_write_extra_review_log` |
| `SenseReviewCardSerializerService::serialize` | `SenseReviewExampleRotationTest::test_rotation_does_not_change_fsrs_fields` |
| `WordSenseExamplePoolService::exampleCandidates` | `SenseReviewExampleRotationTest::test_three_occurrences_do_not_always_show_first` |
| `WordSenseExamplePoolService::exampleCandidates` | `SenseMultiExampleBindingTest` (11 tests) |
| `SenseSourceContextService::sourceContextList` | `SenseSourceContextDisplayedOccurrenceTest` (14 tests) |

---

## 12. Change log

- **2026-07-09**: Changed `pickQuestionIndex` from `crc32(seed) % total` hash to `(reviewCardId + fsrsReps + fsrsLapses) % total` linear sequence. Added `fsrsLapses` parameter. Updated `serialize` to pass `fsrs_lapses`. Added 4 new tests covering linear rotation, wrap-around, lapses shift, and single-example stability. (commit: see git log)
