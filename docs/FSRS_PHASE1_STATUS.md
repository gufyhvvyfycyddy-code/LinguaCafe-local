# FSRS Phase 1 Status

## Current phase

Stage G complete. Continue with Stage H: write the final first-stage acceptance report.

## Completed

- Read `manual/FSRS Review.md`.
- Resumed and re-read `docs/FSRS_PHASE1_STATUS.md`, `docs/FSRS_NEXT_STEPS.md`, and `manual/FSRS Review.md`.
- Updated the 04:43 heartbeat reminder so it says to continue from the current interrupted stage, not from a fixed stage.
- Confirmed `docs/FSRS_PHASE1_STATUS.md` and `docs/FSRS_NEXT_STEPS.md` did not exist before this handoff.
- Checked local Windows support for WSL, Docker, package managers, and administrator permissions.
- Checked Docker Desktop installation log and DISM feature availability.
- Checked whether the current Windows image exposes any alternative WSL, virtualization, Hyper-V, or container optional feature names.
- Confirmed `Microsoft.WSL` is available from winget, but this alone does not solve the missing Windows optional features reported by DISM.
- Confirmed the official `fsrs-rs-php` README documents Linux-style installation using a compiled `.so` extension, reinforcing that a Linux-compatible validation environment is required for production-like verification.
- Installed the Microsoft WSL package with winget to see whether `wsl.exe` could be restored.
- Confirmed `C:\Program Files\WSL\wsl.exe` now exists, but WSL commands fail with `Wsl/0x8007041d`.
- Confirmed `WSLService` is installed but cannot start; Service Control Manager reports timeout/error `1053`.
- Started Docker Desktop after WSL package installation; Docker client exists but Docker daemon is unavailable.
- Confirmed Docker Desktop installation is incomplete because required registry keys and services are missing.
- Confirmed the project has official Docker Compose files:
  - `docker-compose.yml`
  - `docker-compose-dev.yml`
  - `docker-compose-dev-macos.yml`

## Commands run

- Checked existing handoff docs:
  - `Test-Path docs\FSRS_PHASE1_STATUS.md`
  - `Test-Path docs\FSRS_NEXT_STEPS.md`
- Read FSRS manual:
  - `Get-Content manual\FSRS Review.md`
- Checked tools and permissions:
  - `Get-Command wsl`
  - `Get-Command docker`
  - `Get-Command docker-compose`
  - `Get-Command winget`
  - `Get-Command choco`
  - `Get-Command scoop`
  - Windows administrator role check
- Checked Windows environment:
  - `Get-ComputerInfo`
- Checked project Docker files:
  - `Get-ChildItem -Force`
- Checked Docker daemon:
  - `docker info`
- Checked WSL and virtualization Windows features:
  - `dism.exe /online /Get-FeatureInfo /FeatureName:Microsoft-Windows-Subsystem-Linux`
  - `dism.exe /online /Get-FeatureInfo /FeatureName:VirtualMachinePlatform`
- Checked Docker Desktop install log:
  - `Get-Content C:\ProgramData\DockerDesktop\install-log-admin.txt -Tail 80`
- Checked residual Docker and WSL paths:
  - `Test-Path C:\Program Files\Docker\Docker\Docker Desktop.exe`
  - `Test-Path C:\Program Files\Docker\Docker\resources\bin\docker.exe`
  - `Test-Path C:\Windows\System32\wsl.exe`
- Checked Codex status:
  - `get_goal`
- Checked optional feature names:
  - `dism.exe /online /Get-Features /Format:Table | Select-String -Pattern 'Linux|Subsystem|Virtual|Hyper|Container|Machine|WSL'`
  - `Get-WindowsOptionalFeature -Online | Where-Object { $_.FeatureName -match 'Linux|Subsystem|Virtual|Hyper|Container|Machine|WSL' }`
- Checked remote access tools:
  - `Get-Command ssh`
  - searched environment variables for remote/Linux host hints
