# HANDOFF-04 — WebView2 auto-admin ready — 2026-09-02

## Completed

- Fixed the real first-run setup bug: Vuetify disables the create-account button while the form is empty, so the old automation could never fill the form because it checked `button.disabled` too early.
- Both `/setup` and `/login` now use the same two-phase contract: locate fields → set native input values + dispatch `input/change` → wait for Vue to enable submit → click.
- Added startup diagnostics at `%LOCALAPPDATA%\LinguaCafePCTest\startup.log`; full exception stack is recorded without admin password.
- Added an explicit ready marker after authentication: `PC 测试版已就绪：/`.

## Real Windows evidence

- FastCtx/service-launched WPF runs in Windows session 0 and WebView2 can fail with `0x80070578` because it has no valid interactive HWND. This is a validation-host limitation, not a product workaround target.
- A temporary Windows Task Scheduler `InteractiveToken` launch placed the same EXE in Administrator console session 1. The temporary task definition was deleted after launch and is not a product dependency.
- First interactive run created `pc-test-admin@local.invalid`; DB evidence: `is_admin=1`.
- `state.json`: `AdminProvisioned=true`.
- The process remained alive across the full automation timeout with no ERROR.
- Latest interactive startup log reached `PC 测试版已就绪：/`.

## Guard / build

- `WindowsPcTestBuildGuard.test.mjs` prohibits the old pre-fill disabled-button check and requires both flows to wait after filling.
- WPF Release build: 0 warnings / 0 errors.
- `git diff --check`: PASS.

## Status

- I-02: DONE.
- Next: I-03 package exact Goal snapshot + executable and create the desktop shortcut.
