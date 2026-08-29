# H-10 macOS / Xcode / iOS Simulator Capability Continuation — 2026-08-30

## Verdict

**Partial capability recovery accepted. H-10 remains DEFERRED / Not Complete.**

The 2026-08-29 H-10 probe correctly recorded that the local Windows host had no Xcode, Apple signing or connected macOS peer. On 2026-08-30 a different, bounded capability became available: the actual `origin` repository was freshly verified as public, so LinguaCafe could use a standard GitHub-hosted `macos-26` runner without introducing a paid larger runner or Apple credential path.

This continuation closes the previously missing native macOS/Xcode compile and basic iOS Simulator install/launch evidence. It does **not** close the physical-device, Keychain-with-authenticated-session, signing/archive, TestFlight/App Store Connect, or App Review portions of H-10.

## Authority and safety boundary

The work keeps the existing M9/H-10 architecture and release boundary:

- no product API, database, FSRS, Reader, Review or synchronization semantics changed;
- no Apple password, certificate, private key, provisioning profile, App Store Connect credential or signing secret was requested or read;
- no `.env` file was read or modified;
- no archive, IPA, upload, TestFlight submission or App Store submission was created;
- the Xcode build explicitly uses `CODE_SIGNING_ALLOWED=NO`;
- the workflow has `permissions: contents: read`, a 30-minute timeout, no Apple secrets and no artifact upload;
- no production or testing database write was performed by the macOS probe;
- the simulator launch is therefore a native runtime capability check, not an authenticated product-flow acceptance.

The permanent tooling is intentionally small:

- `.github/workflows/ios-xcode-capability-probe.yml` — manual/reusable macOS capability lane;
- `mobile/scripts/ios-generated-web-integrity.mjs` — one repeatable owner for post-sync Web asset integrity;
- `npm run ios:generated-web-integrity` — the local/CI entry;
- `tests/js/M9IosMvpGuard.test.mjs` — keeps the lane manual/read-only/unsigned and prevents it from drifting into signing or distribution.

## Why a GitHub-hosted macOS runner became acceptable

Fresh repository inspection confirmed that `gufyhvvyfycyddy-code/LinguaCafe-local` is currently **PUBLIC**. GitHub's current billing documentation states that standard GitHub-hosted runners are free for public repositories; larger runners remain billable. The probe therefore uses only the standard `macos-26` label.

GitHub's current macOS runner image documents Node.js 24 and Xcode 26.6 (`17F113`). The workflow pins the Xcode toolchain for the job with:

`DEVELOPER_DIR=/Applications/Xcode_26.6.app/Contents/Developer`

This avoids mutating runner-global Xcode selection. The new probe uses `actions/checkout@v6`, whose current release line uses Node.js 24; this also removes the Node-20 action deprecation warning observed in an earlier probe run.

No automatic `push`, `pull_request` or `schedule` trigger was added. The reusable workflow remains callable only from an explicitly invoked workflow path.

## Repeated generated-Web integrity owner

The old playbook described six manual post-sync checks. They are now executable through:

`npm run ios:generated-web-integrity`

The script fails closed unless all of the following are true:

1. `dist/index.html` and generated iOS `public/index.html` reference the same main JS;
2. both reference the same CSS;
3. the complete `dist/assets/` and generated iOS `assets/` file sets are identical;
4. every corresponding generated asset has the same SHA-256;
5. index/main JS/CSS hashes match exactly;
6. generated iOS public contains zero `.map` files;
7. generated main JS contains `正式移动端仅允许 HTTPS`, `服务器分页信息无效`, and `仅用于本地调试`.

The local Windows preflight on the current source reported:

- index SHA-256: `2aa656e3718faabc05b972d44b315c3b6ba6b159c7ef8c40427460611f28803c`;
- main JS: `assets/index-CGTGpXs0.js`;
- main JS SHA-256: `7dcec2f97408575b30674d0632fde03989160b2ed51409aa81a77c5bcede1953`;
- CSS: `assets/index-CLhVdIPh.css`;
- CSS SHA-256: `2afed444c3e988451accf4bc55443dc023c3eb315e41524afd64ddae24e03552`;
- asset count: 5;
- sourcemap count: 0;
- all three safeguards present.

The same script also passed on the real GitHub macOS runner after `cap sync ios`.

## First real macOS/Xcode run

GitHub Actions run:

`33264818165`

URL:

`https://github.com/gufyhvvyfycyddy-code/LinguaCafe-local/actions/runs/33264818165`

Head:

`8e142eac252feb1dac910fd0c9b592047d64bebe`

Result: **SUCCESS**.

Observed facts from the runner log:

- macOS 26.5.2;
- Xcode 26.6;
- Xcode build `17F113`;
- Mobile Vitest: 5 files / 42 tests PASS;
- generated-Web integrity: asset count 5, sourcemap count 0, safeguards PASS;
- M9 iOS source/release guard PASS;
- SwiftPM package resolution PASS;
- `xcrun simctl list devices available` returned current iPhone simulators including iPhone 17 Pro;
- unsigned iOS Simulator build compiled both arm64 and x86_64 simulator targets;
- bundle identifier remained `com.linguacafe.mobile`;
- final Xcode result: `** BUILD SUCCEEDED **`.