- Checked winget WSL package:
  - `winget search --id Microsoft.WSL --accept-source-agreements`
- Installed winget WSL package:
  - `winget install --id Microsoft.WSL --accept-package-agreements --accept-source-agreements --silent`
- Checked WSL after installation:
  - `wsl --status`
  - `wsl --list --online`
  - `Get-Service WSLService`
  - `Start-Service WSLService`
- Checked Docker Desktop after WSL installation:
  - `Start-Process C:\Program Files\Docker\Docker\Docker Desktop.exe`
  - `C:\Program Files\Docker\Docker\resources\bin\docker.exe info`
  - `Get-Service | Where-Object { Docker }`
  - `Get-Process | Where-Object { Docker }`
  - `Get-Content $env:LOCALAPPDATA\Docker\log\host\Docker Desktop.exe.log -Tail 120`

## Command results

- `winget` exists.
- `choco` and `scoop` are not installed.
- Current shell has administrator permission.
- `wsl` is not available in PATH, and `C:\Windows\System32\wsl.exe` does not exist.
- `docker` is not available in PATH.
- Docker Desktop files exist under `C:\Program Files\Docker\Docker`, but Docker Desktop installation is incomplete and no Docker daemon is available.
- `docker info` could not run because the Docker command is unavailable in PATH.
- DISM reports both required feature names as unknown:
  - `Microsoft-Windows-Subsystem-Linux`
  - `VirtualMachinePlatform`
- Docker Desktop install log confirms installation failed while enabling `VirtualMachinePlatform` and `Microsoft-Windows-Subsystem-Linux`.
- `Get-ComputerInfo` reports Windows 10 Pro build `26100`, 64-bit, `HyperVisorPresent: False`.
- Codex status did not expose remaining token/context usage: `remainingTokens` is `null`.
- DISM feature listing only surfaced `MSMQ-Container`; it did not show WSL, VirtualMachinePlatform, Hyper-V, or usable container features.
- `Get-WindowsOptionalFeature -Online` failed with `Value cannot be null. Parameter name: path1` in this environment.
- `ssh.exe` exists, but no remote Linux host details were found in environment variables.
- `winget search --id Microsoft.WSL` found:
  - `Microsoft.WSL` version `2.7.8`
  - `Microsoft.WSL.PreRelease` version `2.7.0`
- Installing the winget WSL package is not sufficient by itself unless the underlying Windows optional features can also be enabled and the machine can reboot.
- `winget install --id Microsoft.WSL` succeeded and installed WSL `2.7.8`.
- `wsl.exe` is now available at `C:\Program Files\WSL\wsl.exe`.
- `wsl --status` and `wsl --list --online` fail with `Wsl/0x8007041d`.
- `WSLService` remains stopped after `Start-Service`; event log reports service startup timeout/error `1053`.
- Docker client reports version `29.5.3`, but cannot connect to `npipe:////./pipe/docker_engine`.
- No Docker service or Docker process is running.
- Docker Desktop log reports missing registry key `SOFTWARE\Docker Inc.\Docker Desktop`, confirming the previous failed installation left only partial files.

## Modified files

- Created `docs/FSRS_PHASE1_STATUS.md`.
- Created `docs/FSRS_NEXT_STEPS.md`.

## Current blocker

Local Stage A is blocked. This Windows image cannot currently provide WSL/Docker because required Windows optional features are missing or unavailable to DISM. Installing the Microsoft WSL package restored `wsl.exe`, but WSLService cannot start and WSL commands fail with `Wsl/0x8007041d`. The machine has Docker Desktop files from a failed install, but no usable Docker daemon.

The user updated the working rule: continue running stages and write handoff logs at each stage; do not pause solely because token/context usage is unavailable.

## Can continue Phase 1?

Yes, Phase 1 can continue, but local Linux/Docker-native validation is blocked until one of these is provided:

