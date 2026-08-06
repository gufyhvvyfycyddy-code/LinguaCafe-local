---
document_status: completed
program_id: linguacafe-recovery-publication-2026-08
authoritative_handoff: docs/plans/codex-final-handoff-2026-08-04.md
active_task: NONE
auto_advance: false
product_code_authorized: false
supervisor_unlock_required: false
closed_on: 2026-08-06
---

# LinguaCafe Recovery And Publication Program — Closed Record

## 1. Final reality

This program existed to protect and publish a large dirty worktree without
resetting, cleaning, stashing, overwriting, or silently dropping user assets.
The M6A–M6D recovery-publication procedure is closed. The M6 restore contract,
acceptance evidence, and closeout governance are formal records in Git history.
The remaining recovered M1–M18 working-tree assets have been identified,
classified, and protected, but they still require functional-slice validation
and publication. Closing the recovery program must not be interpreted as a
claim that every workspace asset is already represented in Git history. Local
agent sessions, temporary screenshots/logs, one-off extraction scripts, and
patch transport files remain local-only and are ignored.

The program is no longer an active product roadmap. New work starts from
`AGENTS.md`, `docs/DOCUMENTATION_INDEX.md`, `docs/CURRENT_AI_CONTEXT.md`, and the
Open Work Registry in `docs/plans/linguacafe-master-plan.md`.

## 2. Closed publication slices

| Slice | Final status | Current authority |
|---|---|---|
| CFH-01 / CFH-01B | Accepted | ownership map and recovery handoff |
| CFH-02A / CFH-02A-R1 | Accepted | exact M6 manifest and publication plan |
| CFH-02B-M6A | Accepted / Published | M6A publication acceptance and MCP evidence |
| CFH-02B-M6B | Accepted / Published | ADR-0055, M6B acceptance report and MCP evidence |
| M6C | Accepted / Published | M6 implementation plan and M6C acceptance report |
| M6D | Accepted / Published | M6 implementation plan and M6D acceptance report |
| Remaining recovered M1–M18 working-tree assets | Identified / Classified / Protected; publication pending | functional-slice validation, exact-path staging, and later commits |

M6A–M6D are therefore not candidate tasks. A real regression may open a new,
small repair task, but no Agent may replay the old publication sequence or
restore the superseded admin-only / user-preview M6B contract.

## 3. Current M6B contract

ADR-0055 is the current public restore authority:

- every authenticated user has the same backup/restore capability;
- unauthenticated access is rejected;
- there is no user-visible restore preview or client preview token;
- the user must type exact `RESTORE` and click the final confirmation button;
- the server performs containment, manifest, checksum, table, SQL, size, disk,
  immutable-pin, idempotency, locking, safety-snapshot, write-fence,
  maintenance, isolated-validation, rollback, and health checks;
- desktop and phone responsive web flows are covered by real MCP Chrome
  evidence in an isolated testing environment with fake restore processes.

ADR-0036 keeps the earlier design history. Where it describes admin-only or
preview-token behavior, ADR-0055 supersedes it.

## 4. Retained safety rules

- No real restore, migration, backfill, drop, truncate, `migrate:fresh`,
  `migrate:refresh`, `migrate:reset`, or `db:wipe` is authorized by this record.
- `.env`, secrets, credentials, local session metadata, temporary screenshots,
  raw traces, and patch transport files must not be committed.
- Tests and browser evidence must use the dedicated testing database and fake
  dump/restore processes.
- Git staging remains exact-path only; `git add .`, `git add -A`, force push,
  reset, clean, stash, and history rewriting remain prohibited unless a future
  explicit user instruction changes the applicable rule.
- This closed program has `auto_advance: false`; it does not authorize another
  milestone.

## 5. What remains open

The recovery/publication program itself has no active task. Project-wide open
items are limited to the current Open Work Registry, chiefly:

- M9 iOS/Xcode/signing/device/TestFlight/App Store evidence;
- runtime AI-provider activation, which requires explicit provider/secret/
  external-data/cost authorization;
- selected maintenance bugs;
- Reasonix/DevSpace/browser supervision toolchain root fixes.

## 6. Historical documents

The ownership map, exact M6 manifest, CFH-02 publication plan, milestone lock,
acceptance reports, and original handoff remain as audit evidence. Historical
status phrases such as `candidate_not_authorized`, `awaiting_web_acceptance`, or
`PUSHED_AWAITING_ACCEPTANCE` describe earlier checkpoints only and must not be
used as the current project state.
