# ADR-0002: Sense-Only Review And AI Study Card Boundaries

## Status

Accepted for current sense-only boundaries. AI study card generation remains planned and not implemented.

## Date

2026-07-02

## Context

LinguaCafe has moved from legacy word-level review toward a sense-only learning model:

- `WordSense` is the real learning object.
- `ReviewCard.target_type = sense` is the main review line.
- `ReviewCard.target_type = word` remains only for legacy compatibility.
- `EncounteredWord` supports reading-page color, familiarity overview, occurrence history, and compatibility behavior.

Recent product planning also froze an AI study card direction. That plan is not implemented yet, but several decisions are stable enough to document so future agents do not re-open them every task.

This ADR records only accepted boundaries. It does not define new database schema, route names, component splits, or implementation order.

## Decision

### Sense-Only Review Line

1. New learning and review features should target confirmed `WordSense` objects and sense `ReviewCard`s.
2. Legacy word cards must not be brought back into the daily review mainline.
3. `EncounteredWord` should not become the primary review object again.
4. Review scheduling and FSRS behavior should preserve existing sense-card tests before any semantic change.

### WordSense / EncounteredWord / ReviewCard Responsibilities

1. `WordSense` represents the meaning being learned.
2. `EncounteredWord` represents a word or phrase occurrence in reading context and supports display state.
3. `ReviewCard` represents the schedulable review item.
4. `ReviewLog` is review history and is preserved by default.

### Delete / Archive / Restore Semantics

1. Archive means pause review; it is not the same as permanent deletion.
2. Permanent deletion of a sense review card preserves review history by default.
3. Restoring an `EncounteredWord` to new is tied to the accepted deletion semantics and should remain covered by contract tests.
4. Any future change to ReviewLog deletion, archive restore, or bulk deletion semantics requires a separate product decision and tests.

### AI Translation Versus AI Study Cards

1. AI sentence translations support reading comprehension.
2. AI translations must not automatically create `WordSense`, `ReviewCard`, or `ReviewLog`.
3. AI study cards are a separate planned workflow and are not implemented by this ADR.

### Planned AI Study Card Product Boundaries

These rules are accepted product boundaries for future implementation, but the implementation is still planned:

1. User-selected words and phrases have priority.
2. AI-recommended words must exclude words already selected by the user.
3. AI-recommended words are unchecked by default in confirmation UI.
4. A "select all" affordance may exist, but confirmation must remain explicit.
5. Only user-confirmed AI recommendations can enter study card generation.
6. The main frontend review entry should be user-facing "复习"; internal labels like "词义确认" and "词义复习" should not be exposed as the primary learning entry.

## Alternatives Considered

### Treat AI Translation As Card Generation

Rejected. Translation is a reading aid. Automatically turning it into learning data would cross a write boundary and would make AI output too powerful without user confirmation.

### Let Legacy Word Cards Continue As A Parallel Mainline

Rejected. It would split the learning model and make review scheduling harder to reason about. Legacy support stays for compatibility, not for new feature direction.

### Write A Full AI Study Card Implementation Spec Now

Rejected for now. The workflow is planned but not implemented, and the code boundaries still need architecture scouting. Writing schema, endpoints, or component details now would turn future guesses into false constraints.

## Consequences

- Future implementation tasks can reference this ADR instead of re-arguing the same product boundaries.
- The master plan can stay shorter and point here for stable decisions.
- Soft product boundaries still need hard verification before implementation, especially browser smoke and contract tests listed in `docs/plans/spec-to-harness-candidates.md`.

## Validation

Current protection is split across existing tests and future candidates:

- WordSense deletion/archive/restore semantics are protected by WordSense contract tests.
- FSRS sense-card scheduling is protected by FSRS and WordSense tests.
- AI translation not creating learning data is covered by AI reading assist tests.
- AI study card UI and selection behavior still need future smoke/tests before implementation.

## Notes

This ADR must not be read as permission to implement AI study cards. Implementation still requires architecture scouting, product confirmation, and task-specific allowed files.