- use a manually prepared Linux/WSL/Docker environment,
- use a remote Linux machine,
- repair/reinstall Windows optional features and Docker Desktop after reboot.

Do not enter Phase 2. Do not add sense support. Do not do Quicker automation.

## Latest pause

Stage A handoff written after confirming local WSL/Docker is blocked. The 04:44 heartbeat is active and should continue from the current interrupted stage if the task is still incomplete.

## Stage B handoff

### Completed

- Completed local dependency inventory and installation.
- Confirmed PHP, Composer, Rust, Cargo, MariaDB, Node, and npm are available locally.
- Installed Rustup with winget. The winget command timed out, but Rustup was installed.
- Configured Rust stable with the `rsproxy.cn` mirror because the default Rust download host timed out.
- Confirmed MariaDB server is running and database `linguacafe_fsrs` exists.

### Commands run

- `php -v`
- `composer --version`
- `node -v`
- `npm -v`
- `mariadb --version`
- `rustc --version`
- `cargo --version`
- `winget search --id Rustlang.Rustup --accept-source-agreements`
- `winget install --id Rustlang.Rustup --accept-package-agreements --accept-source-agreements --silent`
- `rustup default stable`
- `$env:RUSTUP_DIST_SERVER='https://rsproxy.cn'; $env:RUSTUP_UPDATE_ROOT='https://rsproxy.cn/rustup'; rustup default stable`
- `php -m`
- `mariadb.exe --user=root --password=root --host=localhost --port=3309 --execute="SELECT VERSION() AS version; SHOW DATABASES LIKE 'linguacafe_fsrs';"`

### Command results

- PHP CLI: `8.2.31`.
- Composer: `2.10.1`.
- Rust: `rustc 1.96.0`.
- Cargo: `1.96.0`.
- MariaDB: `12.3.2`.
- Node: `22.16.0`.
- npm: `10.9.4`.
- MariaDB database `linguacafe_fsrs` is reachable on port `3309`.
- PHP modules include common Laravel requirements: `curl`, `fileinfo`, `gd`, `intl`, `mbstring`, `openssl`, `pdo_mysql`, `pdo_sqlite`, `sqlite3`, and `zip`.
- First `rustup default stable` failed against `static.rust-lang.org` with timeout `os error 10060`.
- Retried with `rsproxy.cn` mirror and installed `stable-x86_64-pc-windows-msvc`.

### Modified files

- Updated `docs/FSRS_PHASE1_STATUS.md`.
- Updated `docs/FSRS_NEXT_STEPS.md`.

### Current blocker

Local dependencies are available, but local Linux/Docker remains blocked. Stage C must determine whether `fsrs-rs-php` can be built and loaded on Windows. If it only supports Linux `.so` builds, native production verification is blocked until a Linux/WSL/Docker/remote Linux environment is available.

### Next step

Stage C: inspect, compile, and try to load native `fsrs-rs-php` without `FSRS_ALLOW_INTERNAL_FALLBACK`.

## Stage C handoff

### Completed

- Cloned upstream `open-spaced-repetition/fsrs-rs-php` into the local temp directory.
- Inspected upstream build shape and confirmed it is an `ext-php-rs` PHP extension exposing `fsrs\FSRS`.
- Installed the missing Windows build pieces required to compile the extension locally:
  - Windows SDK `10.0.18362`
  - `msvc-kit` MSVC compiler files
  - LLVM/libclang support via the Python `libclang==16.0.6` package
  - Rust nightly toolchain
- Built `fsrs-rs-php` as a native Windows PHP extension.
- Installed the resulting `fsrs_rs_php.dll` into PHP's extension directory.
- Backed up `php.ini` and added `extension=fsrs_rs_php.dll`.
- Confirmed PHP now loads the native extension by default.

### Commands run

