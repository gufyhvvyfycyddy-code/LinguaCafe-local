# HANDOFF-03 — PC build efficiency — 2026-09-02

## Completed

- PC packaged runtime now binds Docker image tags to `runtime-version.txt` / exact Git snapshot instead of rebuilding blindly on every installed-build launch.
- Same installed version reuses existing Web/Python images; a new test build or missing image triggers rebuild.
- Development-source mode keeps `dev` tags and continues rebuilding so local validation cannot silently use stale backend code.
- `.dockerignore` now excludes `/desktop`, so WPF-only edits no longer invalidate the production PHP/Web image source COPY layer.
- `WindowsPcTestBuildGuard.test.mjs` permanently checks the version-cache contract and `/desktop` build-context exclusion.

## Evidence

- Windows PC Guard: PASS.
- WPF Release build after the change: 0 warnings / 0 errors.
- Real Docker Web build succeeded with fixed FSRS Cargo.lock and `/desktop` excluded.
- This is build/test tooling only; no Reader, FSRS scheduling, Web auth, DB schema, or mobile product behavior changed.

## Current limitation

- Docker still transfers a large root build context. Further exclusions must be justified by actual runtime ownership; do not broadly hide project files merely to reduce bytes.

## Next

- Finish real interactive WebView2 first-run validation and record I-02 evidence.
