# HANDOFF-05 — Exact build + desktop shortcut — 2026-09-02

## Completed

- Final installed PC test build is based on exact Goal commit `1fe0fae9d6308118bcf20af7674853d74112aa6a`.
- Installer publishes the WPF shell to staging, creates `runtime-source.zip` from the same Git HEAD, writes the same commit to `runtime-version.txt`, prebuilds Web/Python Docker images tagged with that commit, verifies both images exist, and only then replaces the previous installed app.
- Desktop shortcut exists at `C:\Users\Administrator\Desktop\LinguaCafe PC Test.lnk` and resolves to `%LOCALAPPDATA%\LinguaCafePCTest\app\LinguaCafe PC Test.exe`.

## Real Windows evidence

- Shortcut itself was launched through a temporary Windows Task Scheduler `InteractiveToken` task; the task definition was deleted immediately after launch and is not a product dependency.
- Installed process PID `64320` ran in console session 1 and remained responsive.
- Startup log reached `PC 测试版已就绪：/`.
- Startup log showed runtime reuse for `1fe0fae9d630...`; the user-facing launch did not rebuild the Rust native extension.
- Running containers use exact images:
  - `linguacafe-pc-test-web:1fe0fae9d6308118bcf20af7674853d74112aa6a`
  - `linguacafe-pc-test-python:1fe0fae9d6308118bcf20af7674853d74112aa6a`
- MySQL/Redis remain the dedicated persistent `linguacafe-pc-test` services.

## Packaging reliability

- Packaging refuses tracked or untracked source changes.
- A failed publish/archive/image-build cannot delete the previous installed app because replacement happens only after the staging package and exact-tag images are complete.
- Missing local ECDICT no longer resolves to a hard-coded Administrator path; the Compose fallback is a repository-owned empty placeholder, while a real local ECDICT is mounted read-only when present.

## Status

- I-03: DONE.
- Next: I-04 real PC smoke through the installed executable: Home → Library → import/Reader → Sense Review → WordSense → Admin/settings.
