# ADR-0003: Reading Inline Sense Confirmation Persistence

## Status

Accepted for the inline-sense confirmation persistence layer. Reading inline review scoring remains frozen and is NOT implemented by this ADR.

## Date

2026-07-03

## Context

The previous round (`GLM-ReadingInlinePreview-First-1`, commit `d2184ba`) added a read-only `InlineSensePreviewPanel.vue` to the reading page. When the user clicks a token, the panel fetches `GET /senses/inline-preview` and shows confirmed `WordSense` candidates for the current lemma. Two buttons — "是这个意思" (is this meaning) and "不是这个意思" (not this meaning) — are shown per candidate, but in that round the buttons only mutate a local `userChoice` data object on the frontend. No choice is persisted. If the user reloads the page or closes the side panel, the choice is lost.

Product now wants the choice to be persisted so that:

1. The reading page can echo the user's prior choice when the same occurrence is clicked again.
2. Future statistics / dashboards can count how often a candidate sense was confirmed or rejected at a specific reading position.
3. A future "reading inline review" round can use these confirmations as a pre-stage input — but ONLY after another ADR / requirement freeze. This ADR explicitly forbids turning the confirmation into an FSRS rating.

The hard safety constraint (from `vibe-coding-collaboration-rules.md` §27.0) is: code safety and stability take priority over feature speed. The confirmation must not enter the review-rating chain. It must not write `ReviewLog`, must not change FSRS fields, must not modify `review_card` due/reps/state, must not auto-create `WordSense` or `ReviewCard`, and must not call AI.

## Decision

### Product Freeze

1. "是这个意思" (match) means: the user confirms that the current reading occurrence (chapter + sentence + surface + lemma) matches a specific already-confirmed `WordSense`.
2. "不是这个意思" (not_match) means: the user confirms that the current reading occurrence does NOT match a specific candidate `WordSense`.
3. The confirmation result is ONLY used for:
   - reading-page re-display (echoing the persisted choice),
   - future statistics,
   - as a pre-stage input for a FUTURE reading-inline-review round that is NOT implemented by this ADR.
4. The confirmation result is NOT a review rating.
5. The confirmation result MUST NOT write `ReviewLog`.
6. The confirmation result MUST NOT trigger FSRS scheduling.
7. The confirmation result MUST NOT modify `review_card.fsrs_*` fields, `due_at`, `reps`, `state`, `lapses`, `stability`, `difficulty`, `enabled`.
8. The confirmation result MUST NOT auto-create `WordSense`.
9. The confirmation result MUST NOT auto-create `ReviewCard`.
10. The confirmation result MUST NOT call AI (no external AI API request, no AI judgment of "rare meaning").
11. The confirmation is saved at the **occurrence level**: keyed by `user_id` + `language` + `chapter_id` + `sentence_index` + `surface` + `lemma` + `word_sense_id`. The same occurrence + same sense has at most ONE row; re-clicking a button updates the row instead of inserting a duplicate.
12. "不是这个意思" only negates THIS occurrence + THIS candidate. It does NOT globally reject the `WordSense`. The sense remains confirmed and remains available for other occurrences and for review.
13. If a future round wants to turn "是这个意思" into an FSRS rating, it MUST open a separate ADR / requirement freeze / dedicated task. This ADR does not authorize that.
14. The confirmation row records a `source = 'reading_inline_preview'` marker so that future code can distinguish it from any other future source.

### Why Not A Review Rating

- The user is reading, not in a review session. The cognitive intent is "this is / is not the meaning I am seeing here", not "I remembered / forgot this card".
- FSRS scheduling requires a deliberate rating (Again / Hard / Good / Easy) and depends on the card's prior state. Treating a confirmation as a rating would silently mutate scheduling without the user's informed consent.
- Mixing confirmation with rating would also violate the single-entry-point rule for `ReviewLog` writes (currently `SenseReviewController::rate` → `ReviewCardService::recordReview` → `FsrsSchedulingService::schedule`, plus `ReviewCardService::resetCard`). Adding a second `ReviewLog` entrypoint from the reading page would expand the FSRS write surface and is explicitly forbidden.

### Why Not Global Negation

- A user may reject sense A for occurrence 1 (because occurrence 1 is a different meaning) but still want sense A for occurrence 2. Global negation would silently destroy the sense or break review continuity. Therefore "不是这个意思" is occurrence-scoped only.

### Data Retention And Risk Boundary

