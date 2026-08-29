# E-06 / E-07 Native Android Capability Closure — 2026-08-30

## Verdict

**Accepted / DONE for E-06 and E-07 on the current Android capability.**

The earlier 2026-08-16 E-06/E-07 `DEFERRED` records remain valid historical snapshots: at that time the available AVD/device paths could not run. The current host now has a working Android 16 / API 36 `LinguaCafeM7` AVD, so the missing native-device evidence was rerun instead of being inferred from browser or source checks.

E-08 and H-10 iOS capability remain separate and are not changed by this closure.

## Git / authority preflight

Fresh preflight before the closure found:

- current Goal HEAD / tracking / live remote aligned at `f106ed128d91821395e24d48f5f5f782fae3959f` before this closure commit;
- live `origin/master` = `1c9bdcd74fa793356ba3938f21c56405f3261e39`;
- the Goal branch is 197 commits ahead of that master and 0 behind;
- no reset, rebase, merge, stash, clean, force push, `.env` read/write, destructive database reset, notification script or DCP was used.

## Why the Android Back implementation changed

The first native E-06 run reproduced a real Android 16 defect: when the Reader lookup Bottom Sheet was open, system Back could leave the LinguaCafe activity instead of consuming the existing WebView history entry first.

Android 16 / target API 36 no longer dispatches `KEYCODE_BACK` for this interception path. The initial repair therefore moved the app to AndroidX `OnBackPressedDispatcher` / `OnBackPressedCallback` and kept WebView `canGoBack()` / `goBack()` as the one history owner.

Final code review found one further issue in the first repair: an always-enabled `OnBackPressedCallback` would also consume root Back and therefore prevent the system from owning back-to-home Predictive Back. The accepted implementation now:

1. creates the Web history callback disabled;
2. reuses Capacitor's existing `BridgeWebViewClient` rather than adding another router, plugin or JS/native navigation protocol;
3. updates callback enablement in `doUpdateVisitedHistory()` from `WebView.canGoBack()`;
4. calls only `WebView.goBack()` when the callback is enabled;
5. leaves the callback disabled at the WebView root so Android owns the final back-to-home action;
6. keeps `android:enableOnBackInvokedCallback="true"` for the launcher activity.

No mobile API, sync owner, offline store, review scheduler, FSRS writer or Web Reader/Reviewer contract changed.

## E-06 real Android 16 interaction evidence

The current debug APK was installed on the real Android 16 / API 36 emulator and exercised through the Android WebView UI.

The native interaction run proved:

- long press on Reader text followed by drag selected the bounded phrase `bank account` rather than independent word taps;
- the lookup Bottom Sheet rendered `bank account · phrase` and reused the existing lookup/create-Sense flow;
- with the Android soft keyboard open, the Chinese definition field and create action remained reachable rather than being covered by the IME;
- a real mobile WordSense creation completed through the visible Bottom Sheet and the server received `POST /api/v1/mobile/word-senses`;
- the resulting Sense card appeared in the mobile short-term review package and the Reviewer rendered `SENSE REVIEW · 1 REMAINING`;
- revealing the answer showed the confirmed Chinese meaning and all four Again / Hard / Good / Easy controls;
- haptic feedback was executed through the existing Capacitor Haptics `impact(MEDIUM)` path during the real Good action;
- primary-screen browser history supported Android Back and Forward without a second native router.

### Final Predictive Back regression on the accepted implementation

The final callback version was rebuilt and reinstalled, then verified independently of account state on the real Android 16 WebView:

1. at the WebView root, `history.length` was 1;
2. a same-document `history.pushState({probe: "e06"})` created a second history entry;
3. system Back kept `com.linguacafe.mobile/.MainActivity` as the top-resumed activity and restored `history.state` to null;
4. a second system Back at the root transferred the top-resumed activity to the Android launcher.

This proves both sides of the contract: WebView history is consumed when present, while root Back remains owned by the Android system.

## E-07 real Android online / offline / reconnect closure

The same current Android path completed the previously missing limited-offline chain with real device state rather than browser substitution.

### Downloaded article and cached content

While online, the test material was downloaded through the mobile article-package UI. After removing ADB reverse and restarting the app:

