# D-01 Legacy Word Card Inventory

Date: 2026-08-12
Milestone: D-01
Status: Accepted under current goal authorization

## 1. Entry Gate

### Objective

Inventory every production-reachable use of `ReviewCard.target_type=word`: creation and mutation owners, routes and UI callers, statistics, rating, history dependencies, and current sense-only exclusion barriers. The output must be directly usable by D-02 through D-04.

### Explicitly out of scope

- no manual application-data query or mutation for the inventory; focused regression tests may use only the protected testing database through its existing lease;
- no migration, backfill, delete, disable, or lifecycle change;
- no product-code, route, test, schema, or UI change;
- no retirement decision based only on a name or old document;
- no guessed WordSense mapping and no one-to-many card copy.

### Owner, seam, and allowlist

- Canonical discriminator: `ReviewCard::TARGET_WORD` in `app/Models/ReviewCard.php`.
- Canonical legacy card owner: `app/Services/ReviewCardService.php`.
- Production callers: `VocabularyService`, `InitializeReviewCards`, `FsrsDoctor`, the legacy Review controller/API, and the legacy Goal read model.
- Allowed files for D-01: this audit and the Goal roadmap checkpoint only.
- Forbidden files: all product code, tests, routes, migrations, and the existing unstaged frontend user assets.

### Data and compatibility boundaries

- Every later classification must remain scoped by the card's `user_id`, `language_id`, `target_type`, and `target_id`.
- `target_id` for a word card points to `EncounteredWord.id`; it is not a WordSense ID.
- Historical `ReviewLog` identity is currently obtained through `review_logs.review_card_id -> review_cards`; `review_logs` does not store `target_type` or a WordSense identity.
- ADR-0021 keeps explicit legacy stage transitions compatible for now. D-01 records that boundary; it does not remove it.
- ADR-0010 makes the formal daily queue and lifecycle/manage surfaces sense-only and makes lifecycle events append-only. Those barriers remain regression anchors.

### Validation

- complete production search for `TARGET_WORD`, `target_type`, `ensureWordCard`, and `disableWordCard`;
- route/controller/UI caller tracing for every live writer and reader;
- schema search for every table carrying `review_card_id`;
- focused existing tests for writer compatibility, queue exclusion, history deletion behavior, management exclusion, and stats exclusion;
- final scope/diff review with Blocker/Required findings at zero.

No new ADR is required: this audit expands the already accepted Phase D roadmap, ADR-0010, and ADR-0021 without changing their semantics.

## 2. Terminology exclusions

The following `target_type=word` strings are different namespaces and must not enter the ReviewCard migration classifier:

| Namespace | Current owner | Why excluded |
|---|---|---|
| AI Reading target kind | `AiReadingAssistV2Service` and `ReadingSenseVerificationPolicy.js` | Describes a reading target/occurrence kind, not a ReviewCard row. |
| Example sentence target | `ExampleSentence` through `VocabularyService` | Polymorphic example ownership; independent of review-card identity. |
| Phrase target | legacy vocabulary/example paths | Phrase SRS/example semantics; Phase D does not authorize phrase FSRS migration. |

The D-02 classifier must start from actual `review_cards.target_type='word'` rows, never from an unqualified repository-wide string match.

## 3. Production creation and mutation inventory

