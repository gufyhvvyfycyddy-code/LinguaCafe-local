# Current Publication State

Updated: 2026-09-07

## Public repositories

- Source of truth: `gufyhvvyfycyddy-code/LinguaCafe-local` (public, default branch `master`).
- Architecture review: `gufyhvvyfycyddy-code/LinguaCafe-architecture-review` (public).
- Product / launch review: `gufyhvvyfycyddy-code/LinguaCafe-product-launch` (public).
- `LinguaCafe-dev-main` is historical and must not be treated as the current source of truth.

## Source state

Publication audit source head: `2d8f062fcc453fa609abbed228df371dd192ec57`.

The frozen functional browser-review baseline remains `6989ed27c933716f9069bb9b14fba92624081fc4`. Later default-branch commits contain documentation, repository-hygiene, dependency, tokenizer-build and validation fixes. Reviewers should distinguish frozen user-flow evidence from the current default branch.

The protected primary local checkout was left untouched because it contains user-owned uncommitted work and was 34 commits behind `origin/master` at this audit. Publication verification uses a clean isolated worktree at the remote head.

## Current conclusion

The three-repository external-review package exists and is usable for review.

Real-user release readiness remains incomplete. Public-source environment hygiene, dependency/security triage, Android release evidence, iOS release evidence, production hosting, privacy/support and real-user validation remain open.
