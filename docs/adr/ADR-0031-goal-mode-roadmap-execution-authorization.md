# ADR-0031: Goal Mode Roadmap Execution Authorization

## Status

Accepted — 2026-07-28

This ADR supersedes ADR-0028 for explicit persistent goals. ADR-0028 remains historical evidence for why stage preauthorization was introduced.

> **Partial supersession:** ADR-0034 replaces the treatment of delegated
> in-roadmap choices and the absolute requirement that every acceptance item
> close before any contract-independent downstream slice starts. ADR-0031's
> destructive, real-data, secret, external, paid, deployment, and completion
> boundaries remain active.
>
> ADR-0037 further replaces per-slice manual acceptance, dirty-worktree
> blocking, global deferred-count, and whole-goal pause semantics. It does not
> relax the retained safety and truthful-completion boundaries.

Acceptance evidence is the user's current goal and confirmations:

- `/goal 完成所有里程碑，必要时修改一些规则，方便使用目标模式完成工作`
- `批准，你可以取消这个限制`
- `确认`
- `确认，以后你直接按，1.anki 设计 2.无法参考，按你推荐的，不要出现受阻`

## Context

ADR-0028 removed repeated approval for ordinary implementation inside an explicit roadmap goal, but still stopped at every new table, migration, Controller, Service, public API, formal rating, ReviewLog, WordSense, ReviewCard, or lifecycle seam. The 2026-07-28 cloud-first roadmap necessarily contains those changes in nearly every milestone. Requiring a new confirmation after the roadmap, milestone order, product boundary, and slice contract have already been frozen makes persistent goal mode unable to complete the goal.

The user explicitly authorized removal of that repeated stop. This does not authorize destructive or external actions that are not implied by repository implementation.

## Decision

When the user creates or resumes a persistent goal that names an authoritative roadmap or ordered milestones, the goal authorizes sequential execution of every named milestone.

For each slice, implementation authorization becomes active after:

1. the current authority and dependency order are identified;
2. an Architecture Gate review freezes the slice goal, non-goals, modules, seams, allowed files, data flow, compatibility boundary, validation, and ADR needs;
3. a plan or slice ADR freezes any new data-model, public API, payload, formal-rating, ReviewLog, WordSense, ReviewCard, lifecycle, import/export, or store semantics; when it only instantiates this accepted roadmap and ADR-0034's delegated ladder, ADR-0037 treats it as `Accepted under current goal authorization`;
4. no unresolved highest-effective-authority conflict or non-delegated product choice remains.

Once those conditions hold, the goal preauthorizes:

- additive migration files and new tables required by the milestone;
- applying those migrations only to the dedicated testing database through the repository test harness;
- new Controllers, Services, models, middleware, routes, and store modules required by the frozen slice;
- changes to high-risk domain seams whose exact semantics are frozen by the slice contract;
- multiple reviewed seams when the Architecture Gate shows they form one coherent milestone interface;
- milestone documentation and executable guards;
- automatic entry into the next named milestone after a requirement-by-requirement completion audit closes the current one.

No additional confirmation is required merely because one of those implementation forms is present.

## Mandatory Stops That Remain

Goal authorization does not cover:

- running migrations, backfills, restores, deletes, or destructive commands against development, staging, production, or user data;
- `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe`, drop, truncate, or equivalent destructive operations anywhere;
- secrets, credentials, real AI providers, external data transmission, paid usage, model choice, or cost-limit changes;
- deployment, application-store submission, signing, publication, or other irreversible third-party actions;
- an unresolved authority conflict, an unfrozen product choice, or work outside the named roadmap;
- a material change to the accepted roadmap's product boundary, data authority, security model, external-data handling, or cost assumptions.

Those cases require a specific new user decision. A newly discovered implementation detail inside an already frozen slice requires the Architecture Gate and contract to be updated, but does not require another user confirmation unless it crosses one of the lines above.

## Test Database Boundary

`RefreshDatabase` and equivalent repository test setup may create or roll back the slice schema only in the dedicated testing database. The existing testing/development database isolation checks remain mandatory. This ADR does not authorize `php artisan migrate` against the developer's normal database.

## Alternatives Considered

### Keep ADR-0028 unchanged

Rejected because every roadmap milestone would stop on its expected implementation shape, defeating the explicitly requested persistent goal.

### Blanket autonomy

Rejected because a roadmap goal does not imply permission to alter production data, expose secrets, spend money, transmit user data, or publish externally.

### Avoid migrations and new modules

Rejected because it would force mobile identity and idempotency into unrelated legacy tables and make the architecture less safe merely to satisfy a process rule.

## Consequences

- Goal mode can complete an ordered roadmap without repetitive authorization prompts.
- Architecture review, contract-first design, tests, browser acceptance, and per-milestone audits remain mandatory.
- Dedicated testing-database schema execution is distinguished from developer or production data migration.
- External, destructive, and goal-expanding actions still stop.

## Validation

- `node tests/js/GoalRoadmapExecutionAuthorizationDocsGuard.test.mjs`
- `node tests/js/GoalStagePreauthorizationDocsGuard.test.mjs`
- `git diff --check`
- adversarial review of authority, data safety, external effects, and completion semantics