This is real macOS/Xcode evidence. Windows source inspection is no longer being used as a substitute for native compile or SwiftPM resolution.

## Second real macOS/Xcode + simulator run

GitHub Actions run:

`33264963006`

URL:

`https://github.com/gufyhvvyfycyddy-code/LinguaCafe-local/actions/runs/33264963006`

Head:

`709c5b6ff0fd65c2c961e98608fc2136cd511721`

Result: **SUCCESS**.

The first ten gates repeated successfully, including Xcode compile. The added simulator gate then completed:

1. selected an available iPhone 17 Pro simulator;
2. `simctl boot` + `bootstatus -b` reached terminal `Finished` state;
3. installed the built `App.app`;
4. `simctl get_app_container` returned a real installed application container;
5. `simctl launch ... com.linguacafe.mobile` returned process id `9028`;
6. the process remained launchable for the bounded smoke interval;
7. `simctl terminate` succeeded;
8. `simctl shutdown` succeeded.

The relevant installed bundle container was under the simulator's normal CoreSimulator application container, and no runner artifact was uploaded.

This closes the basic native simulator **boot → install → launch → terminate → shutdown** capability. It does not prove login, Reader, Review, offline sync or Keychain behavior because those require a testing-bound LinguaCafe server and rendered UI/user-event acceptance.

## Third stability run

GitHub Actions run:

`33265308947`

URL:

`https://github.com/gufyhvvyfycyddy-code/LinguaCafe-local/actions/runs/33265308947`

Head:

`20b42148aa488aca9b2e8e7722bf20a29ccf55eb`

Result: **SUCCESS**.

This run used `actions/checkout@v6`, repeated all compile/integrity gates, then repeated the simulator boot/install/launch/terminate/shutdown smoke. The original `schedule-next-attempt` job was **skipped**, so the carrier did not run OpenCode, post issue/PR comments, or start an auto-fix cycle.

The run again passed Mobile 42/42, Capacitor iOS sync, generated-Web integrity, the M9 source/release guard, SwiftPM resolution and the unsigned iOS Simulator build before launching the app. The checkout-v4 Node-20 deprecation warning seen on the earlier runner was no longer present on the new probe path.

## What is now genuinely closed

The H-10 capability list can now mark these items as proven on real macOS/Xcode infrastructure:

- Xcode 26.6 native compilation against the installed current iOS Simulator SDK;
- SwiftPM resolution under the actual Xcode toolchain;
- availability and successful boot of an iOS Simulator;
- installation of the current LinguaCafe simulator app;
- launch of `com.linguacafe.mobile` without immediate process-start failure;
- bounded terminate/shutdown cleanup;
- current generated Web asset integrity on macOS after Capacitor sync.

## What remains unavailable / unaccepted

H-10 remains DEFERRED because the following still lack the required evidence:

1. rendered simulator functional matrix with a server-bound testing environment: login/relaunch, Reader touch/safe-area, formal Review/undo, `.txt` import, offline package/rating/sync and logout scope clearing;
2. authenticated Keychain runtime evidence proving the bearer token is in Keychain and absent from ordinary Web/file storage;
3. signed physical-iPhone installation;
4. physical haptics, notification behavior, audio focus/interruption and real notch/Dynamic-Island/home-indicator behavior;
5. Apple team/provisioning/bundle registration;
6. signed archive and Organizer validation;
7. App Store Connect upload/processing;
8. TestFlight install and critical-flow rerun on a physical iPhone;
9. final public privacy/support URLs and App Store Connect privacy answers;
10. App Review result.

A cloud simulator is useful for compile/runtime capability but cannot substitute for physical-device and Apple-account distribution evidence.

## Why the cloud simulator is not extended into a second backend stack now

The mobile client permits `http://127.0.0.1` only as an explicit local development address, so a same-runner testing server is technically possible. However, the full Review/FSRS write path requires the actual LinguaCafe testing runtime, including PHP/MySQL/native FSRS and a server-bound testing sentinel. Rebuilding an additional macOS backend solely to collect a few simulator UI checks would create another infrastructure path while the final Goal would remain blocked by physical iPhone/signing/TestFlight regardless.

The current efficient boundary is therefore:

- keep the permanent macOS/Xcode compile + simulator launch probe;
- do not weaken ATS;
- do not create an ad-hoc public tunnel;
- do not duplicate the testing backend on macOS without a future concrete need;
- resume the remaining M9/H-10 device/store playbook only when the deployment owner's Apple/physical-device capability is available.

## Official environment references

- GitHub Actions billing: https://docs.github.com/en/billing/concepts/product-billing/github-actions
- GitHub-hosted runners reference: https://docs.github.com/en/actions/reference/runners/github-hosted-runners
- GitHub macOS 26 runner image / Xcode inventory: https://github.com/actions/runner-images/blob/main/images/macos/xcode-27-Readme.md
- `actions/checkout` v6 / Node 24 release line: https://github.com/actions/checkout

## Current H-10 conclusion

**Native macOS/Xcode/SwiftPM/basic simulator capability: Accepted.**

**Full H-10 / E-08 / H-GATE: still DEFERRED / Not Complete**, now narrowed to authenticated simulator product-flow evidence plus the physical-device / Keychain / signing / TestFlight / App Store capability cluster described above.
