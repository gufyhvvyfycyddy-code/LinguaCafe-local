# HANDOFF-01 — FSRS Docker reproducible build — 2026-09-02

## Completed

- Fixed Windows checkout corruption of `docker/fsrs-rs-php-php84.patch` by enforcing `*.patch text eol=lf` in `.gitattributes`.
- Replaced the production Docker build's runtime `cargo update` + historical `Cargo.lock` hash check with a repository-pinned `docker/fsrs-rs-php-php84.Cargo.lock` and `cargo build --release --locked`.
- The pinned lock was generated from upstream `fsrs-rs-php` commit `122299bc273ebecc07f5022b91939380951b5688` after applying the existing PHP 8.4 patch; `ext-php-rs` is locked to `0.15.15`.
- Added `tests/js/FsrsDockerReproducibilityGuard.test.mjs`.

## Evidence

- `FsrsDockerReproducibilityGuard.test.mjs`: PASS.
- `WindowsPcTestBuildGuard.test.mjs`: PASS.
- WPF Release build: PASS, 0 warnings / 0 errors.
- `git diff --check`: PASS.
- Real Windows Docker build of `linguacafe-pc-test-web:latest`: PASS.
- The real build reached and passed `git apply --check`, `cargo build --release --locked`, the native FSRS PHP smoke, Laravel Mix production build, Composer install and final image export.

## Tooling issue closed

This was a shared LinguaCafe production-build defect, not a PC-only workaround. Windows fresh checkouts can now build the same production Web Dockerfile without CRLF patch failure or registry-time lockfile drift.

## Next

Start the isolated PC-test MySQL/Redis/Python runtime with Compose health waiting, run normal migrations/seeds/ECDICT against only the dedicated PC-test database, then start the Web service and prove loopback health before validating hidden setup/login.
