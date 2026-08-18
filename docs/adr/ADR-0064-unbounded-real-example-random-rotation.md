# ADR-0064 — Unbounded Real-Source Example Pool and Random Rotation

- Status: Accepted product direction
- Date: 2026-08-18
- Scope: WordSense real-source example pool / Sense Review example selection
- Supplements and partially supersedes: ADR-0062 example-selection assumptions

## Context

ADR-0062 freezes the provenance bridge: an authoritative Reader `matched_existing` decision must bind the real Reader sentence into the existing `WordSenseOccurrence` / `WordSenseExamplePoolService` path rather than creating a second AI example pool.

Fresh code review then found two existing policies that were not yet product-authoritative:

1. `WordSenseExamplePoolService::exampleCandidateBatch()` currently limits each Sense to the first 10 occurrence rows with `take(10)`.
2. Question selection currently uses deterministic linear rotation based on `reviewCardId + fsrsReps + fsrsLapses`.

The user has now explicitly decided that LinguaCafe cannot reliably judge which real source sentences are “more valuable”. Therefore the product must not retain only a recent/top-ten window or otherwise rank some real examples out of participation.

The user also wants the full set to participate in automatic rotation with randomization, while preserving the already-frozen rule that when multiple examples exist the same example must not appear on consecutive formal reviews.

## Decision

### 1. All distinct real source examples remain eligible

For a WordSense, every distinct active real-source example that passes the existing ownership/source-validity/dedupe rules participates in the same example pool.

Do not cap the pool at 10, 20, 30, or another arbitrary product limit.

Do not discard an example merely because it is older, newer, manually added, AI-assisted, from a particular article, or judged “less valuable”. LinguaCafe does not have a trustworthy value-ranking signal for these sentences.

The existing duplicate rule remains valid: duplicate display sentences within the same chapter may collapse according to the current normalized sentence identity, and a card fallback identical to a real occurrence does not need a second copy.

### 2. Keep one example pool

All examples share the existing `WordSenseExamplePoolService` owner.

This includes:

- the original sentence where the Sense was learned;
- later user-confirmed `matched_existing` Reader sentences;
- later Trust-AI high-confidence authoritative `matched_existing` Reader sentences;
- real-source occurrences from other existing legitimate binding paths;
- the existing card fallback only when it is not already represented by an equivalent real occurrence.

Do not create an AI-only pool, recent-example pool, premium pool, or separate “high-value” pool.

### 3. Selection is randomized across the full eligible pool

When more than one eligible example exists, the next formal Sense Review question example should be selected from the full eligible pool with random/shuffled rotation semantics rather than fixed A→B→C linear order.

However, randomization must preserve the existing user rule:

> if multiple examples exist, the same example must not be shown on two consecutive formal reviews.

Implementation should use the smallest existing-state-based mechanism that can satisfy both properties. It must not add a new persistence table or per-example quality score merely to remember rotation history if the existing ReviewCard/review context can provide enough state.

The exact pseudorandom/shuffle implementation is an implementation-detail Architecture Gate question, but acceptance must prove:

- every eligible real example can be selected over repeated reviews;
- there is no fixed top-N candidate window;
- consecutive formal reviews do not repeat the same question example when at least two candidates exist;
- the supplementary example still differs from the current question example when at least two candidates exist.

### 4. No value ranking

The current manual-source-first / newest-first database ordering may remain as a stable load order only if it has no effect on long-term selection eligibility or selection probability.

It must not be interpreted as a product ranking saying manual, recent, or AI-assisted examples are intrinsically better.

If the random-selection implementation would inherit bias from that ordering, the implementation must remove that bias rather than introduce a new value heuristic.

### 5. Provenance and rating boundaries remain unchanged

This ADR changes example candidate participation/selection only.

It does not change:

- WordSense identity;
- source binding authority;
- ReviewLog write rules;
- FSRS scheduling;
- Reader 24h review eligibility;
- source-revision validation;
- correction/rebind behavior.

Example serialization/selection remains read-only.

## Consequences

1. The current `take(10)` per-Sense occurrence window is no longer acceptable forward behavior and must be removed/reworked.
2. ADR-0062 wording that assumed the existing rotation algorithm required no change is superseded by this decision.
3. The source-example implementation slice now includes `WordSenseExamplePoolService` and focused rotation tests.
4. Existing A/B/C tests may continue as small fixtures, but they must no longer freeze a linear A→B→C product sequence.
5. Tests should include a Sense with more than 10 distinct real examples and prove candidates beyond index 10 are eligible.
6. Browser acceptance should prove source examples remain real, multiple examples rotate without immediate repetition, and no second example system appears.