| Surface | Actual behavior | Reachability | D-02/D-04 disposition |
|---|---|---|---|
| `ReviewCardService::ensureWordCard()` | For an owned `EncounteredWord` with `stage < 0`, `firstOrCreate`s one enabled, due-now `new` word card. | Central production writer. | Freeze as legacy input owner; later fence new creation only after migration/compatibility design proves safe. |
| `ReviewCardService::disableWordCard()` | Sets matching word cards `fsrs_enabled=false`; leaves row and logs. | Central production mutator. | Preserve until legacy stage/ignore callers are classified; do not treat disabled as migrated. |
| `ReviewCardService::initializeExistingWords()` | Scans negative-stage words without a word card and calls `ensureWordCard()`. | Called by `reviews:initialize-cards` unless `--dry-run`. | Writer to fence or retire after D-02; dry-run count may remain diagnostic. |
| `ReviewCardService::recordReviewWithLog()` | Locks any enabled active card and accepts both word and sense targets through `isReviewableTarget()`, then schedules FSRS and writes `ReviewLog`. | Called by legacy `/reviews/rate`; production-reachable for an owned negative-stage word card. | Must be fenced to sense-only or an explicit read-only legacy policy before migrated cards are exposed; preserve existing history. |
| `VocabularyService::updateWord()` | Only an explicit `stage` request invokes `setStage`; negative stage ensures a word card, non-negative disables it, and the existing translation bridge may run. Content-only edits do not touch cards. | POST `/vocabulary/word/update` from legacy vocabulary/reader compatibility UI. | Retain ADR-0021 behavior during D-02; D-04/D-07 must decide the final compatibility fence. |
| `VocabularyService::importFromCsv()` | Saves each imported word, applies an optional stage, then calls `ensureWordCard()`; the writer creates only when the final stage is negative. | POST `/vocabulary/import-from-csv`. | Inventory as a legacy writer; do not let post-migration imports silently recreate retired word cards. |
| `VocabularyService::ignoreWord()` / `softDeleteWord()` / `cleanupInvalidTokens()` | Marks words ignored and disables their word card. | Batch-ignore, batch-delete, and cleanup command paths. | Compatibility mutation; later preserve reader color semantics without claiming migration completion. |
| `VocabularyService::hardDeleteWordsByIds()` | Rejects linked non-rejected senses, removes their sense cards, explicitly deletes every matching legacy word card's `ReviewLog` rows, deletes the word card, then deletes the EncounteredWord. | Single, selected-batch, and filter-based hard-delete HTTP routes. | Critical history hazard. D-03 must design a preservation/write fence before D-04; current behavior cannot be used on a card whose history is being preserved. |
| `ChapterService::finishChapter()` | Finish can move `stage=2` words to `0` and can increment listed word stages through `EncounteredWord::setStage()` without calling `disableWordCard()`. A word card can therefore remain enabled after its target stops being a negative-stage learning word. | POST `/chapters/finish`: both the no-session compatibility branch and a successful Phase B session settlement commit reach this same service method. | D-02 must report card/target stage disagreement explicitly. D-04 must fence or reconcile this shared writer before cutover; stage alone cannot prove that a surviving card is safe to migrate. |
| `UserService::deleteUserLanguageData()` | Deletes all scoped `EncounteredWord` rows but does not delete or disable matching ReviewCards and does not preserve their target join. Legacy cards and logs can become orphaned. | DELETE `/users/delete-language-data/{language}`. | Critical orphan/history hazard. D-02 must classify a missing target as read-only legacy; D-03/D-04 must protect card/log identity before any language-data lifecycle change. |
| `FsrsDoctor --fix` | Finds negative-stage words missing a word card and calls `ensureWordCard()`; without `--fix` it is read-only. | Manual CLI. `scripts/windows/linguacafe-doctor.bat` invokes it without `--fix`. | Keep diagnostic mode; fence the fix mode after the migration cutover. |

There is no second production `ReviewCard::firstOrCreate()` path for `target_type=word`. The eight `ReviewCard::TARGET_WORD` references under `app/` are confined to `ReviewCardService` (five), `VocabularyService` (one), `Goal` (one), and `FsrsDoctor` (one).

## 4. Reachable routes and UI callers

### Legacy vocabulary

The SPA page `/vocabulary/search` remains reachable from My → Advanced. Its relevant authenticated routes are:

- POST `/vocabulary/word/update` — explicit stage can create/disable a word card;
- POST `/vocabulary/word/delete` — hard delete;
- POST `/vocabulary/words/batch-ignore` — disable;
- POST `/vocabulary/words/batch-delete` — soft-delete/disable;
- POST `/vocabulary/words/batch-hard-delete` — hard delete selected IDs;
- POST `/vocabulary/words/bulk-hard-delete-count` — read-only count;
- POST `/vocabulary/words/bulk-hard-delete` — hard delete filtered words;
- POST `/vocabulary/import-from-csv` — can create word cards for imported negative stages.

### Chapter and user-data lifecycle

- POST `/chapters/finish` can change an EncounteredWord stage without synchronizing the legacy word card, leaving card/stage disagreement. This applies both to the controller's no-session fallback and to the Phase B session-aware commit, which delegates through `ReadingFinishSettlementService` to the same `ChapterService::finishChapter()` method.
- DELETE `/users/delete-language-data/{language}` deletes the scoped EncounteredWord targets without deleting the legacy cards or their logs, leaving orphaned `target_id` values.

