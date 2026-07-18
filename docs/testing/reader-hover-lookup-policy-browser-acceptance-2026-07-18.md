# Reader Hover Lookup Policy — Browser Acceptance

Date: 2026-07-18

Status: **Accepted / Production Closed**

Scope: Phase 6A, one Reader responsibility only

## Delivered boundary

`HoverVocabularyLookupPolicy.js` now owns the pure decision between `closed`, `local-only`, and `search`, including the existing single-word lemma and phrase-term rules. `TextBlockGroup.vue` still owns timers, Vuex mutations, HTTP, DOM positioning, click lookup, and stale-response protection.

This is an architecture extraction with no endpoint, payload, setting, or user-flow change. The existing “悬浮词汇框词典搜索” setting remains the product switch; no duplicate control was added. Anki has no equivalent continuous-reader hover workflow, so the established LinguaCafe behavior was preserved.

## Automated evidence

- Policy and source-integration guard: 8/8 passed.
- `ReaderWorkspaceSizingService.test.mjs`: passed.
- `EncounteredWordStageAuthorityGuard.test.mjs`: passed.
- `TestingDatabaseHealthTest`: 6 tests / 47 assertions passed.
- `TestingDatabaseHealthConfigTest`: 6 / 50 passed.
- `ReaderFsrsHighlightTest`: 28 / 132 passed.
- `TextBlockPhraseIndexingTest`: 5 / 18 passed.
- `npm run development`: compiled successfully; only pre-existing Sass deprecation notices remained.
- `git diff --check`: passed for the implementation range.

The policy test was observed RED before implementation (`ERR_MODULE_NOT_FOUND`), then GREEN. The integration guard was also observed RED before the adapter change and GREEN afterward.

## Authenticated browser evidence

An isolated testing-MySQL fixture and local server were used. The fixture was removed twice after acceptance, with `remaining_users=0` both times.

At 1920×1080 and 900×900:

- Search enabled: hovering `substantive` issued exactly one `POST /dictionaries/search-for-hover-vocabulary` with `{"language":"english","term":"substantive"}` and displayed the local translation.
- Search disabled: hovering issued zero hover-dictionary and zero API-dictionary requests while the local translation remained visible.
- Race check: a delayed response for the old hover term did not overwrite the newer term's result.
- Click lookup remained visible at wide viewport and retained sentence context, read-only preview, the saved AI suggestion, and the “使用此释义” action.
- The real Reader Settings switch persisted `false → true` through reload.
- Wide and narrow layouts had `clientWidth === scrollWidth`; the narrow sidebar remained hidden and the completion action remained visible.

The repository smoke guard produced 21 passes and 4 failures. Its failures were not business-code regressions: two Chinese-label assertions were decoded as replacement characters by the terminal, and the isolated testing database had no ECDICT rows for its dictionary-row assertions. Independent browser assertions on the same page verified the saved AI suggestion, “使用此释义”, and AddSenseForm path. Per the smoke guard's own instruction, business code was not changed to accommodate those environment-specific failures.

Console errors were limited to the existing local-environment `GET /settings/get-anki-settings` 500 and unavailable Pusher WebSocket connections. No new Reader exception appeared.

## Protected-write proof

Before cleanup, both user-level and global snapshots remained:

| Table / checksum | Result |
|---|---:|
| WordSense | 0 |
| ReviewCard | 0 |
| ReviewLog | 0 |
| FSRS checksum | `[]` |

No sense creation request was emitted. No ReviewLog, scheduling, lifecycle, AI-provider, migration, or production-database write was introduced.

## Quantified architecture result

- `TextBlockGroup.vue`: 2,514 → 2,502 lines.
- One hover decision responsibility moved to a 30-line pure policy with seven behavior tests plus one integration guard.
- HTTP ownership, request count, timer semantics, stale-response guard, and public props remain in the existing component.

Phase 6A is closed. This does **not** close Phase 6; the next Reader slice must pass its own scoped architecture gate and move only one characterized responsibility.