- `git clone https://github.com/open-spaced-repetition/fsrs-rs-php.git $env:TEMP\fsrs-rs-php`
- `cargo build`
- `winget install --id Microsoft.WindowsSDK.10.0.18362 --silent`
- `winget install --id loonghao.msvc-kit --silent`
- `python -m pip install --user libclang==16.0.6`
- `rustup toolchain install nightly`
- `cargo +nightly build`
- `php -d extension=C:\Users\Administrator\AppData\Local\Temp\fsrs-rs-php\target\debug\fsrs_rs_php.dll -r "..."`
- Copied `fsrs_rs_php.dll` into the PHP `ext` directory.
- Added `extension=fsrs_rs_php.dll` to PHP's active `php.ini`.
- `php -m`
- `php -r "var_dump(extension_loaded('fsrs-rs-php')); var_dump(class_exists('\\fsrs\\FSRS')); print_r(get_extension_funcs('fsrs-rs-php'));"`.

### Command results

- Native extension built successfully at:
  - `C:\Users\Administrator\AppData\Local\Temp\fsrs-rs-php\target\debug\fsrs_rs_php.dll`
- PHP now reports:
  - extension module: `fsrs-rs-php`
  - `extension_loaded('fsrs-rs-php')`: `true`
  - `class_exists('\fsrs\FSRS')`: `true`
  - exported functions include `simulate`, `default_simulator_config`, and `get_default_parameters`
- The extension is currently loaded from PHP configuration, not from a one-off command-line override.
- Stage-end `git status` was run and failed because this checkout does not contain a `.git` directory:
  - `fatal: not a git repository (or any of the parent directories): .git`

### Modified files

- Updated PHP config outside the repository:
  - active `php.ini`
  - copied `fsrs_rs_php.dll` into PHP's `ext` directory
- Updated `docs/FSRS_PHASE1_STATUS.md`.
- Updated `docs/FSRS_NEXT_STEPS.md`.

### Notes

- Upstream `fsrs-rs-php` did not build on stable Rust on Windows because `ext-php-rs` uses `abi_vectorcall`.
- A local temporary patch was applied outside the LinguaCafe repository to the cloned upstream source so nightly Rust could compile it:
  - `C:\Users\Administrator\AppData\Local\Temp\fsrs-rs-php\src\lib.rs`
- No LinguaCafe production code was changed for this build workaround.
- Local WSL/Docker remains blocked, but Windows native PHP can now load native FSRS.

### Next step

Stage D: ensure `FSRS_ALLOW_INTERNAL_FALLBACK` is disabled and verify Laravel sees the native extension.

## Stage D handoff

### Completed

- Confirmed PHP loads native `fsrs-rs-php` by default.
- Confirmed shell-level `FSRS_ALLOW_INTERNAL_FALLBACK` is unset.
- Updated local `.env` for local verification:
  - `APP_ENV=local`
  - `APP_DEBUG=true`
  - database changed to local MariaDB on `127.0.0.1:3309`
  - `QUEUE_CONNECTION=sync`
  - `FSRS_ALLOW_INTERNAL_FALLBACK=false`
- Cleared Laravel config and application cache.
- Verified Laravel is running in the `local` environment.
- Called `App\Services\FsrsSchedulingService` directly with fallback disabled and received native FSRS scheduling output.

### Commands run

- `php -r "echo getenv('FSRS_ALLOW_INTERNAL_FALLBACK'); var_dump(extension_loaded('fsrs-rs-php')); var_dump(class_exists('\\fsrs\\FSRS'));"`
- `Select-String -Path .env -Pattern 'FSRS|APP_ENV|DB_'`
- backed up `.env` to `.env.bak-fsrs-*`
- `php artisan config:clear`
- `php artisan cache:clear`
- `php artisan env`
- direct PHP service call through Laravel bootstrap:
  - `App\Services\FsrsSchedulingService::schedule(...)`

### Command results