### Legacy review page and API

- GET `/review/{practiceMode?}/{bookId?}/{chapterId?}` still mounts `Review.vue`.
- `Review.vue` uses `ReviewApiClient.loadLegacyQueue()` → POST `/reviews` and `rateLegacyCard()` → POST `/reviews/rate`.
- POST `/reviews` is now sense-only through `ReviewService -> SenseReviewService`; book/chapter-scoped mode returns an empty queue.
- POST `/reviews/rate` calls generic `ReviewCardService::recordReview()` with no `target_type=sense` controller guard. An authenticated caller who knows an owned, enabled, negative-stage word-card ID can rate it even though POST `/reviews` will never return it.
- The controller passes `source='sense_review'` for this generic endpoint even when the actual target is a word card. Historical `ReviewLog.source='sense_review'` is therefore not evidence of a historical WordSense identity.
- `ReviewFsrsTest::test_each_rating_updates_card_and_writes_log` currently protects all four ratings on word cards through this endpoint. This is an explicit compatibility writer, not an orphan inferred only from source.

### CLI

- `reviews:initialize-cards {--user_id=} {--language=} {--dry-run}` writes word cards unless `--dry-run` is present.
- `fsrs:doctor {--fix}` reports missing word and sense cards; `--fix` writes both kinds. The shipped Windows doctor script does not pass `--fix`.

## 5. Statistics and read models

| Surface | Current behavior | Classification |
|---|---|---|
| `Goal::getTodaysReviewGoalQuantity()` | Counts enabled, due, negative-stage word cards by joining `review_cards.target_id` to `encountered_words.id`. | Live legacy statistic; replace or retire in D-04/D-07, not a historical-only reader. |
| `Goal::getTodaysQuantity()` | For review goals, writes the computed quantity back to `goals` and `goal_achievements`, creating today's achievement on first read. | Live indirect write-on-read chain. |
| POST `/goals/get` → `GoalService` | Invokes the model calculation. | Production-reachable. |
| `Home/Goals.vue` | Calls POST `/goals/get`; `Home.vue` still mounts `<goals>` and `<calendar>`. | User-visible caller remains live, separate from the new zero-write Home daily summary. |
| `FsrsDoctor` and `reviews:initialize-cards --dry-run` | Count missing word cards. | Diagnostic compatibility readers; not user progress authority. |

All modern review analytics and management statistics inspected below are sense-only; they must not be conflated with the legacy Goal statistic.

## 6. Historical and referential dependencies

| Table / relationship | Schema behavior | Current writer barrier | D-02/D-03 requirement |
|---|---|---|---|
| `review_logs.review_card_id` | Indexed scalar, no FK and no stored target type/sense ID. Deleting the card removes the only target-identity join unless logs are separately preserved. | Legacy `/reviews/rate` can create word-card logs labeled `source=sense_review`; hard delete explicitly deletes them. | Count and classify every log per card, including source, rating, undo fields, and before/after snapshots. Never treat `source` as target identity or relabel a log as a sense review without proved identity. |
| `review_card_state_events.review_card_id` | Indexed scalar, no FK; ADR-0010 declares rows append-only. | Current lifecycle command rejects non-sense cards, so expected legacy count is zero but historical/inconsistent rows must be audited. | Report count and preserve any row found; absence must be data evidence, not source inference. |
| `reschedule_snapshot_items.review_card_id` | FK with cascade delete. | Current preview/confirm/undo paths exclude word cards. | Audit historical rows before any card mutation; card deletion could cascade them. |
| `operations.review_card_id` | Nullable FK with `nullOnDelete`; `review_log_id` is also nullable/unique with `nullOnDelete`. | Current mobile/manual operation paths are sense-only. | Audit operations matching either the legacy card ID or any captured ReviewLog ID, deduplicate by operation ID, and preserve them; deletion can erase the card pointer even when the log pointer remains. |
| `reading_session_interactions.review_card_id` and `reading_session_card_settlements.review_card_id` | Indexed/scalar references without a ReviewCard FK in their migration. | Current reading explicit/passive paths are sense-only. | Audit for legacy rows and preserve any found; do not assume impossible history from current code alone. |
| `word_sense_occurrences.review_card_id` | Nullable indexed scalar without a ReviewCard FK. | Current occurrence binding resolves sense cards. | Audit as possible mapping evidence, but validate its user/language/WordSense ownership before use. |
| `word_senses.encountered_word_id` | Nullable, not unique; multiple senses may refer to one EncounteredWord. | Vocabulary bridge may create an AI-suggested sense; manual enrollment creates confirmed sense/card without a word card. | Strong candidate link but never sufficient for uniqueness by itself; status and all competing candidates must be classified. |

