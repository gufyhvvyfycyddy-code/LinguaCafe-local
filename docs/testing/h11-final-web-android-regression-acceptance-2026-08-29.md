# H-11 Final Web + Android Regression Acceptance — 2026-08-29

## Verdict

**Accepted / DONE for every capability available on the current Windows host.**

The accepted H-11 product/runtime repair commit is:

`eaab88a7a7d96d2c1078b4b5243210430a305970` — `fix: close H-11 runtime regressions`

H-11 completed the final runnable Web + Android regression defined by the Goal plan. H-10 iOS remains **DEFERRED / Not Complete** because the current host still has no Xcode/xcrun/codesign/Apple signing capability and no available macOS peer. H-11 does not convert Windows static/iOS generated-Web checks into real iOS device or TestFlight evidence.

## Git / authority preflight

Fresh Git fetch during H-11 found:

- H-11 baseline / current Goal remote before the final repair commit: `214ce1f8f72a60265fdaa61082bb3725e94f50f3`;
- accepted H-11 product/runtime repair: `eaab88a7a7d96d2c1078b4b5243210430a305970`;
- `origin/master`: `1c9bdcd74fa793356ba3938f21c56405f3261e39`;
- the local worktree branch name remains historical/stale, while the pushed authority is `origin/goal/linguacafe-a-h-sol-medium-20260809`;
- no reset, rebase, merge, stash, clean, force push, or destructive database command was used.

## Production FSRS runtime repair

H-11 found a real release blocker after the PHP 8.4 runtime upgrade: the production PHP image did not yet carry a working native `fsrs-rs-php` extension, while product scheduling/optimization paths expect the native extension in the supported runtime.

The accepted solution keeps one production FSRS path:

- `docker/PhpDockerfile` builds the extension in isolated Rust/PHP builder stages;
- `fsrs-rs-php` is fetched at pinned commit `122299bc273ebecc07f5022b91939380951b5688`;
- the repository-tracked `docker/fsrs-rs-php-php84.patch` updates the upstream binding for the PHP 8.4-compatible `ext-php-rs` API;
- `ext-php-rs` is pinned to `0.15.15`;
- the generated Cargo lockfile is checked against a fixed SHA-256 before `cargo build --release --locked`;
- only `libfsrs_rs_php.so` is copied into the final PHP 8.4 Apache image;
- the final image loads the extension through `20-fsrs.ini` and performs a real scheduling smoke during image construction;
- FSRS, fsrs-rs-php and ext-php-rs redistribution notices are recorded in `THIRD_PARTY_NOTICES.md` and `LICENSES/FSRS-BSD-3-Clause.txt`.

A real production-image build completed successfully. The built image was then executed again after final review and reported:

- PHP `8.4.25`;
- native extension loaded: yes;
- `fsrs\FSRS` class available: yes;
- default parameters: 19;
- real `next_states` Good interval: about `3.173` days.

The five application owners plus the workflow doctor now use the canonical `class_exists(\fsrs\FSRS::class)` check. There is no second runtime scheduler or silent compatibility fallback added by H-11.

## Reader manual-Sense identity repair

The final Web regression reproduced a previously confirmed Reader defect: creating a manual Sense from a live Reader selection could reach Sense creation before the current reading occurrence identity was fully available.

H-11 keeps the existing reading owners and adds a bounded context handshake:

`WordSensesList` → `VocabularyBox` / `VocabularySideBox` → `TextBlockGroup` → `TextReader`.

Before the manual Sense write, the Reader now verifies the current selection fingerprint and obtains the current server-backed reading occurrence/session/source revision through the existing unfamiliar-target and ReadingSession owners. The old duplicate `marked_unknown` interaction write is no longer used as a second truth source.

Real browser evidence on the testing-only server included the following sequence on the same Reader chapter:

- reading session creation;
- server-confirmed unfamiliar target;
- reading interaction;
- `POST /senses/manual`;
- Finish settlement;
- Sense Review loading and interval preview;
- one formal Sense rating;
- daily report;
- WordSense list;
- source-context list.

The manual Sense repair therefore passed through the real page and HTTP flow rather than only a source/static guard.

## Real Web acceptance

The Web acceptance used the existing PAB + machine-global TestingDatabaseLease path and a real browser. API status alone was not used as acceptance.

The full flow exercised material creation/selection, Reader session creation, unfamiliar marking, manual Sense creation, Finish, Sense Review, formal rating, daily report, WordSense browsing/source context, and account surfaces.

A final exact 430 × 932 browser smoke then used visible navigation controls to enter:

- Home;
- 阅读 / Books;
- 复习 / Sense Review;
- 生词 / WordSense;
- 我的 / account settings.

All four ordinary-user primary entries remained reachable at the mobile Web viewport. The temporary testing account used for the last Android/Web cross-check was deleted through the visible Web `永久删除账号` flow and returned to Login.

