# Codex Final Handoff — 2026-08-04

Final conclusion: **HANDOFF_READY_WITH_BLOCKERS**

This document is the authoritative resume point for the current local workspace. It records observable local state; it does not convert uncommitted implementation or historical acceptance reports into pushed Git evidence.

> **Recovery execution status (2026-08-05, CFH-01):** this handoff is superseded as the *active execution contract* by `docs/plans/linguacafe-recovery-publication-master-plan-2026-08.md` (status: `current`, active task `CFH-01`). The milestone lock is `docs/execution/CURRENT_MILESTONE.json`, the exact per-path ownership map is `docs/audits/cfh-01-worktree-ownership-map-2026-08-04.json` (462 paths), and the workflow contract is guarded by `tests/js/RecoveryPublicationWorkflowDocsGuard.test.mjs`. This file keeps its historical content and remains the resume entry for the pre-existing workspace state recorded below.

## 1. Handoff Baseline

- Baseline time: 2026-08-04 21:37:35 Asia/Shanghai.
- Repository: `D:\Document\lingl\LinguaCafe-main`.
- Branch: `master`.
- Starting `HEAD`: `1c17625a576a9b61c13c50b6c3af297c859f789d`.
- `origin/master` after `git fetch origin --prune`: `1c17625a576a9b61c13c50b6c3af297c859f789d`.
- Starting divergence: ahead 0 / behind 0; conflict paths 0.
- Starting worktree clean: no.
- Starting inventory: 454 changed files after expanding untracked directories: 110 tracked modified, 2 tracked deleted, 342 untracked. Categories reported by the workspace inventory were source 265, tests 85, documentation 94, migrations 9, generated 1, temporary 0, unknown 0.
- Porcelain presentation before expansion: 112 tracked status entries and 223 collapsed untracked entries.
- `.env`: absent from Git status; its contents were not read.
- External recovery snapshot: `C:\Users\Administrator\.codex\final-handoff-snapshots\linguacafe-20260804-213735`. It contains the starting HEAD/origin refs, NUL-safe porcelain status, tracked/untracked/conflict path lists, timestamp, and expanded JSON inventory.
- The snapshot is evidence only. It is outside the repository and must not be committed.
- This final session added one repository file: this handoff. After the first exact handoff commit, the pre-existing dirty-status set still matched the starting snapshot exactly (`statusAdded=[]`, `statusRemoved=[]`).
- First handoff delivery commit pushed during this session: `460b275862b992fbbc49087f2fa8347c997bb77a` (`docs: add final Codex handoff`). The latest pushed tip after any follow-up compliance-only revision is the `origin/master` commit containing this document and must be confirmed with `git rev-parse origin/master`.

## 2. User-facing Project State

The local workspace contains a broad M0–M18 implementation and its tests/documents. Historical local acceptance reports mark M1–M8 and M10–M16 closed, M17/M18 Web and Android slices closed, and M9 implementation accepted with the iOS capability cluster deferred. None of that broad implementation is present in the starting GitHub baseline: the fetched remote and local `HEAD` both remain at the July baseline commit above.

Practical user-facing state:

- Web: local code and reports cover mobile-aware authentication/APIs, review controls, custom study, backup/restore preview, article health, search/tag/browser management, workload/statistics/Card Info, portable Anki/JSON/CSV workflows, review accessibility and media controls. These broad product changes are not pushed.
- Android: a local Capacitor Android project covers connected login/review, secure token storage, package use, offline rating queue/retry/conflict display and media caching; historical Android 12 emulator evidence exists, but the entire platform tree is still untracked.
- Offline: local code covers isolated article/review packages, IndexedDB-backed user/language cache, persistent queued ratings, reconnect/sync convergence and visible conflict/retry states. Current-session end-to-end evidence was not rerun.
- Search, tags, statistics, import/export and media: local implementations and acceptance reports exist for M10 and M14–M18; they remain mixed into the dirty tree and require slice attribution plus fresh applicable verification before release.
- iOS: implementation, Keychain bridge, `.txt` import and release material exist locally, but no Mac/Xcode signing/device/TestFlight/App Store acceptance exists. M9 and the iOS portions of M17/M18 therefore remain blocked/partial.
- The work has been preserved locally and in the external inventory snapshot, but the 454-file dirty tree is not a safe single commit. Existing reports are useful evidence, not substitutes for current attribution, exact staging, or current verification.
- No product-code slice was safe to complete within the final Codex budget.