The historical language migration `2026_06_17_000003_add_language_to_review_tables.php` explicitly joined word cards to EncounteredWord. It is provenance evidence only; it is not a safe current migration mechanism.

## 7. Current sense-only barriers and regression anchors

These are not legacy consumers. They are barriers that already exclude word cards and must remain green while Phase D changes compatibility paths:

- `ReviewCard::scopeSenseReviewEligible`, `SenseReviewQueryService`, `SenseReviewService`, `/reviews/senses`, interval preview, rating, undo, and session actions;
- lifecycle policy/commands/events and all ReviewCard manage query/access/mutation/detail/log/export endpoints;
- `ReviewStatsService`, `StatisticsService`, analytics, daily report, seven-day trend, and thirty-day calendar;
- Custom Study and Special Study queries/session order;
- FSRS reschedule preview/confirm/snapshot/undo and optimization settings;
- mobile review/package/sync/operation ledger;
- portable data, learned-sense export, tags, markers, leech governance, knowledge hygiene;
- Home daily summary/progress, reader FSRS familiarity, source context, and AI/preview/lookup flows.

Named executable anchors include:

- `ReviewFsrsTest`: word card excluded from queue, old card retained, and the misleadingly named mixed-card test now asserts sense-only output;
- `ReviewCardManageTest`, `ReviewCardManageDangerContractTest`, `ReviewCardManageDeepLinkTest`, `ReviewCardInfoTest`: legacy word cards rejected or excluded from management;
- `ReviewCardLifecycleQueueTest::test_word_card_not_eligible` and `ReviewCardLifecycleCommandTest::test_word_card_rejected`;
- `ReviewStatsTest::test_legacy_word_card_excluded_from_stats` plus analytics/report/calendar legacy exclusions;
- Custom Study legacy-word exclusions;
- FSRS reschedule and optimization legacy-word exclusions;
- `ReaderFsrsHighlightTest::test_legacy_word_card_does_not_affect_fsrs_familiarity`;
- `EncounteredWordLearningEnrollmentTest`: content-only edits are zero-card-side-effect, explicit stage requests retain legacy behavior, confirmed-sense enrollment creates no word card;
- `VocabularyHardDeleteTest`: current destructive compatibility behavior;
- `WordSenseDestroyRestoreTest::test_sense_delete_does_not_affect_legacy_word_card`.

## 8. Executable classification contract for D-02

D-02 must produce a stable, repeatable, read-only list ordered by `user_id`, `language_id`, and word-card ID. Each row must include at least:

- word-card ID and complete current card state;
- owned EncounteredWord ID, stage, surface, base/study lemma fields, and whether the target is missing;
- all same-user/same-language WordSenses linked by `encountered_word_id`;
- all relevant occurrences and their bound WordSense IDs/status/source;
- existing sense card IDs for every candidate;
- counts and IDs for every history/dependency table in §6;
- one classification and machine-readable reason code.

The production interface is the read-only Artisan command `reviews:classify-legacy-word-cards` with optional `--user_id` and `--language` filters. A supplied user ID must be a positive base-10 integer; a supplied language must be a non-empty lower-case identifier matching `[a-z][a-z0-9_-]*`. Invalid filters return non-zero and no JSON payload; an omitted filter means all scopes.

The JSON object key order is fixed as `schema_version`, `filters`, `counts`, `cards`. `schema_version` is exactly `legacy_word_card_classification_v1`. `filters` uses `user_id`, `language`; `counts` uses `total`, `unique_mapping`, `needs_user_confirmation`, `unmapped_read_only`. Each card row uses `review_card`, `encountered_word`, `target`, `candidates`, `occurrences`, `dependencies`, `classification`, `selected_word_sense_id`, `primary_reason_code`, `reason_codes` in that order.

Nested structures are also fixed:

- `target`: `exists`, `in_scope`, `stage_is_learning`, `card_enabled`, `reason_codes`;
- candidate entry: `word_sense`, `evidence_sources`, `sense_review_card_ids`, `in_scope`, `confirmed`, `reason_codes`;
- occurrence entry: `occurrence`, `word_sense`, `in_scope`, `resolved`, `reason_codes`;
- `dependencies`, in order: `review_logs`, `review_card_state_events`, `reschedule_snapshot_items`, `operations`, `reading_session_interactions`, `reading_session_card_settlements`, `word_sense_occurrences`;
- each dependency entry: `count`, `ids`, `rows`; `ids` are the numeric database row primary keys, including `operations.id`, while complete operation rows still expose the public UUID `operation_id`.

Persisted database rows retain all columns with their keys sorted lexically; raw JSON/date/decimal database scalars are not reinterpreted. Candidate entries are ordered by `word_sense.id`; occurrence entries and dependency rows by numeric row `id`; evidence-source, sense-card-ID, dependency-ID, and per-entry reason-code arrays are sorted. The card-level `reason_codes` array starts with the primary reason and then contains applicable secondary codes in lexical order. The command uses `JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR` and writes exactly one literal LF (`"\n"`) after the JSON object, independent of the host platform. Two command executions against unchanged data must have identical stdout bytes.

### Exact candidate and evidence rules

A `direct candidate` is a WordSense reached by at least one of these persisted ID links; no text field participates:

1. `word_senses.encountered_word_id = review_cards.target_id`; or
2. a `word_sense_occurrences` row whose `review_card_id` is the legacy word-card ID and whose non-null `word_sense_id` points to that WordSense.

A candidate is `in scope` only when its `user_id` and canonical `language_id` equal the card scope; the existing `language` compatibility column is still reported. A candidate is `confirmed` only when `word_senses.status=confirmed`. Rejected, AI-suggested, out-of-scope, and otherwise invalid direct candidates remain visible in the report but never qualify as a unique migration target. EncounteredWord, WordSense, and occurrence rows are fetched first by persisted ID/link without a user/language pre-filter; scope is checked only afterward so a mismatch cannot be disguised as a missing row. An occurrence is `resolved direct evidence` only when its `word_sense_id` exists, is in scope, and the occurrence itself has the same user/language scope. Lemma, POS, surface, translation, source string, ReviewLog source, and approximate text equality never create or strengthen a candidate.

The classifier applies the following ordered rules; the first matching rule supplies the classification and primary machine-readable reason code:

| Priority | Condition | Classification | Primary reason code |
|---|---|---|---|
| 1 | EncounteredWord target is missing | `unmapped_read_only` | `target_missing` |
| 2 | EncounteredWord user/language differs from the card | `unmapped_read_only` | `target_scope_mismatch` |
| 3 | An occurrence attached to the legacy card points to a missing WordSense, the occurrence row has cross-user/language scope, or its referenced WordSense has cross-user/language scope | `unmapped_read_only` | `occurrence_binding_invalid_scope_or_target` |
| 4 | No direct candidate exists | `unmapped_read_only` | `no_direct_candidate` |
| 5 | Direct candidates exist but none is confirmed and in scope | `unmapped_read_only` | `no_confirmed_direct_candidate` |
| 6 | An occurrence attached to the legacy card is unresolved (`word_sense_id` null) while at least one confirmed in-scope direct candidate exists | `needs_user_confirmation` | `unresolved_occurrence_binding` |
| 7 | More than one non-rejected in-scope direct candidate exists, including a confirmed/AI-suggested mix | `needs_user_confirmation` | `competing_direct_candidates` |
| 8 | Resolved occurrence evidence names a different candidate from the sole confirmed EncounteredWord-linked candidate, or resolved occurrences name more than one candidate | `needs_user_confirmation` | `conflicting_direct_bindings` |
| 9 | Exactly one in-scope confirmed direct candidate remains, every resolved occurrence attached to the card either names that candidate or none exists, and there is no other non-rejected in-scope direct candidate | `unique_mapping` | `unique_confirmed_direct_candidate` |

