# LinguaCafe Windows PC Test Build Plan — 2026-09-01

## Purpose

The A–H roadmap has reached the remaining Apple physical/signing/distribution boundary. Windows PC test work is an independent product-validation lane that can continue without pretending H-10/H-GATE are complete.

This lane gives the product owner a real Windows desktop executable for daily hands-on testing. It reuses the canonical Laravel/Vue application instead of creating a second desktop business implementation.

## Current checkpoint — 2026-09-02

- Production Docker build blocker closed: Windows patch line endings are fixed and the PHP 8.4 FSRS dependency graph is now pinned by repository `Cargo.lock` + `cargo build --locked`.
- Real Windows build of `linguacafe-pc-test-web:latest` passes through native FSRS compilation, Laravel Mix, Composer and image export.
- First-run admin DOM selector was corrected after adversarial review; Compose startup now uses health-aware `--wait` instead of racing MySQL readiness.
- I-01 is DONE: dedicated MySQL/Redis/storage were initialized, all normal migrations/seeds completed against `linguacafe_pc_test`, ECDICT is healthy at 768,739 rows, and the loopback Web runtime returns HTTP 200 without touching existing H-07 containers.
- I-02 is DONE: the WPF/WebView2 shell was launched in the real interactive Windows console session, created the dedicated administrator through canonical `/setup`, logged in through canonical `/login`, and reached ready path `/`.
- I-03 is DONE: exact build `1fe0fae9d6308118bcf20af7674853d74112aa6a` is installed under `%LOCALAPPDATA%/LinguaCafePCTest/app`; the desktop shortcut launches the installed executable; Web/Python images are prebuilt with the same commit tag so ordinary launch reuses the runtime instead of compiling FSRS.
- I-04 is DONE: installed PC-test runtime completed real Home -> Library/import -> Reader/ECDICT -> WordSense -> Sense Review Good -> User Settings -> Admin smoke; browser/API/database evidence agrees and no console warning/error was observed.
- I-05 is the only active PC lane: no product-owner runtime feedback has been reported yet. Until there is real hands-on feedback, do not manufacture defects, repeat the accepted main-flow smoke, or expand into public Windows installer/signing/update work.
- Packaging rejects tracked or untracked Git source changes, stages the complete new build before replacing the previous installed app, and uses a repository-local empty ECDICT placeholder when no real CSV is available instead of a user-specific hard-coded path.
- Step handoffs: `HANDOFF-01` through `HANDOFF-06` under `docs/handoffs/windows-pc-test/` record each completed checkpoint.

## Product contract

- Windows PC test is English-learning LinguaCafe with the same Laravel/Vue owners as Web.
- The first PC test release uses a dedicated local Docker runtime and dedicated persistent MySQL/Redis/storage volumes.
- It must not read/write the normal development, acceptance, staging, or production database.
- The desktop shell hides setup/login and automatically enters a dedicated administrator account, but the server still uses the canonical `/users/create` and `/login` authentication paths. No production authentication bypass route is added.
- The administrator password exists only in the current Windows user's local PC-test state, not in Git, `.env`, reports, screenshots, or shell command arguments.
- First-run ECDICT import may use the existing local `linguacafe_ecdict_en_zh_pipe.csv` read-only mount. If unavailable, the application may still start with the existing English fallback, but the missing dictionary must be shown as a test-build limitation.
- The initial desktop container stack is bound to loopback only. It is a local product test surface, not a public deployment.
- Every user-reported PC experience issue goes into `docs/testing/windows-pc-test-feedback-log.md`; fixes remain open until the product owner has had a chance to retest the rebuilt executable.

## Architecture

### Desktop shell

`desktop/windows-pc-test/` is a small .NET 8 WPF application using Microsoft's stable WebView2 package and the Windows Evergreen WebView2 runtime.

Responsibilities:

1. check/start Docker Desktop;
2. unpack the exact Git snapshot bundled with the PC build;
3. build/start the dedicated local Docker stack;
4. run normal migrations/seeds only against the dedicated PC-test database;
5. ensure ECDICT is available when the known local CSV exists;
6. use a hidden WebView2 page to create the first administrator through `/setup`, then log in through `/login`;
7. reveal the ordinary LinguaCafe UI only after authentication succeeds.

The shell does not own study data, FSRS, Reader, dictionary search, backups, user management, or application routing.

### PC test runtime

`desktop/windows-pc-test/docker-compose.pc-test.yml` owns one isolated Compose project:

- MySQL 8.4 persistent PC-test volume;
- Redis 7.2 persistent PC-test volume;
- current tokenizer image;
- current production Web image;
- persistent app storage volume;
- loopback Web port `9391`;
- loopback Reverb port `6001`.

The runtime uses test-only database/reverb credentials and never reuses repository `.env` values.

## Packaging

`scripts/windows/install-pc-test.ps1` publishes the WPF executable, adds an exact `git archive` source snapshot and commit marker, and creates one desktop shortcut named `LinguaCafe PC Test`.

The installed build is deliberately a test build for the current Windows PC. Code signing, auto-update, installer distribution and public Windows release are outside this first lane.

## Phase I milestones

| ID | Status | Outcome | Exit evidence |
|---|---|---|---|
| I-00 | DONE | Freeze PC-test isolation/auth/feedback contract | This plan + adversarial architecture review |
| I-01 | DONE | Dedicated persistent PC Docker runtime | Compose health wait; normal migrate/seed against `linguacafe_pc_test`; ECDICT 768,739 / HEALTHY; loopback HTTP 200; existing H-07 containers unchanged |
| I-02 | DONE | Windows executable shell + hidden normal admin setup/login | `HANDOFF-04-WEBVIEW2-AUTO-ADMIN-READY-2026-09-02.md`; real console-session WebView2 launch; dedicated `is_admin=1`; `AdminProvisioned=true`; startup log reaches `PC 测试版已就绪：/` |
| I-03 | DONE | Package current Goal snapshot and desktop shortcut | `HANDOFF-05-EXACT-BUILD-DESKTOP-SHORTCUT-2026-09-02.md`; exact build `1fe0fae9...`; installed `.exe` + `runtime-source.zip` + commit marker + commit-tagged Web/Python images; real desktop `.lnk` reaches ready path `/` without rebuilding FSRS |
| I-04 | DONE | Real PC smoke | `HANDOFF-06-REAL-PC-MAIN-FLOW-SMOKE-2026-09-02.md`; real DOM flow Home → Library/import → Reader/ECDICT → WordSense → formal Sense Review Good → User Settings → Admin; 66 observed XHR/fetch=200; Console warn/error=0; DB counts reconcile 1 book/1 chapter/1 sense/1 card/1 log/1 source occurrence |
| I-05 | ACTIVE | Product-owner feedback loop | every reported issue recorded with build, expected/actual, disposition, fix commit and retest status |
| I-GATE | TODO | Product owner says the PC test build is stable enough for the next packaging decision | no unresolved blocker chosen as required for current PC test; feedback log reflects owner retest |

## Explicitly deferred

- public Windows installer/update channel;
- Windows code signing / SmartScreen reputation;
- replacing Docker with bundled MySQL/Redis/PHP/Python runtimes;
- offline-first full desktop authority;
- separate desktop business logic;
- multi-user public authentication design changes;
- Apple H-10/H-GATE closure.