## 3. Milestone Status M0–M18

Allowed state vocabulary is used deliberately. “Verified” below means the local implementation has a named local acceptance report and/or current focused executable evidence; it does not mean pushed.

| Milestone | Status | current_goal | local_state | verification_state | git_state | blocker | next_action |
|---|---|---|---|---|---|---|---|
| M0 | IMPLEMENTED_LOCALLY_VERIFIED | Archive roadmap and freeze plan | Planning/roadmap documents exist locally | Documentation state recorded in the current roadmap | Uncommitted; absent from starting `origin/master` | Broad docs tree needs attribution | Verify exact M0 document allowlist, then commit separately |
| M1 | IMPLEMENTED_LOCALLY_VERIFIED | Mobile API foundation and idempotent rating | Backend/API/tests locally present | Local acceptance report exists | Uncommitted; absent from starting remote | Mixed shared Laravel files | Re-run M1 focused tests and stage exact contract/API files |
| M2 | IMPLEMENTED_LOCALLY_VERIFIED | Operation ledger and unified undo base | Models/services/tests locally present | Local acceptance report exists | Uncommitted | Dependency and migration slice not isolated | Verify after M1 commit, then exact-path commit |
| M3 | IMPLEMENTED_LOCALLY_VERIFIED | Article and short-review packages | Local implementation present | Local acceptance report exists | Uncommitted | Shared mobile files overlap later milestones | Attribute package files and rerun focused tests |
| M4 | IMPLEMENTED_LOCALLY_VERIFIED | Queued sync and conflict simulator | Local implementation present | Local acceptance report exists | Uncommitted | Depends on M1–M3 and shared sync files | Verify in dependency order and exact-stage |
| M5 | IMPLEMENTED_LOCALLY_VERIFIED | Touch-adapted reader/reviewer | Local Web/mobile implementation present | Local acceptance report exists | Uncommitted | Reader/UI is high risk and needs current browser evidence before release | Run focused tests/build and real rendered UI batch |
| M6 | IMPLEMENTED_LOCALLY_VERIFIED | Backup/restore, article health, isolation | M6A–M6D implementation locally present | Historical reports exist; this final session freshly passed 44 tests / 123 assertions for SQL grammar, quiescence, write fence, orchestration and restore job | Uncommitted | Full 62-test M6 matrix and exact file attribution were not rerun in this final session | Use task `CFH-02` below |
| M7 | IMPLEMENTED_LOCALLY_VERIFIED | Android connected MVP | Android project and local acceptance evidence present | Android 12 emulator acceptance report exists | Entire `mobile/android` tree is untracked | Needs exact Android source/generated boundary and fresh build | Use task `CFH-04` below |
| M8 | IMPLEMENTED_LOCALLY_VERIFIED | Limited offline MVP | Shared mobile/offline implementation locally present | Local browser/Android acceptance report exists | Uncommitted/untracked | Shared files overlap M7/M9 and need attribution | Verify after M7 foundation is committed |
| M9 | BLOCKED | iOS MVP and store release | iOS implementation and release docs locally present | Local unit/build/sync evidence exists; Mac/Xcode/signing/device/TestFlight/App Store evidence is absent | Entire `mobile/ios` tree is untracked | Requires macOS, Xcode, Apple account/signing, physical/simulator device and store channels | Use task `CFH-05`; do not claim acceptance before capability evidence |
| M10 | IMPLEMENTED_LOCALLY_VERIFIED | Unified search/tag/browser foundation | Local implementation present | Local acceptance report exists | Uncommitted | Shared query/UI files need exact attribution | Re-run M10 tests/build, then exact-stage |
| M11 | IMPLEMENTED_LOCALLY_VERIFIED | Review control/manual scheduling | Local implementation present | Local acceptance report exists | Uncommitted | FSRS/ReviewLog high-risk files overlap later milestones | Commit only after protected FSRS matrix passes |
| M12 | IMPLEMENTED_LOCALLY_VERIFIED | Special/custom study sessions | Local implementation present | Local acceptance report exists | Uncommitted | Depends on M10/M11 query and lifecycle contracts | Verify in dependency order |
| M13 | IMPLEMENTED_LOCALLY_VERIFIED | Review settings/workload planner | Local implementation present | Local acceptance report exists | Uncommitted | Shared scheduling/settings files need attribution | Run protected scheduling tests before exact commit |
| M14 | IMPLEMENTED_LOCALLY_VERIFIED | Statistics and Card Info V3 | Local implementation present | Local acceptance report exists | Uncommitted | Shared metrics/query surfaces need attribution | Verify after M10–M13 dependencies |
| M15 | IMPLEMENTED_LOCALLY_VERIFIED | Browser knowledge hygiene V3 | Local implementation present | Local acceptance report exists | Uncommitted | Search/tag/browser files overlap M10/M16 | Isolate exact diff and rerun browser evidence |
| M16 | IMPLEMENTED_LOCALLY_VERIFIED | Portable data and Anki interoperability | Local implementation present | Local acceptance report exists | Uncommitted | Import/export code is security/data sensitive | Re-run focused import/export tests and non-writing browser preview |
| M17 | IMPLEMENTED_LOCALLY_PARTIAL_VERIFICATION | Review experience/accessibility V2 | Web and Android slices locally implemented | Web/Android acceptance report exists; iOS follows unresolved M9 capability evidence | Uncommitted | iOS accessibility/device evidence absent | Preserve Web/Android result; close iOS only with CFH-05 evidence |
| M18 | IMPLEMENTED_LOCALLY_PARTIAL_VERIFICATION | Media/pronunciation/offline audio V1 | Implementation plus Web/Android slices locally present | Web/Android acceptance report exists; iOS follows unresolved M9 capability evidence | Uncommitted | iOS audio/session/device evidence absent | Preserve Web/Android result; close iOS only with CFH-05 evidence |

