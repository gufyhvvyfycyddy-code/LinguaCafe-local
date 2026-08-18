# LinguaCafe Product Rebaseline — English Reading First — 2026-08-18

> Status: Current product authority / development not entered by this document
>
> Date: 2026-08-18
>
> Scope: forward product direction after G-05. This document preserves completed historical milestones while superseding older forward-facing product-surface classifications where they conflict with the decisions below.

## 1. Historical boundary and current authority

G-01 through G-05 remain completed historical milestones.

G-04 correctly classified the then-current advanced capability families from the evidence available at that time. G-05 correctly closed with zero production deletions because G-04 had authorized zero `DELETE_CANDIDATE` items. The G-05 commit and reports remain valid historical evidence and must not be rewritten to imply that the milestone failed.

The 2026-08-18 product decisions supersede the **forward product classification** that treated all 11 advanced capability families as user features that must continue to be retained in their old form. This supersession does not itself delete production code, persisted data, compatibility contracts, shared services, audit history, or recovery owners. Any physical deletion still requires a later caller/data/safety/compatibility proof.

The former G-06 definition, which only moved video/subtitles/non-English/JMDict/fonts/etymology/generic media out of the main line, is too narrow for the current product rebase and is replaced by the Phase G roadmap in the Goal ledger.

## 2. Product scope and position

LinguaCafe is an **English-only, reading-first, Sense-first** learning product.

The ordinary user loop is:

1. read real English material;
2. mark an unfamiliar word or phrase when needed;
3. understand what the expression means in this exact context;
4. bind learning to a concrete `WordSense`;
5. let later natural reading reinforce that Sense when the evidence is reliable;
6. use formal Sense Review when scheduled review is needed;
7. inspect understandable learning history, memory state, and future review pressure.

`WordSense` remains the learning content identity. Sense ReviewCard / ReviewLog / FSRS remain the formal scheduling and rating foundation. Reading context and `WordSenseOccurrence` remain evidence for where a Sense was encountered.

English-only is a product boundary. Existing multi-language columns, isolation checks, compatibility data, or shared lower services are not automatically deleted. User-facing language switching, Japanese/JMDict entry points, and other non-English mainline behavior must be retired through the later English-only implementation slice with explicit dependency proof.

## 3. AI Reading Assist contract

The first product form remains a manual external-AI file/text loop:

`LinguaCafe exports prompt/package → user gives it to their own AI → AI returns strict data → user pastes it back → LinguaCafe parses and validates it`.

LinguaCafe does not automatically control ChatGPT/DeepSeek, does not automatically send user text to an external AI provider, and does not add a second paid AI call merely to judge duplicates.

### 3.1 Export content

The AI source package must contain:

- the full article sentence set needed for complete translation;
- every word/phrase the user explicitly marked as unfamiliar;
- every article occurrence that needs comparison with already learned WordSense candidates;
- for those candidates, stable WordSense IDs plus the existing Chinese meaning, English meaning, and relevant POS/context evidence.

Batching targets must not remove full-article translation coverage.

### 3.2 Required return content

Paste-back must be machine-recognizable and include:

- complete sentence-by-sentence Chinese translation for the translation-owning package;
- contextual results for all required word/phrase targets;
- word results classified as `matched_existing`, `new_sense`, or `ambiguous`;
- the existing WordSense ID when `matched_existing` is chosen;
- a proposed new Sense only when the contextual meaning is genuinely different.

The user must not need to manually retype WordSense IDs after paste-back.

### 3.3 Semantic anti-duplicate rule

For every target with existing learned candidates:

- if an existing WordSense expresses the same learnable meaning in the current context, return `matched_existing`;
- if an existing WordSense is substantially the same meaning with different wording, paraphrase, translation phrasing, or example tone, return `matched_existing`;
- `new_sense` is allowed only when the current contextual meaning is clearly different from every supplied candidate;
- when the AI cannot determine this reliably, return `ambiguous`.

Wording differences alone never justify a new WordSense.

Current code already validates package identity, candidate IDs, target completeness, stale packages, and the three result types. It does not yet enforce the new semantic anti-duplicate instruction. The later implementation must close that gap without inventing a second semantic truth source. When an AI still returns `new_sense` despite existing candidates, the product must expose those candidates during confirmation so a redundant Sense is not silently created.

High-confidence trusted `matched_existing` evidence may support a reading-derived review only when the reading-review rules below are satisfied. `new_sense`, `ambiguous`, low/medium-confidence results, unresolved identity, or same-session unfamiliar failures do not create a positive review merely because AI returned data.

### 3.4 Matched-existing real-source example binding

