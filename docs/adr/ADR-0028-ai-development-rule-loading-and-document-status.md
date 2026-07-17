# ADR-0028: AI Development Rule Loading And Document Status

## Status

Accepted — 2026-07-17

## Context

LinguaCafe is a long-lived project with many plans, handoffs, ADRs, test playbooks, historical reports, and a large operational collaboration document. The previous root `AGENTS.md` mixed project scope, module details, fixed test commands, Skill instructions, architecture gates, and stop rules. The detailed collaboration document exceeded normal task context needs and contained active, superseded, and historical workflow material in one file.

This created four recurring risks:

1. agents loaded excessive context before a small task;
2. documents with names such as “current”, “next”, or “final” could be mistaken for current authority;
3. stable rules, temporary plans, acceptance reports, and history did not have one explicit priority model;
4. important safety and domain boundaries could be buried by phase-specific detail.

The 2026-07-17 rule rebuild used the project-provided subtitle sources and the local Architecture Skills package. Those methods consistently favor short root rules, progressive disclosure, stable specs for durable behavior, ADRs for consequential decisions, and executable harnesses for critical invariants.

## Decision

### 1. Short root entry

`AGENTS.md` is the single short root entry for project identity, rule priority, universal task entry, stable product boundaries, dangerous operations, protected state, and verification expectations.

`CLAUDE.md` imports `AGENTS.md` and must not duplicate the rule body.

### 2. Canonical detailed rule authority

`docs/architecture/ai-development-rule-system.md` is the authoritative detailed rule system. It owns:

- rule priority and conflict handling;
- document status taxonomy;
- progressive task loading;
- Skill routing;
- local architecture preflight;
- feature/refactor separation;
- interface, compatibility, and migration rules;
- harness and evidence requirements;
- documentation promotion and final review.

### 3. Canonical domain glossary

`CONTEXT.md` is the canonical glossary for `EncounteredWord`, `WordSense`, `WordSenseOccurrence`, `ReviewCard`, `ReviewLog`, and the separation between reading familiarity, sense state, formal review, lifecycle, and history.

It defines terminology, not current phase status.

### 4. Subtitle methodology summary

`docs/architecture/subtitle-guided-development-summary.md` is the short task-entry summary of the project-provided subtitle methodology. Raw transcripts are not loaded by default and are not committed as project rules.

### 5. Document status and progressive disclosure

Documents are classified as root entry, current fact, glossary, stable spec/module contract, ADR, temporary implementation plan, acceptance report/playbook, operational appendix, or history/superseded.

Every file-changing or acceptance task loads the root entry, relevant Skill, canonical detailed rule system, Git/worktree facts, and the exact source/tests involved. Other documents are selected by task type:

- domain or state ownership → `CONTEXT.md`;
- rules/spec/architecture/harness → subtitle methodology summary;
- phase selection or product sequencing → documentation index, then current handoff, with master plan/roadmap only when needed;
- bounded module work → owning contract, accepted ADRs, code, tests, and harness; current-state ledgers only when authorization or status is material.

Large handoffs, ledgers, and indexes are searched and read by relevant section first. They are not a universal bundle.

### 6. Legacy operational appendix

`docs/plans/vibe-coding-collaboration-rules.md` is retained as a **Detailed legacy operational appendix** because current playbooks and old plans still cite specific sections. It is no longer default context or the general hard-rule authority.

A section in that appendix applies only when a current task, module contract, or playbook explicitly cites it. Higher-priority current rules and code facts override conflicts.

### 7. Executable governance

The document architecture is protected by Node guards that check:

- root-rule size and entry links;
- canonical domain terms;
- document status model and Skill routing;
- subtitle source list and methodology boundaries;
- one canonical task-loading order and conditional handoff/master/roadmap gates;
- hard-rule admission and guard-economics requirements;
- current index references;
- explicit legacy-appendix downgrade;
- discontinued workflow isolation.

## Alternatives Considered

### A. Keep all rules in the existing collaboration document

Rejected. It preserves detail but forces excessive context loading and mixes permanent, phase-specific, superseded, and historical instructions.

### B. Delete the existing collaboration document immediately

Rejected. Current plans and playbooks still cite procedural sections. Immediate deletion would break discoverability and could silently remove safeguards before they are migrated.

### C. Copy the same rules into AGENTS.md, CLAUDE.md, the index, and the master plan

Rejected. Duplication would create drift and make conflict resolution harder.

### D. Load all subtitles, ADRs, plans, and history for every task

Rejected. More context does not guarantee better compliance; it can hide the most relevant rules and current facts.

## Consequences

### Benefits

- Agents start from a small, predictable context selected by task type.
- Large handoffs and ledgers no longer dominate bounded module work.
- Current fact, durable rule, temporary plan, evidence, and history are distinguishable.
- Domain ownership is defined once.
- Important invariants can be moved from prose into executable guards.
- Old detailed procedures remain available without dominating every task.

### Costs

- Existing references to old collaboration sections cannot all be removed at once.
- Maintainers must keep the documentation index and guards aligned when the rule architecture changes.
- Some older plans will continue to contain historical language until touched by a related task.

## Validation

- `tests/js/AiDevelopmentRulesGuard.test.mjs`
- `tests/js/GlmSingleAgentWorkflowDocsGuard.test.mjs`
- `tests/js/TargetModeCompositeTaskRuleGuard.test.mjs`
- `tests/js/MasterPlanIntegrityContract.test.mjs`
- local Markdown-link verification for modified rule/index documents
- `git diff --check`
- final diff review confirming no product business code changed

## Notes

This ADR changes documentation and AI task-loading architecture only. It does not authorize product features, schema changes, FSRS changes, ReviewLog changes, browser behavior changes, or the next product phase.