No milestone is `ACCEPTED_AND_PUSHED` at the starting baseline. A handoff-only commit does not change that product milestone fact.

Required evidence supplement:

| Milestone | User-visible result | Main implementation surfaces | Test evidence | Browser/device evidence | Gap | Commit / push |
|---|---|---|---|---|---|---|
| M0 | Current roadmap and decision boundaries | roadmap, master plan, ADR-0031/0034/0037 | documentation guards/review records | not applicable | exact doc slice attribution | product milestone: no / no |
| M1 | Mobile login/bootstrap/device and idempotent Sense rating | mobile controllers, device/idempotency services, foundation migration/routes | `MobileApiFoundationTest` report | historical API/browser evidence; no device requirement | fresh rerun and exact shared seams | no / no |
| M2 | Operation history with linear undo/redo foundation | operation models/service/controllers, ledger migration | `MobileOperationLedgerTest` report | historical API evidence | depends on M1 commit boundary | no / no |
| M3 | Downloadable article and short review packages | mobile package controllers/services | `M3MobileDownloadPackageTest` report | historical rendered/mobile evidence | shared mobile attribution | no / no |
| M4 | Queued action sync with retry/conflict feedback | queued sync service/controller, simulator Vue component | M4 sync/migration tests and report | historical browser simulator evidence | depends on M1–M3 | no / no |
| M5 | Touch-friendly reader/reviewer | reader/reviewer Vue surfaces, touch selection policy | M5 PHP/Node guards and report | historical real browser touch evidence | fresh high-risk browser batch | no / no |
| M6 | Backup inventory/preview, recoverable restore design, article health | backup/restore services, write fence, health controller/UI | historical 62-test report; fresh 44/123 in this session | historical preview/cancel browser batch; no restore executed | full M6 matrix and attribution | no / no |
| M7 | Connected Android login/review | `mobile/android`, shared mobile API/UI | M7 server/Node guards and report | historical Android 12 emulator | fresh build/emulator and generated boundary | no / no |
| M8 | Limited offline packages, queue and reconnect | mobile offline repository/storage/UI | mobile Node tests and M8 report | historical disconnect/reconnect browser + Android | fresh end-to-end rerun | no / no |
| M9 | Local iOS shell, secure token bridge and text import | `mobile/ios`, M9 import/API surfaces | M9 PHP/Node/build/sync evidence | Apple login page only; no Xcode signing/device/TestFlight | full iOS capability cluster | no / no |
| M10 | Unified search, Sense tags and browser foundation | criteria/search/tag services and browser/tag Vue components | M10 PHP/UI guards and report | historical browser evidence | exact M10/M15 ownership | no / no |
| M11 | Manual review scheduling/control | manual-operation service/controller and review UI | M11 tests/report plus protected FSRS evidence | historical browser evidence | shared FSRS/ReviewLog attribution | no / no |
| M12 | Custom/special study sessions | custom study services/controller/UI | M12 foundation/query/execution tests and report | historical browser evidence | depends on M10/M11 | no / no |
| M13 | Review settings and workload planner | scheduling settings/workload services and admin panels | M13 tests/report | historical browser evidence | fresh protected scheduling run | no / no |
| M14 | Statistics and Card Info V3 | statistics/read-model services and chart/UI | M14 PHP/Node tests/report | historical browser evidence | shared query attribution | no / no |
| M15 | Knowledge-hygiene browser workflows | hygiene service/controller/panel | M15 PHP/Node tests/report | historical browser evidence | overlaps M10/M16 | no / no |
| M16 | Portable Anki/JSON/CSV data flows | portable-data/import-export services and panel | M16 tests/report | historical non-destructive browser evidence | fresh parser/security verification | no / no |
| M17 | Review automation, previous-card and accessibility on Web/Android | review experience services/components and mobile UI | M17 PHP/Node tests/report | historical Web + Android; no iOS | iOS evidence follows M9 | no / no |
| M18 | Media, pronunciation and offline audio on Web/Android | media controllers/services/components/cache | M18 PHP/Node tests/report | historical Web + Android; no iOS | iOS audio/session evidence | no / no |