When a real Reader occurrence is authoritatively confirmed as `matched_existing` — either by the user or by the existing Trust-AI high-confidence path — the real source sentence must become source provenance for that WordSense.

The product reuses the existing `WordSenseOccurrence` + `WordSenseExamplePoolService` path:

- the English example sentence comes from the canonical Reader chapter/source revision, never from AI-generated prose;
- the accepted occurrence is idempotently bound/upserted to the existing WordSense;
- after binding, the existing Sense Review example pool automatically includes that sentence and its existing rotation rules apply;
- correcting the occurrence to another Sense moves the active source association instead of leaving the sentence active in two Sense pools;
- repeated paste-back/retry must not create duplicate example rows;
- preview/ambiguous/medium/low results do not create source binding;
- source/example binding alone never writes ReviewLog or changes FSRS.

This closes the current gap where Reading occurrence evidence can know `sentence B → existing Sense X` while the review example pool still sees only sentence A. The architecture authority is `docs/adr/ADR-0062-reading-ai-matched-existing-source-example-binding.md`.

## 4. Reading reinforcement, early review, and single-credit boundary

Reading is a real memory event. It may move a formal review **earlier** than the card's current scheduled due time, but repeated exposure must not be allowed to manufacture many reviews.

The current product rule is:

- a learned existing WordSense can receive one formal reading-derived review even when its current `fsrs_due_at` is still in the future;
- natural, unassisted recognition with reliable exact-Sense evidence may settle as one `Good` at Finish Reading (`reading_passive`);
- when the learner deliberately opens the recognition flow, recalls the meaning, confirms the exact existing Sense, and chooses “认识 / 记得”, that may settle immediately as one `Good` (`reading_explicit`); clicking the word is not itself disqualifying when the action is a self-recognition confirmation rather than help;
- opening/revealing help because the learner needs the answer prevents that occurrence from being treated as **passive** recognition;
- the same ReviewCard may receive at most one formal reading-derived rating in one ReadingSession; ten occurrences of the same Sense still count as at most one review;
- after a formal reading Good/Again is written, FSRS recalculates memory state and the next due from the actual review time; Reader must not create a second interval or familiarity system;
- when the user explicitly marks an occurrence as `不认识` and it later resolves to an **already learned existing WordSense**, that failure may create at most one formal `Again` and blocks every later positive reading rating for the same ReviewCard in that ReadingSession;
- the Reader does not run the short-step relearning loop after Again; follow-up learning remains owned by the existing formal Sense Review / FSRS system;
- if the unfamiliar occurrence is a genuinely `new_sense`, first enrollment is not a lapse and must not manufacture an `Again` or same-reading Good.

The forward Reader experience therefore centers on the reading decisions `认识/记得 → Good` and `不认识 → Again` after exact existing-Sense confirmation. The external Sense Review keeps the full Again/Hard/Good/Easy controls. Existing historical `reading_explicit` four-rating ReviewLogs remain valid data.

Cross-session and cross-article anti-farming now has one explicit minimum rule: a positive Reader `Good` is eligible only after **24 full elapsed hours** since the card's latest effective non-undone formal rating. This is not a local-calendar-day reset. Before 24 hours, the encounter remains reading evidence/exposure but produces no positive Reader rating; at or after 24 hours, a genuine exact-Sense recall may count as an early Good even if `fsrs_due_at` is still farther away. The 24-hour floor applies regardless of whether the previous formal rating came from external Sense Review, Reader, or another canonical formal rating source.

The 24-hour rule gates positive Good only. A truthful exact-existing-Sense `不认识` may still record one Again even inside 24 hours; Reader does not then run the short-step relearning loop.

The current architecture authority for this boundary is `docs/adr/ADR-0061-reading-early-review-minimum-spacing-boundary.md`. ADR-0060 and ADR-0059 are superseded historical stages and must not be implemented as current cross-session policy.

## 5. Stable translation layout

When saved AI sentence translations exist, translation visibility must not change the geometry of the English reading text.

The rule is:

- translated sentences establish their translation-ready slot before the user changes visibility;
- hidden, hover/focus, and continuously visible presentation states must keep the same English text positions;
- visibility may change; English line/paragraph placement must not jump because a translation is shown or hidden;
- sentences/materials with no saved translation do not reserve meaningless blank translation space;
- narrow screens follow the same stability rule.

The current committed Reader inserts/removes translation blocks with visibility and therefore does not satisfy this contract yet.

## 6. Reading continuity: progress, resume, bookmarks

Reading continuity uses stable text anchors, not scroll pixels.

### 6.1 Automatic latest position

The product stores one latest meaningful reading position per user/material context. Reopening an unfinished article continues at that position.

The anchor must be tied to the current source revision and a canonical text/token position so Desktop and Mobile can refer to the same location. A stale source revision must not be silently treated as the same text position.

