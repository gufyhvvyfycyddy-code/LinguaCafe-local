# LinguaCafe Agent Entry Rules

> **Status**: Current root entry / hard safety boundary
> **Last updated**: 2026-07-17
> **Purpose**: Keep root context small. Detailed workflow lives in `docs/architecture/ai-development-rule-system.md`.

## 1. Project Goal

LinguaCafe is a long-lived, English-reading-centered learning system. Its formal review mainline is sense-only:

- `WordSense` is the meaning being learned.
- `ReviewCard` with `target_type=sense` is the formal review item.
- `EncounteredWord` supports reading familiarity and display state.
- `ReviewLog` records formal review and audit history.

Do not turn LinguaCafe into an Anki clone, restore legacy word cards as a new mainline, or add unrelated product/language scope without an explicit product decision.

## 2. Rule Priority

When instructions conflict:

1. Current explicit user decision and current task contract.
2. This file's safety and protection rules.
3. `docs/architecture/ai-development-rule-system.md`.
4. Stable module specs and accepted ADRs.
5. Current-state sources selected through `docs/DOCUMENTATION_INDEX.md`, after Git/code cross-check.
6. The explicitly authorized temporary plan and acceptance evidence for the current task.
7. Detailed operational appendices.
8. Historical or superseded documents.

Higher-priority rules win. For data safety, use the stricter rule. Code, tests, and Git establish implementation facts; they do not silently repeal an accepted product contract. Ask the user only when repository facts cannot resolve a choice that changes product behavior, data ownership, or long-term architecture.

## 3. Required Task Entry

Before changing files:

1. Read the relevant local Skill instructions.
2. Read `docs/architecture/ai-development-rule-system.md`.
3. Check branch, latest commit, working tree, and user-owned uncommitted changes.
4. Choose the smallest task route:
   - domain/data ownership: `CONTEXT.md`;
   - rules/spec/architecture/harness: `docs/architecture/subtitle-guided-development-summary.md`;
   - phase or priority selection: `docs/DOCUMENTATION_INDEX.md`, then the current handoff; load master plan/roadmap only when sequencing is part of the task;
   - bounded module work: the owning contract, accepted ADRs, relevant code/tests/harness; use the index only if ownership or current status is unclear.
5. Inspect the exact files to change and one established local pattern before implementation.

Do not load all plans, ADRs, reports, handoffs, or history by default. Stop loading context once ownership, scope, contracts, and verification are resolved. Historical documents never authorize work.

## 4. Development Flow

1. Resolve current code and document facts.
2. Classify the task and run the local architecture preflight.
3. Freeze allowed files, forbidden scope, tests, browser checks, and stop conditions.
4. Implement only the current task.
5. Verify with commands, tests, browser actions, and data evidence appropriate to the change.
6. Review the final diff against scope and contracts.
7. Promote only accepted, stable decisions to a spec or ADR.

AI self-report such as “done” or “should work” is not evidence.

## 5. Stable Product Boundaries

Unless explicitly superseded by a newer accepted decision:

- Daily formal review remains sense-card first.
- Do not create new legacy word cards.
- Do not mix `EncounteredWord`, `WordSense`, `WordSenseOccurrence`, `ReviewCard`, and `ReviewLog` responsibilities.
- Reading familiarity is not formal review state.
- Do not change FSRS semantics, ReviewLog lifecycle, data ownership, database schema, or migration strategy without explicit authorization and compatibility evidence.
- Public APIs, payloads, imports/exports, and persisted formats require a compatibility and migration plan before breaking changes.
- Feature delivery and broad refactoring are separate tasks. Necessary small structural changes must be disclosed and tested.

Canonical terms are in `CONTEXT.md`.

## 6. Safety And Protected State

Never:

- Read, modify, expose, or commit environment secrets or authentication material, except for the explicitly non-secret localhost acceptance fixture in Section 8.
- Run `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe`, drop, truncate, or equivalent destructive database commands.
- Use `--force`, force-push, `git add .`, or `git add -A`.
- Overwrite, discard, stage, or commit user-owned uncommitted changes.
- Process `.omo/`, `.playwright-cli/`, `nul`, database dumps, logs, or generated artifacts unless explicitly authorized.
- Run notification scripts, including `notify.ps1`.
- Treat user-local or agent-global convenience hooks as task authorization when they conflict with current task or project safety rules.
- Run DCP unless explicitly authorized for the completed task.
- Automatically enter the next phase.

Do not commit or push unless the current task explicitly asks for it. When authorized, stage exact files only.

## 7. Verification

- Run task-relevant executable guards and tests; do not weaken them to pass.
- Page, button, dialog, navigation, import, reader, and review flows require real browser interaction. API success or source inspection does not replace browser acceptance.
- Database-affecting work requires before/after evidence and user/language isolation checks.
- Report pass, failure, skip, block, and environment unavailability separately.
- Docs/rules-only tasks require document/guard/link checks and proof that no business code changed; product browser acceptance is not applicable.

## 8. Local Browser Acceptance Account

- Only on `http://127.0.0.1` or `http://localhost`, browser acceptance uses `1816529781@qq.com` / `100200hbt` first.
- If the email is absent, create it through the normal local registration flow. If it exists but the fixture password fails, reset only that local user's password while preserving its ID and learning data, then verify local authentication before acceptance.
- This fixture is never valid for a remote host or production. Do not copy it into application configuration, environment files, logs, or test fixtures.

## 9. Document Roles

- Root navigation and red lines: this file.
- Domain glossary: `CONTEXT.md`.
- Detailed rule system and task-loading matrix: `docs/architecture/ai-development-rule-system.md`.
- Current document map: `docs/DOCUMENTATION_INDEX.md`.
- Historical map: `docs/HISTORY_INDEX.md`.
- Detailed legacy operational appendix: `docs/plans/vibe-coding-collaboration-rules.md` — read only when a current document explicitly cites a section.

Changing this file requires explicit task authorization and rule-guard updates.