## 4. Current Worktree Inventory

Counts below come from the expanded starting inventory. Feature rows overlap by design; they are lenses over the same 454 files and must not be summed. Readiness applies to the pre-existing assets, not to this new handoff document.

| Group | Observable inventory | Readiness | Handling rule |
|---|---:|---|---|
| Web / Laravel core | 130 files: 51 tracked-status, 79 untracked | needs verification | Split by milestone/contract; never bulk-stage |
| Vue frontend | 39 files: 21 tracked-status, 18 untracked | needs verification | High-risk reader/reviewer pages require build plus real browser evidence |
| Android | 56 untracked files under `mobile/android` | partial verification | Distinguish authored source/config from generated assets before staging |
| iOS | 22 untracked files under `mobile/ios` | partial verification | Keep local; do not call release-complete without Apple capability evidence |
| Shared mobile shell | 19 untracked files under `mobile` outside platform directories | needs verification | Attribute dependencies/package lock/config to M7–M9 slices |
| Database / backup / restore lens | 32 files: 3 tracked-status, 29 untracked | needs verification | High risk; use exact M6 allowlist and focused tests |
| Sync / operation ledger lens | 12 untracked files | needs verification | Preserve idempotency, isolation and dependency ordering |
| Search / tag / browser lens | 15 files: 5 tracked-status, 10 untracked | needs verification | Attribute across M10/M15 before staging |
| Statistics / workload lens | 9 files: 4 tracked-status, 5 untracked | needs verification | Verify shared metric/query contracts |
| Import / export / Anki lens | 17 files: 2 tracked-status, 15 untracked | needs verification | Treat archives/parser paths as data-security sensitive |
| Media / audio lens | 10 untracked files | partial verification | Web/Android evidence exists; iOS capability evidence absent |
| Tests | 79 files: 23 tracked-status, 56 untracked | needs verification | Commit with owning implementation slice, not as a detached test dump |
| Documentation | 92 files: 13 tracked-status, 79 untracked | needs verification | Historical reports require link and implementation consistency checks |
| Migrations | 9 untracked files (inventory category) | needs verification | Review individually; never execute against real/development data in closeout |
| Generated | 1 file (inventory category) | do not commit until identified | Confirm reproducibility and repository policy first |
| Temporary / unattributable | `.reasonix` inventory artifacts plus any task-external residue | unattributable / do not commit | Leave untouched; do not infer ownership |
| Deleted guards | 2 tracked deletions: `tests/js/GlmCompositeTaskHardRulesDocsGuard.test.mjs`, `tests/js/GlmSingleAgentWorkflowDocsGuard.test.mjs` | needs verification | Confirm replacement-guard convergence before committing deletion |

