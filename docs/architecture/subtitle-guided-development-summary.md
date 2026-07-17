# LinguaCafe Subtitle-Guided Development Summary

> **Status**: Current methodology summary / task entry reference
> **Last updated**: 2026-07-17
> **Purpose**: Provide a short, stable engineering summary so normal tasks do not load raw subtitle transcripts.

## 1. Source And Verification

The repository checkout contained **0** tracked `.srt`, `.vtt`, or `.ass` files when this rule rebuild was performed.

The current task provided the original subtitles in the companion uploaded archive:

- source archive: `Architecture_Skills_LinguaCafe_OpenTeam_2026-07-17.zip`;
- temporary extraction outside the repository: `/mnt/data/linguacafe_openteam_2026-07-17/subtitle-source/`;
- raw subtitle count: 9 files;
- raw subtitle line count: 11,156 lines.

All nine raw files were searched and scanned. The four files most directly related to spec, harness, long-lived boundaries, and architecture debt received focused close reading. The subtitle files and extracted copies are not committed to LinguaCafe.

This document records methodology, not product truth. Subtitle advice cannot override current code facts, accepted ADRs, or a newer explicit user decision.

## 2. Source File List

1. `10万代码量真实项目，我是如何防止AI把旧功能改坏的？.srt`
2. `AI 编程的 spec 到底该什么时候写？和先写文档完全相反.srt`
3. `AI可以帮你写代码，但帮不了你成为架构师.srt`
4. `AI编程别一开始就写太多spec，MVP阶段放开抡.srt`
5. `AI编程越写越乱？我用水桶装水，把边界讲透，快速认识spec与harness.srt`
6. `AI编程项目为什么总是烂尾？长期项目迭代先给 AI 画边界.srt`
7. `Vibe Coding 第二讲：像架构师一样用 AI 做复杂产品.srt`
8. `你写了一堆文档AI还是不听话？问题不在文档本身.srt`
9. `答应我，别再和AI一起拉屎了；Vibe Coding如何避免屎山.srt`

## 3. MVP Versus Long-Lived Projects

### MVP/exploration

- Keep architecture and spec density low enough to preserve learning speed.
- Freeze product identity, irreversible safety boundaries, and the minimum interfaces needed to test the idea.
- Do not describe untested implementation guesses as permanent architecture.
- A temporary plan may change rapidly and should not be treated as a stable spec.

### Long-lived project

- Repeatedly validated decisions must be written down so later agents do not reopen them.
- Stable responsibilities, public contracts, data ownership, and critical invariants belong in module specs or accepted ADRs.
- Old implementation plans and handoffs must not remain current task entry points.
- LinguaCafe is now a long-lived project, so continuing in unbounded MVP mode would amplify debt.

## 4. Plan, Spec, ADR, Harness, And Evidence

### Temporary plan

Describes how one bounded task may be implemented. It may contain file lists, sequence, experiments, and temporary choices. It expires or becomes historical when the task closes.

### Stable spec/module contract

Describes observable behavior, responsibility, boundaries, invariants, and why later changes must preserve them. It should contain only decisions stable enough to survive multiple tasks.

### ADR

Records a consequential, difficult-to-reverse decision, its context, alternatives, trade-offs, and consequences. It is not a container for every task plan.

### Harness

Makes an important rule executable or reproducible through tests, guards, lint/static checks, browser flows, database checks, or a documented manual gate.

### Acceptance evidence

Comes from observable facts: commands and exit results, real browser interactions, Console/Network observations, database before/after evidence, and a final diff review. The implementing AI's self-declaration is not evidence.

## 5. Root Rules And Progressive Disclosure

- Root rules should be short: project identity, exclusions, priority, entry links, universal flow, and dangerous-operation boundaries.
- Detailed module behavior belongs in the owning module spec or ADR.
- Current state belongs in the current-state documents, not in root rules.
- Temporary plans and reports are loaded only for the task that needs them.
- History is discoverable through an index but is not default context.
- Too much context can hide the most important rule; more documentation is not automatically better governance.

## 6. Architecture And Anti-Mud Principles

1. AI tends to extend the structure it sees. A confused architecture is likely to become more confused unless humans freeze boundaries first.
2. Before adding a feature, identify the owning module, public interface, data flow, side effects, affected old behavior, and verification.
3. Each extracted module must own a real responsibility that can be stated in one sentence.
4. Split by data flow, side-effect ownership, and stable contract—not by line count alone.
5. Avoid empty wrappers, duplicate DTOs, generic services without a real owner, unnecessary interfaces, and global state introduced only to look architectural.
6. Tests do not make confused ownership acceptable; difficulty testing often signals unclear inputs, outputs, or effects.
7. Feature work and broad refactoring should be separated. Necessary small structural corrections must be explicit and verified.
8. Architecture changes should reduce implicit state, duplicated rules, parallel write paths, and future agent ambiguity.

## 7. Regression Protection

- A prose rule such as “do not break the old flow” is too weak.
- Protect repeated failures and high-risk invariants with executable guards or reproducible browser/data acceptance.
- Characterization tests are useful before changing a legacy path.
- Public interface and persisted-format compatibility require dedicated tests and migration/deprecation plans.
- A failed check must be investigated; it must not be deleted or weakened to make the task appear complete.

## 8. How This Summary Enters Project Rules

The following stable principles are enforced by `AGENTS.md` and `docs/architecture/ai-development-rule-system.md`:

- load this summary only when the task concerns rules, specs, architecture, harnesses, or methodology; the relevant Skill and authoritative rule system select it through the task-loading matrix;
- treat LinguaCafe as a long-lived project;
- keep root rules sparse and load documentation by task;
- distinguish current fact, stable spec, ADR, temporary plan, acceptance evidence, and history;
- run a local architecture preflight before feature work;
- separate feature delivery from broad refactoring;
- convert critical invariants into harnesses;
- reject AI self-report as acceptance evidence;
- promote decisions to stable specs/ADRs only after validation;
- avoid architecture ceremony that adds interfaces without reducing real complexity.

## 9. Task Use

Rules/spec/architecture/harness tasks selected by the task-loading matrix should read this summary. A bounded feature, bug fix, or acceptance task does not load it unless methodology or document governance is material. Raw subtitle transcripts should be revisited only when a new methodology question is not adequately answered here or when the user explicitly requests a fresh source review.
