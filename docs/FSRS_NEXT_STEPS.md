# FSRS Phase 1 Next Steps

## Resume checklist

At the start of the next run, read:

- `docs/FSRS_PHASE1_STATUS.md`
- `docs/FSRS_NEXT_STEPS.md`
- `manual/FSRS Review.md`

Then continue Phase 1 only.

## Next stage

Stage H is complete. First-stage word-only FSRS validation is ready to report.

## Next action

Run the next checks from `D:\Document\lingl\LinguaCafe-main`:

```powershell
git status
```

No further action should be taken in this run unless the user explicitly asks to continue beyond the first-stage report.

User allowed continuing stage work without stopping only because usage is unavailable. Keep writing handoff logs at every stage.

## Manual system steps likely required for local Windows

The following must be handled outside the current Codex run if local Docker/WSL is required:

- Repair or enable Windows optional features for:
  - `Microsoft-Windows-Subsystem-Linux`
  - `VirtualMachinePlatform`
- Reboot Windows after enabling virtualization/WSL features.
- Reinstall or repair Docker Desktop.
- Confirm:
  - `wsl --status`
  - `docker info`

Current DISM output says those feature names are unknown, so this may require a Windows image/feature store repair, a different Windows image, or a remote Linux environment.

`winget search --id Microsoft.WSL` finds a WSL package, but installing it alone is not enough unless the Windows optional features can also be enabled and the system can reboot.

The WSL package was installed successfully, but `wsl --status` still fails with `Wsl/0x8007041d` because `WSLService` cannot start.

## Stage G result

Native `fsrs-rs-php` was compiled and installed into local PHP configuration. PHP now loads the extension by default, and the Laravel service layer produced native scheduling output with fallback disabled:

- `extension_loaded('fsrs-rs-php')`: `true`
- `class_exists('\fsrs\FSRS')`: `true`
- `.env`: `FSRS_ALLOW_INTERNAL_FALLBACK=false`
- `App\Services\FsrsSchedulingService::schedule(...)` returned FSRS stability and difficulty values.
- Composer succeeded with Windows-only ignores for `ext-pcntl` and `ext-posix`.
- Migrations succeeded.
- `review_cards` and `review_logs` have `language_id`.
- `reviews:initialize-cards --dry-run` and `reviews:initialize-cards` succeeded against real MariaDB data.
- FSRS-specific tests passed:
  - `FsrsSchedulingServiceTest`: 3 passed.
  - `ReviewFsrsTest`: 5 passed.
- Full suite still has 7 unrelated Auth/homepage failures.
- `npm run development` passed.
- Browser Review page loaded 4 due word cards.
- Again / Hard / Good / Easy were clicked through the UI.
- `review_cards` updated and `review_logs` wrote 4 rows.
- `VocabularyService::buildSearchRequest()` was not changed by the final `language_id` fix.

## Stage H result

First-stage word-only FSRS is usable in the local Windows PHP/MariaDB environment with native `fsrs-rs-php` loaded and fallback disabled.

Do not enter Phase 2 until the user explicitly approves it. The next phase would be sense-level groundwork, but it has not been started here.

Expected local tool versions from Stage B:

```powershell
php -v
composer --version
rustc --version
cargo --version
mariadb --version
node -v
npm -v
```

Known local versions:

- PHP `8.2.31`
- Composer `2.10.1`
- Rust/Cargo `1.96.0`
- MariaDB `12.3.2`
- Node `22.16.0`
- npm `10.9.4`

If a remote Linux environment becomes available, run equivalent:

```bash
uname -a
php -v || true
composer --version || true
rustc --version || true
cargo --version || true
mysql --version || mariadb --version || true
node -v || true
npm -v || true
```

## Hard boundaries

- Do not enter Phase 2.
- Do not create `word_senses`.
- Do not do sense mapping.
- Do not do Quicker automation.
- Do not treat `FSRS_ALLOW_INTERNAL_FALLBACK=true` as production FSRS.
- If `fsrs-rs-php` cannot build/load on Windows and no Linux environment is available, record Stage C as blocked instead of using fallback.

Stage C and Stage D are no longer blocked locally; use the native Windows extension for the remaining first-stage checks.

## Automation note

The heartbeat reminder has been updated to 04:44 and should continue from the current interrupted stage, not from a fixed named stage.