There is no pre-existing product-code group marked commit-ready in this final session. The only safe commit candidate created here is this handoff file.

## 5. Completed In This Final Session

- Fetched `origin` with prune and proved local `master` initially matched the fetched remote exactly.
- Saved a NUL-safe, expanded starting inventory outside the repository.
- Confirmed zero conflict paths and recorded `.env` status without reading it.
- Inspected the SQL dump grammar boundary. The current inspector rejects database selection, cross-database qualified objects, filesystem export/directory options, tablespaces, FEDERATED/remote connections, `CREATE ... SELECT`, executable-comment setting abuse, and non-literal insert tails.
- Inspected restore-time write exclusion. The current path activates an external fence, blocks unsafe HTTP methods, intercepts non-read-only Laravel queries on existing and future connections, blocks console entry, enters maintenance, and waits for active database statements plus InnoDB transactions to remain at zero before schema replacement.
- Freshly ran `SqlDumpInspectorTest`, `DatabaseRestoreProcessTest`, `RestoreWriteFenceTest`, `BackupRestoreServiceTest`, and `ExecuteBackupRestoreTest`: **44 passed / 123 assertions**.
- Created this final handoff. No product code, migration, user data, database schema, external account, or deployment state was changed.
- Did not modify `docs/plans/current-working-handoff.md` because it already contains complex pre-existing user edits; resume directly from this file.
- Exactly committed and pushed the initial handoff as `460b275862b992fbbc49087f2fa8347c997bb77a`; the follow-up revision only completes attachment-required handoff fields.
- MCP Chrome: not applicable; no user-visible page was changed. The supplied local account was not used, no account was created, and no login was attempted.
- Android/iOS: no device action in this final session; historical evidence and current gaps are recorded above.
- No product-code slice was safe to complete within the final Codex budget.

## 6. Blocking Issues

### B1 — Local implementation is not on GitHub

Starting `HEAD` and fetched `origin/master` were identical while 454 changed files existed locally. Broad M0–M18 implementation therefore remains a local asset. This is blocking for declaring the product delivered or remotely recoverable.

- Severity / impact: critical delivery risk; another machine cannot recover the product implementation from GitHub.
- Evidence: starting refs matched at `1c17625a...`; expanded dirty inventory was 454 files.
- Recommended role / next minimum task: repository tech lead; execute CFH-01.
- Forbidden shortcut: bulk-stage or describe the handoff-only push as a product delivery.

### B2 — Attribution and commit boundaries are incomplete

Multiple milestones share tracked files, and most new implementation is untracked. Two tracked guard tests are deleted while replacement guards appear to be part of a later workspace-convergence slice. Commit ownership must be proved per slice; bulk commit is unsafe.

- Severity / impact: high; a mixed commit could lose reviewability or capture unrelated user assets.
- Evidence: 110 modified, 2 deleted and 342 untracked files; shared Laravel/Vue seams span milestones.
- Recommended role / next minimum task: read-only repository archaeologist; produce the CFH-01 exact map.
- Forbidden shortcut: `git add .`, `git add -A`, cleanup/reset/stash, or milestone attribution from filenames alone.

### B3 — SQL dump execution boundary

Current result: **not blocking for the inspected grammar contract**, because the source and fresh 23-case inspector suite reject the named dangerous suffixes and cross-database forms. It becomes blocking again if the dump producer grammar changes, additional SQL forms are allowed, or the full M6 acceptance matrix fails. No real restore was executed in this session.

- Severity / impact: critical if regressed; an accepted dump could mutate another schema, filesystem or server state.
- Evidence: `SqlDumpInspector` anchored allowlist plus 23 fresh cases within the 44-test run.
- Recommended role / next minimum task: Laravel security reviewer; complete CFH-02's full matrix.
- Forbidden shortcut: accept a dump because isolated import succeeds, broaden grammar without adversarial tests, or execute a real restore as proof.

### B4 — In-flight writes during restore

Current result: **not blocking for Laravel-managed and observed MySQL writers**, because the external fence, per-query guard, maintenance transition, active PROCESSLIST/INNODB_TRX count, stable-zero window, and fresh tests close the reviewed race. It remains a deployment assumption that all application writers use the guarded Laravel process boundary or are visible to MySQL process/transaction inspection. Any independent writer that bypasses both conditions is blocking until incorporated into the quiescence contract.

