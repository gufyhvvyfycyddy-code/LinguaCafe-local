# ADR-0022: WordSense POS canonicalization boundary

- Status: Accepted
- Date: 2026-07-15
- Related: ADR-0021

## Context

Manual sense creation and editing accept one POS contract, while reading-assist suggestions can contain short values. Real project inputs include uppercase `NOUN`/`ADJ`, lowercase `noun`/`adj`, and dictionary forms that its existing mapper already converts to canonical values. Passing raw `adj` from the reading UI to `/senses/manual` produced a structured 422 response that the UI hid behind a generic message.

## Decision

The canonical WordSense POS set is:

`noun`, `verb`, `adjective`, `adverb`, `preposition`, `conjunction`, `phrase`, `other`.

The manual-sense boundary accepts only these evidenced, case-insensitive aliases after trimming:

| Alias | Canonical value |
|---|---|
| `n` | `noun` |
| `v` | `verb` |
| `adj` | `adjective` |
| `adv` | `adverb` |
| `prep` | `preposition` |
| `conj` | `conjunction` |

The frontend normalizes AI, dictionary, ordinary-create, and edit prefill before `ManualSenseForm` receives the value, so the select and outgoing payload use a canonical value. The controller independently normalizes both create and update input before the existing allow-list validation because it is the server trust boundary. Known aliases are stored canonically. Unknown values are preserved for validation and return structured HTTP 422; they are never coerced to `other`.

Dictionary aliases such as `vi`, `vt`, and `a` remain owned by the existing dictionary mapper, which already emits canonical values. They are not duplicated into the manual API alias contract without evidence that a manual client sends them. Tokenizer Universal POS values remain a separate token annotation contract.

For 422 responses, the UI shows a specific Chinese POS or required-meaning error, or the first safe structured field message. HTML and unknown/network failures use the generic fallback. A failed save leaves the form and user input intact and has zero learning side effects: no WordSense, ReviewCard, ReviewLog, stage change, or learning target is created.

## Shared Form and field validation follow-up

Manual create and edit now render the same `ManualSenseForm` field template. The shared component owns its local draft, create/edit field visibility, inline Vuetify errors, advanced-section state, and first-error focus. `WordSensesList` continues to own the distinct POST/PUT requests, create context snapshot, enrollment response handling, and success lifecycle. The existing `ManualWordSenseFormService` supplies the pure local validator and safe structured 422 mapper; it does not access Vuex, DOM, stage, FSRS, ReviewLog, or Enrollment.

This follow-up does not alter the accepted POS set, aliases, HTTP payload shape, controller trust boundary, or ADR-0021 enrollment semantics. Code, automated tests, and final DevSpace5/Chrome web acceptance are complete. The shared-form follow-up is **Accepted / Production Closed** as of 2026-07-15 on master `a0916784951be69b411066446a03be940373589f`.

Final browser acceptance covered the real reader at `1920×1080` and `900×900`: successful create normalized the submitted POS to `adjective`, moved the selected EncounteredWord from stage `2` to `-1`, created one confirmed WordSense and one sense ReviewCard, created no legacy word card and no ReviewLog, and showed 10% familiarity after reload. Empty Chinese meaning in create and edit sent no POST/PUT, displayed and focused the inline field error, and kept the draft and advanced-section state. Create and edit network failures displayed safe generic text, preserved the draft, and produced zero learning-data delta. The narrow viewport had no horizontal overflow, and a clean reload introduced zero application console errors or warnings.

## Boundaries and consequences

ADR-0021 remains accepted and authoritative for confirmed-sense enrollment. This decision does not change FSRS, ReviewLog semantics, ReviewCard lifecycle, `EncounteredWord::setStage`, legacy stage compatibility, or legacy word-card behavior. It adds no migration or schema change.

Rollback consists of reverting the frontend helper/use sites and controller normalization together. Existing canonical database values require no rollback or backfill; removing only one layer would reopen inconsistent client behavior and is not supported.
