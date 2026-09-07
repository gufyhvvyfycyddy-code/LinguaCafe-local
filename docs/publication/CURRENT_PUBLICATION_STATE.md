# Current Publication State

Updated: 2026-09-07

## Public repositories

- Source of truth: `gufyhvvyfycyddy-code/LinguaCafe-local` (public, default branch `master`).
- Architecture review: `gufyhvvyfycyddy-code/LinguaCafe-architecture-review` (public).
- Product / launch review: `gufyhvvyfycyddy-code/LinguaCafe-product-launch` (public).
- `LinguaCafe-dev-main` is historical and must not be treated as the current source of truth.

## Source state

Current source checkpoint for the synchronized public-review package: `2abc82df754525c19733382200aaf72a930d436a` (merged source PR #40).

The frozen functional browser-review baseline remains `6989ed27c933716f9069bb9b14fba92624081fc4`. Later default-branch commits contain documentation, repository-hygiene, dependency, tokenizer-build and validation fixes. Reviewers should distinguish frozen user-flow evidence from the current default branch.

Post-baseline source fixes include tokenizer reproducibility (PR #30), registration validation (PR #31), targeted browser-runtime dependency updates (PR #32), compatible PHP security refresh (PR #33), publication synchronization (PR #34/#35), tokenizer/IIS CodeQL remediation (PR #36/#37), unused Vue3 experiment removal (PR #39), and BrowserSync 3 development-tool cleanup (PR #40). Architecture Issues #23/#25/#26/#28/#29 are closed.

Cross-repository status synchronization is complete:
- architecture-review PR #31 merged as `78ef7c9c384643b00af0b559d22541b802ebe350`, recording current dependency High dispositions and CodeQL/dependency status;
- product-launch PR #18 merged as `2d24e516b04dac9b5e9283d2c03fbba50844f51d`, synchronizing launch/security status;
- historical `LinguaCafe-dev-main` PR #1 merged as `b7fe9ee1d602e5aedeb26469546e798ed19edb4f`, and its README points to the current source/review repositories;
- duplicate source Issues #12–#18 were closed with `duplicate` state while canonical Issues #5–#11 remain open.

The protected primary local checkout remains user-owned and intentionally unsynchronized because it contains uncommitted assets. Publication/security work uses clean isolated worktrees at the remote head; no reset, clean, stash or bulk staging is used on the protected checkout.

## Current conclusion

The three-repository external-review package exists, its public status documents are synchronized with the post-baseline source fixes above, and the historical repository is visibly marked as historical.

Real-user release readiness remains incomplete. Dependency High triage is complete (28 open total, 0 Critical, 6 High with explicit dispositions) and current default-branch CodeQL has 0 open alerts. Public-source environment hygiene, Android release evidence, iOS release evidence, production hosting, privacy/support and real-user validation remain open.