- Severity / impact: critical if an unobserved writer exists; concurrent data could be silently lost during schema replacement.
- Evidence: middleware, existing/future connection guard, console fence, stable-zero query, and fresh fence/process/orchestration tests.
- Recommended role / next minimum task: backend/concurrency reviewer; inventory every writer before CFH-02 release.
- Forbidden shortcut: rely on maintenance mode alone, check only request entry, or substitute a delay for writer observation.

### B5 — iOS capability cluster

M9, and the iOS portions of M17/M18, lack Mac/Xcode signing, Apple account, device, TestFlight and App Store evidence. This is a genuine external capability blocker, not a documentation gap.

- Severity / impact: high release blocker; iOS users cannot receive a verified build.
- Evidence: local code/build/sync records exist, but the named capability evidence is absent.
- Recommended role / next minimum task: macOS/Xcode iOS engineer; execute CFH-05.
- Forbidden shortcut: infer device/store acceptance from source, Web, Android, screenshots or an unauthenticated App Store Connect page.

### B6 — Current full verification is incomplete

This session reran only the restore-focused 44-test slice. It did not rerun every M0–M18 suite, the frontend production/development build, all protected regressions, Android build/emulator batches, or real browser batches. Historical reports must not be reported as fresh execution.

- Severity / impact: high confidence gap; local changes may have drifted after their reports.
- Evidence: only the five named restore test files were executed in this session; no UI was changed.
- Recommended role / next minimum task: QA lead paired with each owning implementer; verify one exact commit slice at a time.
- Forbidden shortcut: weaken tests, hide failures, or use API/source inspection as real-page evidence.

### B7 — Unattributable and generated assets

`.reasonix` artifacts and the inventory's generated file were not attributed. They must not be staged without an explicit owner and repository policy check.

- Severity / impact: medium; repository pollution or accidental local metadata disclosure.
- Evidence: three `.reasonix` JSON files and one inventory-generated category entry are present in the starting inventory.
- Recommended role / next minimum task: CFH-01 owner; identify or mark permanently excluded.
- Forbidden shortcut: treat every untracked file as authored product source.

## 7. Exact Next Tasks

### CFH-01 — Freeze an exact commit map

- task_id: `CFH-01`.
- Goal / prerequisite: produce a reviewable ownership/dependency map; start only after confirming current refs and comparing the external snapshot.
- Scope: read-only attribution of all 454 starting files to one milestone/slice or `unattributable`.
- Allowlist: Git status/diff; `docs/DOCUMENTATION_INDEX.md`; current handoff; roadmap; acceptance reports; `scripts/workspace-inventory.mjs`; its test. No product edits.
- Key surfaces: `collectWorkspaceInventory()`, roadmap Open Work Registry, current handoff pointers.
- Forbidden scope: product edits, migration execution, cleanup, staging or committing unattributed files.
- Tests: `node --test tests/js/WorkspaceInventory.test.mjs`; `git diff --check` on any documentation-only change.
- Browser/device: none.
- Completion standard: every starting path has one owner/readiness label and an ordered exact-path commit slice, with overlaps explicitly deferred.
- Stop conditions: any same-line user change conflicts with a proposed slice; a path has multiple owners with no safe dependency order; inventory differs from the saved baseline without an explained current-turn change.

### CFH-02 — Revalidate and commit M6 restore safety as an isolated slice

- task_id: `CFH-02`.
- Goal / prerequisite: make M6 a self-contained verified commit; requires the CFH-01 ownership map and unchanged restore contracts.
- Scope: M6 backup/restore only; do not execute restore against development/real data.
- Allowlist: `app/Services/{BackupService,BackupRestoreService,DatabaseRestoreProcess,RestoreWriteFence,SqlDumpInspector}.php`, `app/Jobs/ExecuteBackupRestore.php`, `app/Http/{Controllers/BackupController.php,Middleware/RejectWritesDuringRestore.php}`, M6-specific config/routes/provider/bootstrap seams, corresponding backup/restore tests, ADR-0036, M6 plan/reports, and the exact admin backup UI files identified by CFH-01.
- Key functions/components: `SqlDumpInspector::inspect*`, `DatabaseRestoreProcess::{validateIsolated,waitForQuiescence,replaceActive}`, `RestoreWriteFence`, `BackupRestoreService::runLockedOperation`, admin restore preview.
- Forbidden scope: real restore/schema reset, new SQL grammar, new endpoint/migration, non-M6 provider/bootstrap changes.
- Tests: full M6A/M6B focused matrix from the acceptance reports; at minimum rerun the five tests used in this session plus backup publication/HTTP tests; run protected Review FSRS, scheduling and WordSense regressions if shared seams changed; `npm run development` for UI changes.
- Browser/device: official browser, testing-bound listener, preview/cancel only; never click real restore execution.
- Completion standard: exact cached diff contains M6 only; all applicable tests/build/browser preview pass; normal push succeeds without branch divergence.
- Stop conditions: unsafe SQL form accepted; writer fence/quiescence test fails; testing server identity is unproved; allowlist expands beyond M6; any real database target would be touched.

