# ADR-0052 — Goal-mode Deferred Evidence Clusters

## Status

Accepted under the user's explicit authorization to remove the single-deferred
path limit

## Supersession

This ADR supersedes ADR-0034 and ADR-0037 only where they impose a numeric limit
of one deferred node per dependency path or require
`Blocked by Deferred Dependency — Not Complete` solely because a later slice
needs evidence from the same unavailable device/external capability.

All completion semantics, server-bound testing requirements, platform safety
refusals and reserved destructive/external stop lines remain in force.

## Context

The one-deferred-per-path rule prevented unverified behavior from silently
compounding, but it also froze locally verifiable implementation. In the mobile
roadmap, one unavailable Android device can withhold M7 interaction evidence,
M8 offline evidence, M17 haptics and M18 offline audio at once even though their
implementations consume already executable server contracts and have separate
automated tests. Naming the later slices "blocked" does not improve safety.

## Decision

1. Track missing evidence by **capability cluster** (for example, Android device,
   iOS signing device, or store submission) instead of a numeric path budget.
2. A downstream implementation slice may proceed when its Architecture Gate
   proves that every implemented seam consumes an executable, verified contract
   and does not depend on the missing behavior being correct.
3. Every unavailable observation remains explicitly
   `Acceptance Deferred — Not Complete` inside its capability cluster. A later
   slice may add another named check to the same cluster without becoming a new
   implementation blocker.
4. Multiple different clusters may coexist on one roadmap path. Work continues
   wherever it is reversible and independently testable; no cluster authorizes
   deployment, signing, publication, paid services, secrets or destructive data
   actions.
5. A slice must pause only when its implementation itself requires an unverified
   behavior, a reserved stop-line action, or an unresolved highest-authority
   decision—not merely because an ancestor has deferred evidence.
6. Final goal completion still requires every cluster and every named check to be
   cleared with actual evidence. Deferred is never Accepted, Closed or Complete.

## Verification

- Documentation guard asserts capability-cluster semantics and final-clearance
  semantics.
- Each slice acceptance report lists its cluster, named checks and automated
  contract evidence.
- Fresh adversarial review checks that the rule cannot launder platform refusals,
  real-data writes, signing/publication or absent behavior into completion.