- Confirmation rows are kept indefinitely (until a separate future cleanup decision). They are small (a handful of scalar fields) and do not carry sensitive content beyond what the user already typed.
- If a `WordSense` is deleted by the user, the corresponding confirmation rows become orphaned references. They will be filtered out by the preview payload (which joins against live confirmed senses), so they will not leak as stale candidates. A future cleanup task may remove orphans; this ADR does not require it.
- If a `Chapter` is deleted, the confirmation rows referencing it remain but will no longer be reachable via the reading page. Again, no active harm.

## Alternatives Considered

### Option A: Keep Front-End Only (Status Quo)

The choice stays in `InlineSensePreviewPanel.vue` local state and is lost on reload.

- Pros: zero DB write risk, zero migration, zero new endpoint.
- Cons: cannot echo prior choices; cannot support future statistics; cannot act as a pre-stage for reading-inline review. Fails the product goal of this round.
- Verdict: **Rejected** for this round. Does not meet the persistence requirement.

### Option B: Reuse `word_sense_occurrences` Table

Store the confirmation as a new status / column on the existing `WordSenseOccurrence` table.

- Pros: no new table; occurrence data is co-located with sense binding.
- Cons:
  - `word_sense_occurrences` already has its own lifecycle (`pending` / `bound` / `rejected` / `ignored`) tied to the sense-mapping AI import and the manual bind/ignore/reject flows. Adding a fifth "match / not_match from reading preview" status would mix two different concepts (AI sense-mapping pipeline vs. reading-page user confirmation) and would require altering the existing occurrence service, status guards, and N+1 queries.
  - `WordSenseOccurrence` rows are created by the sense-mapping import flow; the reading-preview click happens BEFORE any occurrence row may exist for this (chapter, sentence, surface, lemma) tuple. Reusing the table would force the preview flow to either create an occurrence row (which is a write into an AI-pipeline-owned table) or look up an existing one (which may not exist).
  - Altering `word_sense_occurrences` schema or status semantics risks the existing `WordSenseExamplePoolService`, `SenseSourceContextService`, and `WordSenseKnownSenseService` behavior, all of which depend on the current occurrence status semantics.
  - This would couple the read-only preview flow to the AI-pipeline-owned occurrence flow.
- Verdict: **Rejected**. Too much coupling to a table owned by another lifecycle; high risk of polluting source-context / example-pool / review-card logic.

### Option C: New Lightweight Additive-Only Table

Create a new table `reading_inline_sense_confirmations` with a new model `ReadingInlineSenseConfirmation`. Additive-only migration: `Schema::create` only, no `ALTER` of any existing table, no `DROP`, no `TRUNCATE`, no `DELETE`.

- Pros:
  - Clean separation: the confirmation lifecycle is independent of the sense-mapping occurrence lifecycle and of FSRS / ReviewLog.
  - No risk of polluting `word_sense_occurrences` status semantics, source-context, example-pool, or review-card logic.
  - Easy to reason about: the table only ever stores user-initiated match / not_match choices from the reading preview.
  - Additive-only migration is safe to roll back (`down()` only drops the new table).
  - The unique constraint `(user_id, language, chapter_id, sentence_index, surface, lemma, word_sense_id)` makes "same occurrence + same sense → update, not insert" enforceable at the DB level.
- Cons:
  - Adds one new table and one new model. Acceptable cost for clean isolation.
  - Future reading-inline-review round will still need its own ADR before touching FSRS.
- Verdict: **Selected**. This is the safest, clearest, lowest-coupling option.

## Chosen Option: C

This round implements Option C:

- New migration: `2026_07_03_000001_create_reading_inline_sense_confirmations_table.php` (additive-only, `Schema::create` only).
- New model: `App\Models\ReadingInlineSenseConfirmation`.
- New service: `App\Services\ReadingInlineSenseConfirmationService` (the only writer; does not call ReviewLog / FSRS / AI).
- New endpoint: `POST /senses/inline-confirmation` (the only write entrypoint; enforces user / language / chapter / sense ownership and `confirmed` status).
- Extended read-only endpoint: `GET /senses/inline-preview` returns `persisted_choice` / `confirmation_id` / `confirmed_at` per candidate (read-only).
- Frontend: `InlineSensePreviewPanel.vue` buttons now call `POST /senses/inline-confirmation` and echo the persisted choice on reload.
- Tests: new `ReadingInlineSenseConfirmationTest` (security guardrails) + extended `InlineSensePreviewTest` (echo) + extended `InlineSensePreviewUiGuardTest` (UI guard).

## Consequences