### CFH-03 — Commit M1–M5 server/mobile contracts in dependency order

- task_id: `CFH-03`.
- Goal / prerequisite: publish M1–M5 as dependency-ordered reviewable slices; requires CFH-01 and no unresolved M6 overlap.
- Scope: M1 API foundation → M2 ledger → M3 packages → M4 sync → M5 touch UI, one commit or reviewable commit series per dependency slice.
- Allowlist: only paths assigned to M1–M5 by CFH-01, including matching migrations and tests. Exclude M6+, iOS, statistics, portable data and media files.
- Key functions/components: mobile auth/bootstrap/rating controllers, idempotency/ledger/package/sync services, `ReaderTouchSelectionPolicy`, reader/reviewer touch surfaces.
- Forbidden scope: new API/payload, FSRS/ReviewLog semantics, migration redesign, Android/iOS feature additions.
- Tests: named acceptance tests for each milestone; protected rating/FSRS/WordSense tests when formal scoring seams are included; frontend build for M5.
- Browser/device: M5 rendered reader/reviewer interaction on a testing-bound server; Android not required until CFH-04.
- Completion standard: each dependency slice has an exact cached diff, current passing evidence and normal push before the next slice begins.
- Stop conditions: formal rating bypasses `FsrsSchedulingService`; user/language isolation changes; migration ownership is unclear; shared file contains inseparable M6+ edits.

### CFH-04 — Revalidate Android/offline and commit M7–M8 platform slices

- task_id: `CFH-04`.
- Goal / prerequisite: publish verified Android and offline slices; requires pushed M1–M5 foundations and CFH-01 platform ownership.
- Scope: authored `mobile/android`, shared Capacitor shell, offline cache/queue and corresponding docs/tests.
- Allowlist: exact M7/M8 paths from CFH-01; exclude `mobile/ios` and M9 release material.
- Key components: Android `MainActivity`/`SecureTokenPlugin`, mobile API/storage/offline repository/UI and sync convergence.
- Forbidden scope: signing secrets, iOS files, new native feature, protocol or server payload changes.
- Tests: mobile Node tests, frontend build, Capacitor sync/build, Android unit/instrumentation tests available locally, and server-side M7/M8 API tests.
- Browser/device: Android 12+ emulator/device, testing-bound server; repeat connected login/rating and offline queue/reconnect conflict flows.
- Completion standard: authored/generated boundary documented; build and required device flows pass; exact commits push normally.
- Stop conditions: generated/source boundary is unclear; credentials or signing artifacts appear; server-bound testing evidence is missing; any pre-existing user device/session would be altered.

### CFH-05 — Close the iOS capability cluster

- task_id: `CFH-05`.
- Goal / prerequisite: obtain missing M9/M17/M18 iOS evidence for the existing implementation; requires a Mac, supported Xcode and authorized Apple capabilities.
- Scope: verify existing M9 implementation; no new product scope.
- Allowlist: `mobile/ios`, M9-specific shared mobile files, iOS release/privacy docs, M9/M17/M18 iOS acceptance reports.
- Key components: `MyViewController`, `SecureTokenPlugin`, AppDelegate/project sources, text import, offline repository, media/accessibility UI.
- Forbidden scope: new product behavior, secret persistence, signing material in Git, unrelated Web/Android changes.
- Tests: mobile tests/build/Capacitor sync, Xcode build/test, Keychain persistence, file import, connected/offline review, accessibility and audio session behavior.
- Browser/device: Mac with supported Xcode, simulator and/or device, Apple signing account, TestFlight/App Store Connect as required.
- Completion standard: named build, signing, device and store checks have reproducible evidence; all deferred iOS checks are cleared or individually remain BLOCKED without overstating completion.
- Stop conditions: signing identity/profile unavailable; official Apple services inaccessible; secrets would enter repository/logs; observed behavior requires a product decision outside accepted roadmap.

