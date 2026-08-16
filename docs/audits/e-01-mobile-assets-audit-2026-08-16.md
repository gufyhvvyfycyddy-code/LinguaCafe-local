# E-01 Mobile M1–M9 Asset Audit — 2026-08-16

Status: `Accepted under current Goal authorization`

## Architecture Gate

- Goal: audit the current M1–M9 implementation and classify each asset as `reuse`, `adapt`, or `obsolete` for Phase E.
- Not in scope: product-code changes, schema changes, a second mobile architecture, a local FSRS authority, background workers, full-dictionary bundling, signing, store submission, or real provider use.
- Owners and seams: Laravel `/api/v1/mobile` remains the server authority; the existing Capacitor/Vite `mobile/` client remains the only native/mobile shell; existing package, idempotency, operation-ledger, queued-sync and testing-harness owners remain single sources of truth.
- Files changed by E-01: this audit and the Goal control ledger only. All product code, tests, native projects and user dirty assets are read-only evidence.
- Data boundary: no application or learning-data mutation. Verification uses automated tests and static/build checks only.
- Compatibility: Web rating, Sense Review, WordSense, ReviewLog, FSRS, Reader/Finish and the accepted Mobile API envelopes remain unchanged.

## Current executable baseline

The old M1–M9 closure reports are evidence, not automatic acceptance. Fresh source and executable checks establish this current baseline:

- `/api/v1/mobile` still exposes device-bound auth/bootstrap, article and short-term review packages, dictionary/WordSense, summary, text import, queued sync, formal Sense rating, operation undo/redo and device revocation through the existing controllers.
- Laravel mobile tests passed 60/60 (589 assertions): Mobile API foundation, operation ledger, M3 packages, M4 sync, M7 connected API and M9 text import.
- Mobile Vitest passed 29/29; TypeScript and Vite production build passed.
- M5/M6/M7/M9/static sync guards passed 5/5.
- No Phase E code or data was changed to obtain this evidence.

## Asset classification

| Asset | Current owner / evidence | Classification | Phase E treatment |
|---|---|---|---|
| M1 Mobile API, active-device Sanctum and idempotent formal Sense rating | `routes/api.php`, `MobileApiResponse`, `EnsureActiveMobileDevice`, `MobileIdempotencyService`, `MobileSenseReviewController` | `reuse` | Keep the envelope, device authority and unique client-action claim. All formal ratings continue through `ReviewCardService` and `FsrsSchedulingService`; do not add another rating endpoint. |
| M2 operation ledger and linear undo/redo | `MobileOperationLedgerService`, `operations`, `operation_changes`, `/operations` endpoints | `reuse` | Keep one operation identity and append-only transition history. E-04/E-05 may consume it from the client but must not build another history or recovery ledger. |
| M3 article/review package APIs | `MobileArticlePackageService`, `MobileReviewPackageService`, package controllers | `reuse + adapt` | Reuse deterministic checksums, bounded shards/cursors, token/sentence identity, per-token lemma/POS, sentence translations and confirmed WordSense summaries. Adapt the client/package projection only where E-03 proves missing article-local dictionary summaries or current Phase A–D fields. |
| M4 queued action sync | `MobileQueuedActionSyncService`, `/sync/actions`, `mobile_client_actions` | `reuse + adapt` | Reuse deterministic ordering, action idempotency, per-action results and server-authoritative conflicts. The server supports rating/update/delete, while the native client currently persists only `sense_review.rating`; extend only the action types required by an E milestone. |
| M5 touch adaptation | Web Reader/Reviewer touch policy plus `mobile/src/styles.css` safe areas, bottom sheet and 44 px targets | `reuse + adapt` | Reuse accepted gesture/accessibility policies and current mobile sheet/safe-area CSS. The native reader currently handles single-token tap only and the native reviewer lacks explicit previous/forward navigation; E-06 owns those real gaps instead of adding a parallel UI system. |
| M6 resilience, health and isolation | existing backup/restore, health, testing DB lease/sentinel and scope guards | `reuse (supporting)` | Reuse safety and testing infrastructure. Backup/restore and health are not new Phase E primary navigation or a new mobile subsystem. |
| M7 Android Capacitor client | `mobile/src/*`, Android project, secure-token bridge, official Capacitor transport/plugins | `reuse + adapt` | Keep the single Capacitor/Vite client and native credential boundary. E-02/E-06 align its UI with current product semantics; E-07 must rebuild and run the current artifact on an emulator/device rather than treating the 2026-08-01 artifact as current proof. |
| M8 IndexedDB limited-offline path | `OfflineRepository`, cached bootstrap, foreground sync, visible pending/issues | `reuse + adapt` | Keep `M3 package → scoped IndexedDB → durable action → M4 sync`. Adapt package coverage, user-facing conflict/retry copy and the current Phase B reading/passive-Good operation boundary; do not add a second sync engine or local FSRS calculation. |
| M9 iOS project and platform seams | official Capacitor iOS project, Keychain `SecureToken`, picker/import, privacy/release artifacts | `reuse + adapt + capability-bound` | Keep the shared client and bounded native bridge. Refresh generated assets and rerun every Windows/static/build contract in E-08. Xcode compile, signing, simulator/device, Keychain runtime and TestFlight remain in the named iOS capability cluster. |