Secondary reason codes are also fixed. Card/target annotations use `target_stage_not_learning` and `card_disabled`. Rejected candidate entries use only `candidate_user_mismatch`, `candidate_language_mismatch`, `candidate_status_ai_suggested`, `candidate_status_rejected`, and `candidate_competes_with_selected`. Occurrence entries use only `occurrence_user_mismatch`, `occurrence_language_mismatch`, `occurrence_word_sense_missing`, `occurrence_unresolved`, and `occurrence_conflicts_with_selected`. Emit only applicable codes, sorted lexically after the primary code. Candidate IDs, occurrence IDs, and dependency IDs are sorted numerically. This fixed precedence plus the stable row order makes unchanged input byte-for-byte repeatable after canonical JSON encoding.

Required invariants:

- no writes and no card/log/FSRS mutation;
- rerunning against unchanged data returns the same rows, order, classes, and reasons;
- never copy one word card or its history to multiple senses;
- never infer a sense from lemma/POS/translation similarity alone;
- every rejected candidate records why it was rejected;
- any row with history that cannot be safely mapped remains `unmapped_read_only` or `needs_user_confirmation`, never silently dropped.
- a `unique_mapping` describes only the current card target candidate; it does not relabel old ReviewLogs as historical sense reviews. D-03 owns that preservation decision.
- output must include complete ReviewCard state; target stage/surface/lemma/study-base fields; occurrence status/source; dependency counts and IDs; and full ReviewLog source/rating/undo/before/after snapshot fields. A misleading `ReviewLog.source=sense_review` remains only history metadata.

### D-02 executable acceptance matrix

Focused tests must prove:

- one persisted fixture for each ordered priority 1–9, including that a higher-priority condition wins when lower-priority evidence is also present; these predicates exhaust the normalized persisted evidence shape;
- every fixed candidate/occurrence/card secondary reason code and lexical secondary ordering;
- rejected and AI-suggested candidates remain visible, and lemma/POS/translation equality alone creates no candidate;
- a misleading `ReviewLog.source=sense_review` remains unchanged with its complete before/after and undo metadata;
- dependencies are captured with full rows/counts/IDs, with `operations` found both by `review_card_id` and by captured `review_log_id`, without duplicates;
- positive `--user_id` and `--language` filters narrow output; invalid filters return non-zero and emit no JSON object to stdout;
- two independent command executions against unchanged data have byte-identical stdout with exactly one literal trailing LF;
- a before/after database fingerprint over every queried table is unchanged.

## 9. Required follow-up fences

### D-03 before any migration apply

- Define a testing-only snapshot/backup and a traceable mapping ledger.
- Define how legacy card identity and ReviewLog identity remain inspectable after migration without fabricating a historical WordSense.
- Prove recovery for every changed card and all §6 dependencies.
- Block or redesign legacy hard delete so it cannot erase history under migration protection.

### D-04 cutover

- Apply only under the dedicated testing DB lease.
- Reuse or create at most one sense card for a proven unique mapping; never fan one word card out to multiple cards.
- Preserve ambiguous/unmapped cards as legacy/read-only.
- Fence `/reviews/rate`, `reviews:initialize-cards`, `fsrs:doctor --fix`, CSV negative-stage import, and explicit-stage recreation so migration cannot be undone by a live writer.
- Fence legacy `/chapters/finish` stage changes and `/users/delete-language-data/{language}` so they cannot leave a migrated card inconsistent or erase its target identity.
- Replace or retire the legacy Goal statistic without changing the new Home daily summary authority.
- Keep all §7 sense-only barriers green and prove formal queues contain only sense cards.

### D-07 retirement decision

- Legacy `/review` and `/vocabulary/search` UI/routes may only be hidden/retained/deleted after their current callers and compatibility obligations above have been resolved.
- Read-only legacy history remains available for unmapped records even if ordinary navigation is hidden.

## 10. Search coverage and result

Read-only coverage included:

- all `ReviewCard::TARGET_WORD` references under `app/`;
- all `ensureWordCard()` and `disableWordCard()` production callers;
- production EncounteredWord stage/delete paths that can desynchronize or orphan an existing word card;
- all production `target_type` references under `app/` and `resources/js/`;
- authenticated review, vocabulary, goal, management, and sense routes;
- every migration occurrence of `review_card_id`;
- all test methods whose names contain legacy-word, word-card, or target-type-word semantics.

Result: the inventory is actionable for D-02. No data, product code, route, test, schema, migration, or external dirty user asset was modified.
