# Worktree Inventory

Snapshot: 2026-09-07

This inventory describes the protected primary local checkout. It records file ownership risk only; it does not publish file contents.

## Git relationship

- Local branch: `master`
- Local head at inventory: `28c12d41b242bccb79e414d3d37db317ca7e0c5c`
- Remote head at inventory: `2d8f062fcc453fa609abbed228df371dd192ec57`
- Divergence: local was 34 commits behind and 0 commits ahead.

## Uncommitted assets

- tracked modified: 9
- tracked deleted: 1
- untracked: 7
- source: 6
- tests: 4
- documentation: 6
- generated: 1
- migration: 0
- unknown: 0

Remote changes overlap five locally modified application paths. The primary checkout must not be reset, cleaned, stashed, bulk-staged, or blindly fast-forwarded.

## Preservation rule

All dirty-worktree changes are user assets until their owning task is identified. Publication work uses an isolated clean worktree based on the remote branch and does not reconcile or overwrite the protected checkout.