The existing ReadingSession remains session/lifecycle identity; it is not itself a text-position store.

### 6.2 External progress

Library/material views show real reading progress for unfinished material. Progress is derived from completed content plus the latest canonical reading position; it is not a second independently editable percentage.

### 6.3 Manual bookmarks

Users can create multiple manual bookmarks in a text and jump back to them later. Manual bookmarks and the automatic latest position have separate user meanings, but they reuse the same canonical text-anchor contract.

This feature is a small bookmark capability, not a general annotation/highlight system.

## 7. Daily reading-new-Sense goal

The daily learning target means:

> “How many new WordSenses do I want to learn through reading today?”

The user enters the number directly.

Counting rules:

- count a distinct WordSense once when it first enters the learning system from the reading flow;
- a new Sense of an already known lemma still counts because the learning unit is Sense;
- an already learned Sense encountered or reviewed again does not count as a new Sense;
- AI suggestions that are never confirmed/enrolled do not count;
- legacy EncounteredWord stage changes, phrase counters, ReviewLog count, and review-queue new-card limits are not this metric.

Reaching the target never blocks continued reading or creation of additional legitimate WordSenses. The product does not ask for a time budget and does not let future workload predictions decide the user's daily target.

The existing numeric Goal UI may be reused, but its old `learn_words` semantics must be rebased onto the canonical WordSense learning-entry event before it is treated as this product metric.

## 8. Learning record and history

Home check-in and calendar become an entry into actual learning history.

### 8.1 Day and date range

The user can open:

- one selected day;
- an arbitrary inclusive date range.

The result is centered on concrete WordSenses and their learning/review events, rather than editable aggregate achievement numbers.

At minimum the history distinguishes:

- newly learned through reading;
- natural reading review (`reading_passive`), including a legitimate early Good;
- explicit reading review (`reading_explicit`), including current Good/Again intent and historical four-rating rows;
- external formal Sense Review (`sense_review`).

Where available, rows show the Sense, source/article context, relevant sentence, rating/event type, and current memory state. A same-reading later “认识/记得” after an unfamiliar failure must not appear as a successful learning event.

### 8.2 One reliable learning-entry time

The current system lacks a reliable semantic timestamp for “this WordSense first entered learning”. `created_at`, `updated_at`, legacy word stage, and first ReviewLog each mean something different.

A later implementation must establish exactly one canonical WordSense learning-entry time/event at the existing confirmed-Sense enrollment path. This fact feeds daily goal counting, history, date filtering, and exports. It must not become a second learning-card or rating system.

### 8.3 Unified exports

The same learning-history query/result feeds:

- PDF;
- TXT;
- CSV.

Format renderers must not implement three different history queries. Existing PDF/CSV rendering patterns can be reused, while Browser/card inventory export remains a separate advanced/portable-data concern.

## 9. Memory durability and future review pressure

The ordinary product exposes understandable memory state without requiring users to read raw FSRS parameter vectors.

### 9.1 Memory durability

Users can filter learned WordSenses by date range and inspect states such as:

- 容易遗忘;
- 正在巩固;
- 掌握稳定.

Existing ReviewLog/FSRS/leech-policy evidence should be reused before adding another classifier. Presentation must not label a newly created or evidence-poor Sense as strongly “掌握稳定” merely because an internal fallback bucket currently says `stable`; evidence sufficiency remains visible in the explanation.

The page may show understandable facts such as recent rating history, retrievability, stability, difficulty, and lapse tendency. Raw model vectors stay in diagnostics/advanced views.

### 9.2 Future pressure

Future workload is a forecast based on current scheduling/history. Ordinary users can understand:

- tomorrow's expected reviews;
- next 7 days;
- next 30 days;
- next 90 days;
- a simple future pressure curve.

Forecasts do not prescribe a daily new-Sense target and do not silently mutate scheduling.

## 10. FSRS product contract

The existing canonical FSRS scheduler remains the scheduling authority.

### 10.1 Ordinary presentation

All users can access basic learning analytics and model state in understandable language. Raw engineering parameter vectors move behind diagnostic/advanced presentation.

### 10.2 Manual optimization

Users can explicitly choose “现在优化记忆模型”. The existing historical ReviewLog → optimization path remains the single optimization engine and keeps its current eligibility/safety checks.

### 10.3 Configurable interval optimization

Users can choose:

- manual only; or
- automatic optimization every N days.

Automatic interval mode defaults to **30 days** and the interval is user-configurable. Eligibility can be derived from the last successful optimization time plus the configured interval; a second independent optimization schedule truth is unnecessary.

Automatic optimization uses the same optimizer as manual optimization.