- The reading page can now persist and echo the user's match / not_match choice per occurrence + candidate.
- The FSRS / ReviewLog / WordSense / ReviewCard write surfaces are unchanged.
- A future round that wants to turn this confirmation into an FSRS rating MUST open a new ADR and MUST NOT reuse the `POST /senses/inline-confirmation` endpoint for rating. The endpoint name and `source = 'reading_inline_preview'` marker exist precisely to keep the boundary clear.
- The `word_sense_occurrences` table and its services remain untouched.
- The legacy word-card compat layer remains untouched.

## Validation

This ADR is validated by:

1. `ReadingInlineSenseConfirmationTest` — proves the POST endpoint:
   - saves `match` / `not_match`,
   - updates instead of duplicating on repeat,
   - rejects invalid `choice`,
   - rejects non-confirmed `WordSense`,
   - rejects `WordSense` / `Chapter` not owned by the current user / language,
   - does NOT write `ReviewLog`,
   - does NOT change any `ReviewCard` FSRS field,
   - does NOT create `WordSense`,
   - does NOT create `ReviewCard`,
   - does NOT call AI,
   - isolates cross-user and cross-language.
2. Extended `InlineSensePreviewTest` — proves the GET preview endpoint echoes `persisted_choice` / `confirmation_id` / `confirmed_at` for the current user / language only, and does not leak other users' confirmations.
3. Extended `InlineSensePreviewUiGuardTest` — proves the frontend source code contains the required safety copy ("已保存：是这个意思" / "已保存：不是这个意思" / "这不是复习评分" / "不会写入复习记录" / "不会改变 FSRS") and does NOT contain rating copy (Good / Easy / Hard / Again) or rating-route references.
4. Regression suite (`ReviewFsrsTest`, `FsrsSchedulingServiceTest`, `WordSense*`, `FinishedReadingSafetyTest`, `LegacyEntryUiGuardTest`) must remain green.
5. MCP Chrome real click acceptance on the reading page: confirm match → reload → echo → switch to not_match → echo updates; network shows only `POST /senses/inline-confirmation` and `GET /senses/inline-preview`; no `ReviewLog` / FSRS / AI requests.

## Notes

- This ADR does NOT authorize reading-inline review scoring.
- This ADR does NOT authorize AI judgment of "rare meaning".
- This ADR does NOT authorize per-occurrence lemma persistence into `EncounteredWord`.
- This ADR does NOT delete or alter any existing table.
- The `source` column is reserved for future source types but this round only writes `reading_inline_preview`.

## Usage Surface Layer (added 2026-07-03 by GLM-ReadingInlineConfirmationUsageSurface-AndMorphology-1000-1)

This section freezes the **usage surface** rules for already-persisted inline confirmations. It does NOT change the persistence contract above; it only clarifies how the persisted rows may be READ and DISPLAYED.

1. A reading inline confirmation is **reading evidence**, not a review rating.
2. Confirmation rows MAY be read for:
   - reading-page display (echoing the persisted choice),
   - per-candidate summary statistics (match_count / not_match_count / last_choice / last_confirmed_at),
   - future statistics dashboards,
   - as a pre-stage input for a FUTURE reading-inline-review round that is NOT implemented by this ADR.
3. Confirmation rows MUST NOT be used to:
   - write `ReviewLog`,
   - change any `ReviewCard` FSRS field (state / reps / due_at / stability / difficulty / lapses / enabled),
   - auto-create `WordSense` or `ReviewCard`,
   - call AI,
   - globally negate a `WordSense` (a `not_match` only negates the current occurrence + current candidate).
4. The usage-surface payload is **read-only**. It MUST NOT perform any DB write.
5. The usage-surface payload is **isolated by user + language**. It MUST NOT leak other users' confirmations or other languages' confirmations.
6. New copy shown to the user SHOULD prefer the friendlier phrase "复习进度" over the technical term "FSRS" where possible; "FSRS" may be kept as a parenthetical clarification. This is a copy guideline, not a code contract.
7. The candidate card MAY display:
   - "这个词义在阅读中确认过 N 次" (match_count),
   - "这个词义在阅读中排除过 N 次" (not_match_count),
   - "最近一次：是这个意思" / "最近一次：不是这个意思" (last_choice),
   - the current occurrence's persisted_choice (already implemented in the previous round).
8. If a future round wants to turn a confirmation into an FSRS rating, it MUST open a separate ADR / requirement freeze / dedicated task. This usage-surface layer does NOT authorize that.
