# Goal-Mode Roadmap Completion Audit

Date: 2026-07-23

Status: **Complete**

Goal: complete every authorized milestone in the Anki-aligned product and architecture sequence.

## Milestone ledger

| Milestone | Final state | Primary evidence |
|---|---|---|
| Phase 0 current-fact convergence | Completed / Production Closed | Anki-aligned roadmap |
| Phase 1 Settings architecture | Completed / Production Closed | ADR-0023 |
| Phase 2 Preset V1A–V1D | Completed / Production Closed | ADR-0024–0027 and browser matrix |
| Phase 3 Browser / ReviewCardManage | Accepted / Production Closed | Phase 3D browser acceptance |
| Phase 4 Card Marker + Custom Study 1B | Accepted / Production Closed | ADR-0029 and browser acceptance |
| Phase 5 Reviewer convergence | Accepted / Production Closed | reviewer browser acceptance |
| Phase 6 Reader frontend/backend governance | Accepted / Production Closed | Phase 6A–6M acceptance chain |
| Phase 7 AI Study Card service convergence | Accepted / Production Closed | Phase 7A–7E acceptance chain |
| Provider Environment Gate | Accepted / Closed default-off | ADR-0030; 80 tests / 806 assertions |

Phase 7 reduced `AiStudyCardPendingItemService.php` from 1,065 to 61 lines and assigned lifecycle, packages, validation, source binding, and generation to named owners. Its final protected matrix passed 1,093 tests / 6,124 assertions, the frontend build succeeded, and the official Browser completed the manual-confirmation generation flow with zero ReviewLog writes and complete fixture cleanup.

The provider audit kept external requests disabled, made no live provider call, and hardened policy snapshots so secret values and secret references cannot escape.

## Non-milestone registry items

The master plan still preserves several future or external items, but they are not unfinished milestones in the authorized sequence:

- AI Reading Assist phrase/word dual-path exploration is explicitly Partial and non-blocking; phrase FSRS remains excluded by product scope.
- WordSense free-form Tag is a Product Gate intentionally deferred behind the completed ReviewCard Marker design.
- DevMain / alternate-machine reproduction is Deferred / Unverified because it requires another machine or checkout, not repository implementation.
- Runtime provider activation is an environment-specific decision requiring exact provider/model/secret/timeout/cost/Network authorization. The repository milestone is the completed default-off gate, not activation.

No authority conflict, open authorized implementation slice, required migration, unverified production write, or pending browser acceptance remains in the current roadmap.

## Verification basis

- Current roadmap phase/status audit.
- Master-plan Open Work Registry audit.
- Exact-scope Git history and acceptance reports.
- Phase 7E protected matrix and official Browser acceptance.
- Full V6 fail-closed suite and provider policy hardening.
- Documentation conflict search and `git diff --check`.

The persistent goal may therefore be marked complete without treating future Product Gates, runtime configuration, or external-machine reproduction as blockers.