## Confirmed gaps routed to E-02…E-08

1. **E-02 — IA alignment.** The current native `Screen` union and bottom nav are `library / review / summary / settings`, labeled `文章 / 复习 / 进度 / 设置`. They are not yet the Phase C product mapping of Home plus four primary destinations `阅读 / 复习 / 生词 / 我的`. The existing shell is adapted in place; no mobile router/store/service is added merely for parity.
2. **E-03 — packages.** Article shards already contain tokens, source sentence identity, lemma/POS, sentence translations and relevant confirmed WordSense summaries. The client consumes these package endpoints, but offline lookup still calls the server dictionary and `reviews()` requests `horizon_days=0`; E-03 must determine the smallest package/client change for article-local dictionary summaries and a genuinely short-term Sense package.
3. **E-04 — formal offline operations.** Offline explicit Sense rating already persists one durable action and reuses M4. The native client contains no reading-session/settlement/passive-Good path. E-04 must integrate only the already accepted Phase B operation/Finish boundaries required by the mobile reader; it must not invent a client-side passive-rating rule.
4. **E-05 — recovery language.** IndexedDB preserves queued actions across restart and records terminal issues, but current issue presentation exposes stable server codes/messages directly. E-05 owns ordinary-user conflict/retry/app-kill recovery wording and evidence, not a watchdog or background recovery worker.
5. **E-06 — interaction.** Safe areas, bottom sheet, 44 px controls, tap lookup, reveal/four ratings and haptics exist. Native long-press phrase drag, reviewer previous/forward behavior and current-product navigation still require implementation and real UI evidence.
6. **E-07 — Android.** Historical Android 12 evidence proves the architecture, but the repository publication report explicitly says the later rebuilt APK was not device-revalidated. E-07 needs a fresh build plus current online/offline/reconnect rendered workflow.
7. **E-08 — iOS.** Static project/source contracts are reusable. The checked-in report records stale ignored generated iOS Web assets and unavailable Apple toolchain/device/signing. Windows-local work proceeds; missing Apple evidence remains `Acceptance Deferred — Not Complete` in one named capability cluster.

## Obsolete as Phase E product paths

- Historical `Completed / Closed` labels are not current acceptance evidence; only fresh source/tests/device runs count.
- The M4 Web sync simulator remains an advanced diagnostic/test surface and must not become the ordinary mobile product flow.
- M7's connected-only assumption is superseded by the accepted M8 limited-offline path.
- Any platform-hard-coded Android identity in shared client behavior is obsolete; current code derives the actual Capacitor platform.
- An Anki-style full local collection, client-authored FSRS state, blanket retry worker, full ECDICT bundle and separate iOS/Android data authorities remain excluded, not future fallback options.

## E-01 exit decision

E-01 is complete. The existing architecture is materially reusable and there is one implementation path for Phase E. The next active milestone is E-02, starting with the current `mobile/src/ui.ts` shell and the Phase C navigation contract. No product code should be changed under E-01.