- the app visibly reported `服务器不可达`;
- Reading showed that it was using the local short-term offline article package;
- the library still listed the downloaded material with `已下载 1/1 章`;
- the chapter screen showed `已下载 · 可离线`;
- the Reader opened the cached chapter and rendered the actual English text while the server remained unreachable.

### Offline review and queued rating

The same disconnected app opened the cached short-term review package:

- the Reviewer visibly identified the offline review package and warned that ratings would queue for sync;
- the `bank account` card and its real source sentence rendered offline;
- `显示答案` exposed the Chinese meaning plus Again / Hard / Good / Easy;
- the visible Good action completed while offline;
- local pending operations increased from one reading-position action to two operations, adding the Sense rating;
- logcat recorded the existing Haptics `impact(MEDIUM)` call;
- the app attempted `POST /api/v1/mobile/sync/actions`, received a real connection failure because reverse was intentionally absent, and retained the queued operations.

### Reconnect and server authority

After restoring ADB reverse and restarting the app:

- the app returned to `在线` without asking for the password again;
- the server received the real `POST /api/v1/mobile/sync/actions`;
- the account surface reported `0 个操作待同步；0 个操作需要处理`;
- server readback showed the reviewed card moved from `new` to `learning`, `reps=1`, with the corresponding `sense_review.rating` operation in `applied` state.

The queue therefore did not merely disappear locally: the authoritative server state contains the replayed rating.

## Device revoke / account boundary

The native acceptance also reused the existing privacy/device path:

- `撤销此设备并退出` sent the real mobile-device DELETE;
- the app returned to the login surface;
- force-stop/restart remained on login, so the revoked session was not silently restored.

During final task-data cleanup, the temporary acceptance account was initially the last administrator in the testing database. The canonical `UserService::deleteAccount()` correctly refused to violate the last-admin invariant. The project-approved fixed local testing administrator was restored through the normal authenticated admin flow, after which the temporary account was deleted through the canonical account service. No direct SQL bypass of the last-admin protection was used.

Final task-user readback before the last native Back-only probe was:

- temporary task user: 0;
- task book: 0;
- task chapter: 0;
- task WordSense: 0;
- task ReviewCard: 0;
- fixed project testing administrator retained for future acceptance work.

## Automated verification

The accepted Android closure has the following current-code checks:

- `tests/js/E06MobileInteractionGuard.test.mjs`: PASS;
- `tests/js/MasterPlanIntegrityContract.test.mjs`: PASS after repairing its stale H-11/H-GATE expectations;
- Mobile Vitest: **42 / 42 PASS**;
- mobile TypeScript + Vite production build: PASS;
- Android `assembleDebug`: **BUILD SUCCESSFUL**;
- full JS guard suite on the final callback plus updated E-06/E-07 plan state: **477 / 477 PASS**;
- `git diff --check`: PASS.

## Tooling findings

Two tooling events were handled without changing product architecture:

1. a foreground PAB invocation was terminated by the terminal timeout and left stale lease metadata; the existing PAB recovery path removed the stale sentinel and returned `TestingDatabaseLease` to `active=false / stale_metadata=false` before work continued;
2. Capacitor `cap sync android` touched generated Gradle files only through line-ending metadata; their semantic diff was empty and they were restored exactly instead of being included in the product change.

Final post-probe cleanup completed: the debug APK was uninstalled, the emulator was stopped, `adb devices` was empty, port 8878 had no listener, and the expected stale lease metadata left by stopping the acceptance server was recovered through the existing PAB lifecycle. That recovery removed exactly one stale sentinel, created and cleaned one fresh sentinel, and ended with `TestingDatabaseLease active=false / stale_metadata=false`.

## Boundary after closure

E-06 and E-07 no longer belong to the Android capability cluster. Their original 2026-08-16 DEFERRED records remain historical evidence explaining why they were not accepted earlier.

The remaining mobile capability gap is iOS only:

**E-08 / H-10 real Xcode build, iOS simulator/device, Keychain runtime, signing/archive and TestFlight remain DEFERRED / Not Complete until a macOS/Apple-capable environment is actually available.**
