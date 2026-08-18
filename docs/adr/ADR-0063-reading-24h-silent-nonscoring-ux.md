# ADR-0063 — Reading 24h Silent Non-Scoring UX

- Status: Accepted product direction
- Date: 2026-08-18
- Scope: Reader behavior when ADR-0061 blocks a positive Good because fewer than 24 actual hours have elapsed
- Supplements: ADR-0061

## Context

ADR-0061 freezes the server-side positive spacing rule: a Reader-derived `Good` is eligible only after at least 24 actual elapsed hours from the latest effective non-undone formal rating for the same ReviewCard.

The remaining product question was whether the Reader should surface a message such as “本次只记录阅读遇见，不计复习” when the 24h floor blocks a positive rating.

The user has explicitly rejected that extra feedback. The rule will already be explained in onboarding/user guidance. Normal reading should not be interrupted or visually cluttered merely because a background review opportunity is currently non-scoring.

## Decision

### 1. A 24h-blocked positive encounter is silent

When every normal Reader eligibility condition is satisfied except the ADR-0061 24h positive floor:

- write zero Reader Good;
- write zero ReviewLog for that positive encounter;
- leave ReviewCard / FSRS unchanged;
- continue reading normally;
- show no popup;
- show no snackbar;
- show no inline warning;
- show no badge or disabled-state explanation;
- do not ask the user to acknowledge the block.

The encounter may still exist as ordinary reading/exposure evidence where current architecture already records it, but the user-facing Reader remains silent.

### 2. The server still owns the decision

Silence is a presentation rule, not permission to move the 24h check to the client.

The locked canonical writer remains the final authority. A stale preflight that expected a Good may become non-scoring after a concurrent rating; that race loser is also silent and continues as ordinary exposure.

### 3. Real failure remains visible when the user explicitly chooses it

This ADR applies only to a positive Good suppressed by the minimum-spacing floor.

It does not hide an explicit user action such as `不认识`, nor does it suppress a legitimate `Again` for an exact existing Sense. User-initiated failure/identity flows keep the feedback needed to complete that explicit action.

### 4. No hidden cooldown UI state

Do not add a client countdown, “next Reader Good available at” label, timer, or local eligibility cache merely to support this silent rule.

Onboarding/help may explain the general 24h rule, but the Reader does not surface per-card cooldown status during ordinary reading.

## Consequences

1. Any G-06B R3 architecture wording requiring a visible “24h block message”, “stable 24h conflict UI”, or equivalent user-facing notice is superseded.
2. Automated acceptance should verify the no-write outcome; it should not require visible block copy.
3. Real-browser acceptance for <24h should verify that reading remains usable and no new warning/snackbar/popup appears.
4. External Sense Review remains unchanged.
5. ADR-0061 remains the timing/data authority; this ADR only fixes the ordinary Reader presentation of a blocked positive encounter.