The testing-only one-process PHP server emitted Reverb/WebSocket connection-refused console noise because Reverb was intentionally not started for that PAB server. Production ownership was checked separately: `config/supervisord.conf` starts `artisan reverb:start --port=6001`, and the production image runs supervisord. H-11 therefore did not add a second WebSocket launcher or change product code to hide a testing-environment omission.

## Real Android 16 acceptance

H-11 rebuilt/reused the current debug APK and installed it on the existing `LinguaCafeM7` Android 16 / API 36 emulator.

The final real device chain used visible Android/WebView controls and completed:

1. testing-only server address entry;
2. normal email/password login;
3. online Home with 阅读 / 复习 / 生词 / 我的;
4. Reading empty-library state;
5. Review no-due state;
6. WordSense empty state;
7. My/account page with server, offline-sync, privacy and device-revocation controls;
8. real offline transition by removing ADB reverse and restarting the app;
9. visible `服务器不可达` / `无法读取今日进度` / connection guidance while navigation stayed available;
10. reverse restoration + app restart;
11. automatic return to `在线` Home without entering the password again;
12. visible `撤销此设备并退出`;
13. server-side device DELETE;
14. restart after revocation remained on the login screen, proving the prior mobile session was not silently restored.

The server log contains the real mobile device revocation DELETE request. The final APK was uninstalled and the emulator was shut down; final ADB device list was empty.

## Android/emulator production-tool incident

One earlier emulator instance entered a SystemUI ANR / ADB-offline state after APK installation. The app code was not changed to accommodate the broken emulator instance.

H-11 followed the established Android tooling recovery path: stop the unhealthy emulator instance, restart ADB, and boot the AVD cleanly without reusing the bad snapshot. The clean Android 16 instance then stayed in `device` state and completed the acceptance chain above.

A second tool issue was also reproduced: the machine PATH still placed PHP 8.2 before PHP 8.4. That caused one PAB cleanup helper run to fail after the Goal runtime had already moved to PHP 8.4. `scripts/windows/gpt-workflow-config.bat` now refuses an older PATH PHP and resolves an installed WinGet PHP 8.4+ runtime. Re-running the existing PAB recovery with PHP 8.4 completed:

- stale sentinel cleanup;
- fresh sentinel creation;
- child exit 0;
- fresh sentinel cleanup.

No global Windows PATH rewrite was required, so other local projects are not silently changed by LinguaCafe tooling.

## Automated verification

Final H-11 verification includes:

- PHP 8.4 Unit + Feature combined suite: **4034 tests / 19397 assertions / 14 skipped / 0 failures**;
- earlier direct Feature batching: **3083 tests / 15424 assertions / 14 skipped / 0 failures**;
- full JS guard suite: **477 / 477 PASS**;
- mobile Vitest: **5 files / 42 tests PASS**;
- mobile TypeScript + Vite production build: **PASS**;
- Laravel Mix development build: **PASS**;
- `npm run release:rights`: **PASS**, 2066 tracked paths checked;
- FSRS native availability guard: **PASS**;
- Reader manual Sense identity guard: **PASS**;
- Windows PHP/tokenizer workflow guard: **5 / 5 PASS**;
- `git diff --check`: **PASS** after removing nested-patch blank-line whitespace while preserving `git apply --check` compatibility;
- production FSRS Docker image: **real build + runtime scheduling smoke PASS**.

## Independent read-only review

A final `opencode/hy3-free` read-only review inspected the H-11 diff. It reported no Critical issue, found the Docker FSRS path fail-closed/minimal, and found the Reader change reduced duplicate truth rather than introducing a second learning owner.

The reviewer initially marked runtime acceptance incomplete because it did not itself execute the final Docker/Web/Android gates. Those gates were subsequently executed independently as recorded above, so that evidence gap is closed.

## Cleanup

Final H-11 cleanup state:

- testing browser server on 8877: no listener;
- TestingDatabaseLease: `active=false`, `stale_metadata=false`;
- H-11 acceptance sentinel: no live residue;
- Android debug package: uninstalled;
- Android emulator: stopped;
- ADB device list: empty;
- temporary final testing account: deleted through the real Web account-deletion flow;
- `.env` and production/development user databases were not read, reset, wiped, migrated, or restored by H-11.

## Boundary handed to H-GATE

H-11 closes every runnable final Web + Android regression on the current host. H-GATE must now audit the entire Goal plan and preserve the following unresolved external capability fact:

**H-10 real iOS Xcode build, simulator/device, Keychain-at-rest runtime, signing/archive, TestFlight and App Store evidence remain Not Complete.**

H-GATE may close the runnable Windows/Web/Android program, but it must not label the complete cross-platform Goal finished until the required iOS capability cluster is actually available and verified.