### 10.4 Optimization and rescheduling remain separate

Parameter optimization changes the parameters used by future scheduling decisions. It does not silently reschedule existing cards.

Rescheduling existing cards remains a separate user-triggered action with impact preview and explicit confirmation. Automatic parameter optimization must never call that reschedule action implicitly.

## 11. Premium boundary

This rebaseline does not introduce a paywall around the user's basic learning truth.

At minimum, all users retain access to:

- the ordinary reading/Sense learning loop;
- their basic learning history and model state;
- understandable memory status;
- future review forecast needed to understand upcoming workload.

No new premium feature set is frozen here. Future commercial packaging must be decided separately and cannot change the semantic truth of learning history, WordSense identity, ReviewLog/FSRS scheduling, or make the product hide the basic model state that this rebaseline explicitly opens to all users.

## 12. Reclassification of engineering-style user surfaces

The following decisions apply to **user-facing product surfaces**. Shared lower owners, persisted rows, compatibility readers, safety ledgers, migration/recovery duties, and current callers stay protected until a later implementation proves their transition.

### Saved Search

The old “Saved Search” user concept is retired in favor of **学习记录 / 历史回顾** for ordinary learning review. Existing saved-search persisted data and lower query compatibility remain until current callers are rewired and old rows are safely handled.

### Tag / Marker

Tag / Marker are no longer assumed to be required user features. The product direction is to remove the generic Tag/Marker user surfaces during the later reclassification implementation unless a concrete current product need is separately demonstrated.

This does not authorize immediate deletion of WordSense tag relations, ReviewCard marker fields, APIs, filters, import/export compatibility, or current callers. Those lower contracts require their own dependency proof.

### Manual Scheduling / Review Control

Engineering-style direct scheduling/lifecycle controls leave the ordinary learning flow. Existing manual-operation/audit/undo safety owners remain protected while callers and any necessary diagnostic access are reassessed.

### Generic Browser

A generic database-like Browser stops being a central user tool. Useful capabilities are rehomed to the product concepts that own them: WordSense detail, learning history, memory diagnostics, data portability, or internal maintenance. Shared query/detail serializers remain reusable until migration is complete.

### Knowledge Hygiene

Knowledge Hygiene becomes internal maintenance/automation and recovery-oriented tooling rather than a primary user feature. Its safe-delete/merge/backup/undo responsibilities remain protected until a later dependency and safety audit proves what can be retired.

### Article Health

Article Health becomes a **material diagnostic / anomaly** entry associated with the material that has a problem. It is no longer framed as a generic “advanced user feature”. Read-only health owners remain useful beneath that product surface.

### Other G-04 families

The old blanket conclusion that every one of the 11 families must keep its previous user-facing form is superseded. Families not explicitly reclassified above are not automatically deleted; they continue under their current contracts until a fresh product/caller/safety decision addresses them.

## 13. Phase G forward execution boundary

This document authorizes product direction and roadmap rewriting only. It does not authorize implementation by itself.

The next Phase G implementation work is split in the Goal ledger into:

- G-06A English-only convergence;
- G-06B opportunistic early reading review + full-24-hour minimum positive spacing + same-session single-credit + failure/short-step boundary;
- G-06C AI anti-duplicate + authoritative matched-existing real-source example binding + stable translation layout;
- G-06D reading progress/resume/bookmarks;
- G-06E daily new-Sense goal + learning history/date-range exports;
- G-06F memory analytics/future workload/FSRS productization;
- G-06G engineering-surface retirement/rehome;
- G-GATE final product/browser regression and slim-product acceptance.

G-06G must occur after the replacement destinations it depends on exist. Physical deletion remains evidence-driven.

No production implementation, database mutation, external AI call, restore, destructive data operation, or automatic transition into the next task occurs as part of this rebaseline document.

## 14. Supersession map

The following older forward statements remain useful as history but no longer control conflicting current product behavior:

- `docs/product/confirmed-product-decisions-and-discussion-roadmap-2026-07-23.md` PD-002 / PD-003 / PD-009 / relevant PD-013 user-surface retention language, where they require Tag/Saved Search/Browser-style concepts to remain current user features;
- the same document's PD-012 generic Reader four-rating workflow and DISC-004 statement that overall translation layout stability was still unfrozen;
- `docs/plans/LinguaCafe_Phase_G_Advanced_Capability_Classification_2026-08-18.md` as a forward guarantee that all 11 families must keep their then-current user-facing classification;
- the former single G-06 scope limited to non-English/media cleanup.

G-04 and G-05 remain valid historical acceptance evidence. Their lower-owner, compatibility, data-safety, and caller findings remain inputs to later implementation and deletion decisions.