## 8. Recommended Agent Assignment

- ReasoniX / DeepSeek Flash: CFH-01 inventory sub-batches only after path groups and output schema are frozen; it must not make product edits or semantic ownership decisions alone.
- DeepSeek Pro: CFH-02 security/concurrency review and CFH-03 shared-seam reasoning, with exact allowlists and independent tests.
- OpenCode: implementation/test execution for an already frozen CFH-02/03/04 slice; one slice at a time, exact-path staging only.
- Read-only review Agent: mandatory for CFH-01 attribution and the final M6 cached diff; it owns scope/asset leakage checks.
- Browser experience acceptance Agent: M5, M6 preview-only, and Web portions of M10–M18 after a server-bound testing listener is proved.
- macOS/Xcode human or Agent: CFH-05 exclusively; must own signing/device/TestFlight/App Store evidence without exposing credentials.

Do not run these tasks in parallel when they touch shared files. CFH-01 is the prerequisite; CFH-02 and CFH-03 may proceed only where the map proves disjoint ownership.

## 9. Safe Resume Instructions

1. Read `AGENTS.md`, `docs/DOCUMENTATION_INDEX.md`, this file, and only the contract/report for the selected task.
2. Run `git fetch origin --prune`; record branch, `HEAD`, fetched `origin/master`, ahead/behind and conflict paths.
3. Compare current NUL-safe status with the external starting snapshot. Treat every unexplained difference as user-owned until attributed.
4. Do not read `.env`; only check whether it appears in status.
5. Select exactly one task ID from Section 7. Write its target, exclusions, allowlist, data flow, verification and stop conditions before editing.
6. Read current files and diffs before touching a tracked modified path. Preserve unrelated hunks and do not clean, reset, restore, stash or bulk-stage.
7. Run the task's focused tests outside implementation output. For visible UI, use a real rendered browser/device and a server proven bound to the testing database before any write.
8. Stage exact paths only. Inspect `git diff --cached --check`, `git diff --cached --stat`, and the full cached diff before committing.
9. Fetch again before a normal push and stop if the branch is behind or the staged scope is mixed. Never force-push.
10. Update this handoff with new verifiable facts; do not rewrite historical status to hide deferred capability evidence.

## 10. Compliance Record

- FastCtx used for all local file inspection, search, inventory and shell commands: **Yes**.
- Official plugin priority: **Observed**; no browser action was required in this final session because no visible product slice was changed.
- External baseline snapshot created before edits: **Yes**.
- `.env` content read: **No**.
- Destructive Git commands (`reset`, `restore`, `checkout`, `stash`, `clean`, `rebase`, force): **No**.
- Destructive database commands or real restore execution: **No**.
- Product code changed in this final session: **No**.
- `.env` modified: **No**.
- User-owned pre-existing changes overwritten or discarded: **No**.
- Planned-outside files modified: **No**; only the required final handoff was added/revised.
- Bulk staging: **No**.
- Notification script / `notify.ps1`: **No**, because the explicit final-task request forbids it.
- DCP: **No**.
- `reset` / `stash` / `clean`: **No**.
- Test failures hidden: **No**; the one FastCtx background-job storage permission error was reported by the tool and the same test command then passed in foreground.
- MCP Chrome impersonated by API/source inspection: **No**; Chrome was marked not applicable rather than claimed.
- Subagents: **No**.
- Real browser/device action in this final session: **No**; no UI behavior was changed and the task prioritized preservation/hand-off.

## 11. Final Conclusion

**HANDOFF_READY_WITH_BLOCKERS**

The local work is preserved, the resume order and exact stop conditions are documented, and the two explicitly requested restore risks have current passing evidence. Delivery is not complete because the broad local implementation is not yet attributed/committed/pushed, the full current M0–M18 verification matrix was not rerun, and the iOS capability cluster remains externally blocked.