- `FSRS_ALLOW_INTERNAL_FALLBACK` shell env: unset.
- `.env` now explicitly sets `FSRS_ALLOW_INTERNAL_FALLBACK=false`.
- `extension_loaded('fsrs-rs-php')`: `true`.
- `class_exists('\fsrs\FSRS')`: `true`.
- `php artisan env`: `local`.
- Native service call with rating `good` returned:
  - state: `review`
  - due date: `2026-06-20 02:00:00`
  - stability: `3.1730000972747803`
  - difficulty: `5.282434463500977`

### Modified files

- Updated `.env` for local verification.
- Updated `docs/FSRS_PHASE1_STATUS.md`.
- Updated `docs/FSRS_NEXT_STEPS.md`.

### Current blocker

No D-stage blocker. Docker/WSL remains blocked, but local PHP now has native FSRS.

### Next step

Stage E: run Composer install, migrations, verify `review_cards` and `review_logs`, then run `reviews:initialize-cards --dry-run` and `reviews:initialize-cards`.

## Stage E handoff

### Completed

- Ran Composer dependency installation.
- Plain `composer install` failed on Windows because `laravel/horizon` requires the Unix-only `ext-pcntl` PHP extension.
- Reran Composer while ignoring only `ext-pcntl` and `ext-posix`; install succeeded.
- Ran Laravel migrations.
- Found and fixed a first-stage schema gap: Review tables had `language` but not the requested `language_id`.
- Added `language_id` to `review_cards` and `review_logs`.
- Updated Review-only code so cards/logs write both `language` and `language_id`, while queue/rating isolation uses `language_id`.
- Verified `review_cards` and `review_logs` exist with FSRS fields and `language_id` indexes.
- Created minimal real test data in MariaDB because the database had no initial learning words.
- Ran `reviews:initialize-cards --dry-run`, then `reviews:initialize-cards`, then dry-run again.
- Confirmed dry-run does not write data, initialization writes only learning word cards, and repeated initialization does not duplicate cards.
- Confirmed ignored, known, and new words did not get cards.
- Confirmed user and language isolation in initialized cards.

### Commands run

- `composer install`
- `composer install --no-interaction --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix`
- `php artisan migrate --force`
- MariaDB schema checks:
  - `SHOW TABLES LIKE 'review_%'`
  - `SHOW COLUMNS FROM review_cards`
  - `SHOW INDEX FROM review_cards`
  - `SHOW COLUMNS FROM review_logs`
  - `SHOW INDEX FROM review_logs`
- `php artisan reviews:initialize-cards --dry-run`
- `php artisan reviews:initialize-cards`
- Created minimal validation data through Laravel bootstrap.
- Queried initialized card counts grouped by user and language.

### Command results

- Composer succeeded with Windows-only platform ignores for `pcntl` and `posix`.
- Migration result:
  - `2026_06_17_000004_add_language_id_to_review_tables ... DONE`
- `review_cards` has these verified fields:
  - `user_id`
  - `language_id`
  - `language`
  - `target_type`
  - `target_id`
  - `fsrs_enabled`
  - `fsrs_due_at`
  - `fsrs_state`
  - `fsrs_stability`
  - `fsrs_difficulty`
  - `fsrs_reps`
  - `fsrs_lapses`
- `review_logs` has `language_id` and the requested previous/new FSRS snapshot fields.
- Verified indexes:
  - `review_cards_user_language_id_target_unique`
  - `review_cards_language_id_due_lookup_index`
  - `review_logs_user_language_id_reviewed_index`
- Initial empty database result:
  - dry-run: `0`
  - actual initialization: `0`
- After inserting minimal validation data:
  - dry-run: `4 review cards would be created`
  - actual initialization: `Created 4 review cards`
  - second dry-run: `0 review cards would be created`
- Initialized card distribution:
  - `fsrs-native-a@example.com` / `english`: `2`
  - `fsrs-native-a@example.com` / `spanish`: `1`
  - `fsrs-native-b@example.com` / `english`: `1`
