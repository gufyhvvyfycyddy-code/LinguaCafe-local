# Current Publication State

Updated: 2026-09-07

## Public repositories

- Source of truth: `gufyhvvyfycyddy-code/LinguaCafe-local` (public, default branch `master`).
- Architecture review: `gufyhvvyfycyddy-code/LinguaCafe-architecture-review` (public).
- Product / launch review: `gufyhvvyfycyddy-code/LinguaCafe-product-launch` (public).
- `LinguaCafe-dev-main` is historical and must not be treated as the current source of truth.

## Source state

Source checkpoint used for the completed cross-repository publication sync: `abba49dd9723170d861839b478dad9224505ea8c` (merged source PR #34).

The frozen functional browser-review baseline remains `6989ed27c933716f9069bb9b14fba92624081fc4`. Later default-branch commits contain documentation, repository-hygiene, dependency, tokenizer-build and validation fixes. Reviewers should distinguish frozen user-flow evidence from the current default branch.

Post-baseline source fixes include the reproducible tokenizer build (source PR #30), registration password-validation synchronization (source PR #31), targeted browser-runtime dependency updates (source PR #32), and publication-state documentation (source PR #34). Architecture Issues #25 and #26 are closed.

Cross-repository status synchronization is complete:
- architecture-review PR #30 merged as `77cf71eeb85c36b7acd15008976d08c4768b19a5`;
- product-launch PR #17 merged as `7e89cad03e086139d34bb0f56e108783979493d3`;
- historical `LinguaCafe-dev-main` PR #1 merged as `b7fe9ee1d602e5aedeb26469546e798ed19edb4f`, and its README now points to the current source/review repositories;
- duplicate source Issues #12–#18 were closed with `duplicate` state while canonical Issues #5–#11 remain open.

The protected primary local checkout was left untouched because it contains user-owned uncommitted work and was 34 commits behind `origin/master` at this audit. Publication verification uses a clean isolated worktree at the remote head.

## Current conclusion

The three-repository external-review package exists, its public status documents are synchronized with the post-baseline source fixes above, and the historical repository is visibly marked as historical.

Real-user release readiness remains incomplete. Public-source environment hygiene, dependency/security triage, Android release evidence, iOS release evidence, production hosting, privacy/support and real-user validation remain open.