- No card was created for:
  - ignored word (`stage = 1`)
  - known word (`stage = 0`)
  - new word (`stage = 2`)
- Stage-end `git status` was run and failed because this checkout does not contain a `.git` directory:
  - `fatal: not a git repository (or any of the parent directories): .git`

### Modified files

- Added `database/migrations/2026_06_17_000004_add_language_id_to_review_tables.php`.
- Updated `app/Models/ReviewCard.php`.
- Updated `app/Models/ReviewLog.php`.
- Updated `app/Services/ReviewCardService.php`.
- Updated `app/Services/ReviewService.php`.
- Updated `app/Models/Goal.php`.
- Updated `.env` during Stage D for local validation.
- Updated `docs/FSRS_PHASE1_STATUS.md`.
- Updated `docs/FSRS_NEXT_STEPS.md`.

### Current blocker

No E-stage blocker. Docker/WSL remains unavailable, so validation is proceeding on local Windows PHP/MariaDB with native `fsrs-rs-php` loaded.

### Next step

Stage F: run FSRS-specific tests first, then `php artisan test`. If failures are unrelated old failures, record them explicitly; if failures touch FSRS, fix them.

## Stage F handoff

### Completed

- Ran FSRS scheduling unit tests.
- Ran Review FSRS feature tests.
- Ran the full Laravel test suite.
- Confirmed all FSRS-specific tests pass with native `fsrs-rs-php` loaded.
- Confirmed the full suite has 7 failures that are outside the FSRS/Review path.

### Commands run

- `php artisan test --filter=FsrsSchedulingServiceTest`
- `php artisan test --filter=ReviewFsrsTest`
- `php artisan test`

### Command results

- `FsrsSchedulingServiceTest`: `3 passed`, `10 assertions`.
- `ReviewFsrsTest`: `5 passed`, `92 assertions`.
- Full suite: `11 passed`, `7 failed`, `113 assertions`.
- FSRS-related tests passed inside the full suite as well.
- Stage-end `git status` was run and failed because this checkout does not contain a `.git` directory:
  - `fatal: not a git repository (or any of the parent directories): .git`

### Full-suite failures recorded

- `Tests\Feature\Auth\AuthenticationTest::users_can_authenticate_using_the_login_screen`
  - Expected `204`, got `200`.
- `Tests\Feature\Auth\EmailVerificationTest::email_can_be_verified`
  - Missing route `verification.verify`.
- `Tests\Feature\Auth\EmailVerificationTest::email_is_not_verified_with_invalid_hash`
  - Missing route `verification.verify`.
- `Tests\Feature\Auth\PasswordResetTest::reset_password_link_can_be_requested`
  - Expected password reset notification was not sent.
- `Tests\Feature\Auth\PasswordResetTest::password_can_be_reset_with_valid_token`
  - Expected password reset notification was not sent.
- `Tests\Feature\Auth\RegistrationTest::new_users_can_register`
  - User was not authenticated.
- `Tests\Feature\ExampleTest::the_application_returns_a_successful_response`
  - Expected `200`, got redirect `302`.

### Modified files

- Updated `docs/FSRS_PHASE1_STATUS.md`.
- Updated `docs/FSRS_NEXT_STEPS.md`.

### Current blocker

No FSRS blocker in Stage F. The full test suite is not green because of existing Auth/homepage tests unrelated to the word-only FSRS path.

### Next step

Stage G: start the app, open the Review page in a browser, verify due word cards load, click Again / Hard / Good / Easy, and confirm `review_cards` updates and `review_logs` rows are written.

## Stage G handoff

### Completed

- Ran `npm run development`.
- Confirmed the local Laravel site responds at `http://127.0.0.1:8000`.
- Restarted the stale Laravel serve process so it used the current `.env` and database state.
- Created a UI validation user and four due word cards.
- Logged into the browser as `fsrs-ui@example.com`.
- Opened the Review page.
- Confirmed the Review page loaded 4 due word cards.
- Clicked `Reveal`.
- Clicked all four rating buttons through the UI:
  - `Again`
  - `Hard`
  - `Good`
  - `Easy`
- Confirmed the page advanced after each rating and showed the completion screen after 4 cards.
- Confirmed `review_cards` was updated for all 4 cards.
- Confirmed `review_logs` contains 4 rows with previous/new FSRS snapshots.
- Confirmed static frontend path: Review sends only `reviewCardId` and `rating` to `/reviews/rate`; FSRS computation stays on the backend.
- Confirmed static export path: `VocabularyService::buildSearchRequest()` was not changed by the `language_id` fix, and the FSRS export test passed in Stage F.

### Commands run

- `npm run development`
- `php artisan serve --host=127.0.0.1 --port=8000` through a hidden local process
- browser login at `http://127.0.0.1:8000/login`
- browser navigation to `http://127.0.0.1:8000/review`
- database checks for `review_cards` and `review_logs`
- static checks:
  - `resources/js/components/Review/Review.vue`
  - `app/Services/VocabularyService.php`
  - `app/Services/AnkiApiService.php`
  - `app/Http/Controllers/AnkiController.php`

### Command results

- `npm run development`: compiled successfully.
- Sass emitted deprecation warnings from existing Bootstrap/Sass imports, but compilation succeeded.
- Browser Review load:
  - page showed 4 due cards.
  - first visible card: `ui-again`.
- UI rating flow:
  - `Again` advanced page to 3 remaining cards.
  - `Hard` advanced page to 2 remaining cards.
  - `Good` advanced page to 1 remaining card.
  - `Easy` advanced page to completion.
- Final page text:
  - `Congratulations!`
  - `You have finished reviewing 4 cards.`
- Database after UI ratings:
  - all 4 cards have `fsrs_reps = 1`
  - `Again` card has `fsrs_lapses = 1`
  - all 4 cards have non-null `fsrs_stability`, `fsrs_difficulty`, `fsrs_due_at`, and `fsrs_last_reviewed_at`
  - `review_logs` has 4 rows with ratings `again`, `hard`, `good`, and `easy`
  - each log has `previous_state`, `new_state`, `previous_due_at`, `new_due_at`, `new_stability`, `new_difficulty`, `source = review`, and `language_id = english`
- Stage-end `git status` was run and failed because this checkout does not contain a `.git` directory:
  - `fatal: not a git repository (or any of the parent directories): .git`

### Modified files

- Updated `docs/FSRS_PHASE1_STATUS.md`.
- Updated `docs/FSRS_NEXT_STEPS.md`.

### Current blocker

No Stage G blocker. First-stage UI flow is usable on local Windows PHP/MariaDB with native `fsrs-rs-php`.

### Next step

Stage H: final report only. Do not enter Phase 2, do not add sense support, and do not do Quicker.

## Stage H handoff

### Completed

- Prepared final first-stage acceptance report.
- Did not enter Phase 2.
- Did not add `word_senses`.
- Did not add sense mapping.
- Did not do Quicker automation.

### Current status

First-stage word-only FSRS is usable in this local verification environment:

- native `fsrs-rs-php` loads in PHP
- fallback is disabled
- migrations run
- `review_cards` and `review_logs` exist with `language_id`
- initialization command works with `--dry-run`
- Review page loads due word cards
- Again / Hard / Good / Easy update cards and write logs
- FSRS tests pass
- frontend build passes

### Remaining caveats

- Docker/WSL remains unavailable on this Windows image.
- Plain Windows `composer install` fails because Horizon requires Unix-only `ext-pcntl`; Windows verification used Composer's `--ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix`.
- Full Laravel test suite still has 7 unrelated Auth/homepage failures.
- `git status` cannot run because this checkout has no `.git` directory.
