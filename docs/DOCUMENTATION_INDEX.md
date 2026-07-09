# LinguaCafe Documentation Index

> **Status**: Current entry index.
> **Last updated**: 2026-07-09 (AIStudyCardFullLoopRegressionHarness-1).

This file is the lightweight entry map for humans and agents. It exists to prevent context flooding: read the current layer first, then load module or history documents only when the task actually needs them.

## 1. Current Entry Order

Read these in order for a new Codex / OpenCode / CodeBuddy / WorkBuddy task:

1. `docs/plans/current-working-handoff.md` — current short-term workbench, current decisions, and next candidates.
2. `docs/plans/linguacafe-master-plan.md` — long-term ledger of product lines, completed work, and preserved future directions.
3. `docs/architecture/sense-http-controller-boundaries.md` — current HTTP/controller placement rules for sense, review-assist, pending-sense, manual-sense, and inline-confirmation features.
4. `docs/plans/vibe-coding-collaboration-rules.md` — collaboration rules, safety boundaries, and verification rules.
5. `docs/plans/repo-architecture-hotspot-audit.md` — architecture risk map and candidate backlog.
6. `docs/plans/final-architecture-closure-report.md` — architecture-closure phase conclusion (read when judging whether to start AI study card v1 or new feature work).

Do not start from `docs/CODEX_HANDOFF.md`, `docs/NEXT_TASK.md`, `docs/CURRENT_STATUS.md`, or `docs/FSRS_PHASE*.md`. Those are historical references.

## 2. Document Layers

| Layer | Purpose | Primary files |
|---|---|---|
| Entry / current state | Decide what is current and what to read next | `current-working-handoff.md`, this file |
| Long-term ledger | Preserve product directions and completed task history | `linguacafe-master-plan.md` |
| Collaboration rules | Role boundaries, safety red lines, test/smoke/report rules | `vibe-coding-collaboration-rules.md` |
| Architecture risks | Module responsibilities, hotspots, candidate routes | `repo-architecture-hotspot-audit.md` |
| Architecture closure | Final closure conclusion and next-stage roadmap | `final-architecture-closure-report.md` |
| Frozen plans | Route-frozen plans for upcoming minimum implementation | `ai-study-card-v1-frozen-plan.md`, `frontend-review-entry-unification-plan.md` |
| ADR / stable decisions | Accepted decisions that should not be re-litigated each task | `docs/adr/*.md` |
| Module contracts | Stable module boundaries and output contracts | `docs/architecture/sense-http-controller-boundaries.md`, `docs/plans/*contract*.md`, `docs/plans/*boundaries*.md` |
| Test / smoke / harness | Executable or semi-executable verification playbooks | `docs/testing/*`, `docs/testing/ai-study-card-v6-real-provider-network-smoke-playbook.md`, `docs/testing/ai-study-card-full-loop-regression-playbook.md`, `docs/plans/*smoke*`, `docs/plans/mcp-chrome-local-smoke-playbook.md`, `docs/plans/sense-review-real-workflow-smoke-playbook.md`, `docs/plans/morphology-test-sample-tracker.md` |
| Architecture scout | Read-only architecture investigation reports | `docs/plans/ai-study-card-architecture-scout.md` |
| Product principles | Long-term product direction, function constraints, and legacy cleanup plan | `docs/plans/product-principles-and-legacy-cleanup-plan.md` |
| History | Old handoffs, old status files, old phase notes | `docs/HISTORY_INDEX.md` |

## 2.5 Key Rule References

New rules and process notes are documented in:
- `vibe-coding-collaboration-rules.md` §22 — 总设计师提示词前进度说明规则（每次给提示词前必须先说明当前进度）。
- `vibe-coding-collaboration-rules.md` §23 — 三方架构侦查规则（网页端总设计师/CodeBuddy/OpenCode 三方分工）。
- `vibe-coding-collaboration-rules.md` §24 — 进度条显示规则（100% 项隐藏、涨幅标注、样式）。
- `vibe-coding-collaboration-rules.md` §25 — 计划审查规则（入任务前审查全部未满项）。
- `vibe-coding-collaboration-rules.md` §26 — 模式选择规则（OpenCode 微任务 / Codex 目标模式）。
- `vibe-coding-collaboration-rules.md` §27 — 高内聚低耦合架构规则与 GLM 1000% 分层规则、MCP 词元测试样本治理规则、视频字幕架构经验规则。§27.0 第一硬原则：代码安全性和稳定性优先于功能速度。
- `docs/architecture/sense-http-controller-boundaries.md` — sense / review-assist / pending-sense / manual-sense HTTP Controller 落位规则。新功能若无清晰归属，必须先建架构再实现。
- `repo-architecture-hotspot-audit.md` §8.5 — 下一轮架构优化必须遵守的边界。
- `mcp-chrome-local-smoke-playbook.md` §8 — Lemma / Morphology click sample rotation（词元测试样本轮换操作指南）。
- `morphology-test-sample-tracker.md` — MCP 词元测试样本追踪文档（每轮 marker / 文章 / 测试词 / 重复比例 / 8 类覆盖 / 真实点击标记 / API 替代标记 / Incomplete 标记）。
- `vibe-coding-collaboration-rules.md` §27.7 — Testing DB 健康检查规则（每轮大型任务必须先跑 DB health check）。
- `testing-db-health-playbook.md` — Testing DB 健康检查操作指南（health check / 进程锁 / 禁止命令 / 故障报告）。
- `current-working-handoff.md` §7 — 当前主线进度估算。
- `docs/testing/ai-study-card-full-loop-regression-playbook.md` — AI Study Card 主链路（V6 → V4 → V5 → `/reviews/senses` → FSRS rating）回归 harness：测试命令矩阵（9 条命令 + 期望计数）/ MCP Chrome 真实验收 playbook（轻量 7 步 + 完整 20 步）/ 数据库验收矩阵（每阶段表 delta）/ 网络验收（禁止 provider 域名）/ Refuse 条件（12 条安全契约 + 额外触发器）/ Accept/Refuse/Incomplete 判断 / 允许修改文件边界 / 停止条件 / 文件→测试映射。任何 agent 改 AI 学习卡前必须先读本文档并跑 §3 测试命令矩阵。

## 2.6 Frozen Plans (Route-Frozen, Not Implemented)

| Plan | Status | What it freezes |
|---|---|---|
| `ai-study-card-v1-frozen-plan.md` | Frozen, implemented (2026-07-02) | AI study card v1 target, user flow, data/frontend/backend boundaries, forbidden scope, acceptance. Implementation round must still pass Architecture Gate and ADR. |
| `frontend-review-entry-unification-plan.md` | Frozen, round-1 implemented (2026-07-02) | Frontend review entry unification direction, current entry state, future unified layout, round-1 minimum change, forbidden one-shot deletion of old pages, MCP Chrome / WorkBuddy acceptance. |
| `ai-study-card-v2-generation-loop-plan.md` | Frozen, implemented (2026-07-02) | AI study card v2 generation loop phase 1: pending item list, dismiss/restore, preview modal placeholder. |
| `ai-study-card-v3-safe-preview-package-plan.md` | Frozen, implemented (2026-07-02) | AI study card v3 safe preview package: dismissed-view restore button, real preview content, safe preview package (`schema_version=ai-study-card-preview-package-v1`). |
| `ai-recommendation-confirmation-loop-plan.md` | Frozen, implemented (2026-07-02) | AI study card v4 AI recommendation confirmation loop: paste AI recommendation JSON, dedupe, default unchecked, user confirmation, final candidates package (`schema_version=ai-study-card-final-candidates-v1`). |
| `ai-study-card-v6-preflight-plan.md` | Frozen preflight; V6-1/V6-2 implemented (2026-07-07) | V6 architecture gate for real AI recommendation. V6-1 adds provider-disabled request-package preview (`POST /ai-study-card/v6/recommendations/request-package`) plus desktop UI entry (`AiStudyCardV6RequestPackagePanel.vue`). V6-2 adds disabled-by-default provider interface / adapter / recommendation schema validator / recommendation service. Still does not authorize real provider calls or API keys. |
| `ai-study-card-v6-provider-security-plan.md` | Implemented pre-real-provider security gate (2026-07-07) | V6-3 provider configuration/security boundary. Adds disabled-by-default config, policy service, logging/data/network/secret rules, and tests proving real-provider preconditions are not met yet. |
| `ai-study-card-v6-real-provider-implementation-plan.md` | Frozen plan; backend transport + UI trigger + V4 default-unchecked import bridge + duplicate filtering + empty duplicate UX hint + manual-confirmation guidance + V5 reason-vs-definition warning + V5 generation counts before confirm + V5 per-candidate generate/skip status + V5 result candidate overview + V5→/reviews/senses→sense card FSRS rating closed-loop verification implemented (2026-07-08) | V6-4/V6-19 real-provider implementation plan. Documents provider-preview route, prompt/response contract, OpenAI-compatible adapter, DeepSeek-compatible backend transport, UI boundary, fail-closed behavior, and browser Network smoke requirements. Current implementation has explicit backend-preview UI trigger, can import V6 recommendation packages into the existing V4 AI recommendation list, still default unchecked and still requiring user confirmation before final candidates / V5 card generation. Provider recommendations duplicated with user-selected items are filtered into dropped_items at the backend service layer, the V6 panel shows recommendation/dropped counts plus an empty-state hint when all recommendations were dropped, the V4 list shows an import notice guiding the user to manual selection and V5 Chinese-definition confirmation, the V5 dialog now shows stronger warnings that AI reason is not a Chinese definition, the V5 confirm button shows explicit generation/skip counts (共 X 项，将生成 Y 张，将跳过 Z 项) with the button disabled when 0 definitions are filled, each V5 candidate now shows a per-item 将生成/将跳过 status chip that updates in real time as the user fills/removes the Chinese definition, the V5 result panel now shows a candidate overview block (total/filled/skipped_unfilled) attached by the workflow after final confirm so the user can distinguish unfilled candidates (not submitted / not generated / not deleted) from the backend's reverse-validation skipped list, and the V5→/reviews/senses→sense card FSRS rating closed loop has been verified end-to-end via MCP Chrome real page acceptance (newly generated sense card with fsrs_state=new/fsrs_due_at=now/fsrs_enabled=true/status=confirmed immediately enters the /reviews/senses queue; one controlled 'good' rating on mediation card #89 produced exactly 1 ReviewLog with source=sense_review, advanced only the target card's FSRS fields, left word_senses/review_cards/legacy word card counts unchanged, and did not touch any other card; locked by new WordSenseTest::test_v5_generated_sense_card_is_immediately_reviewable_with_single_log_and_no_side_effects). |
| `reading-inline-review-and-example-pool-plan.md` | Frozen, partially implemented (2026-07-03) | Reading inline review and multi-example pool route: WordSense-only inline review (still frozen), real-source example rotation (implemented 2026-07-02), known-sense-new-meaning bridge front-only structure (implemented 2026-07-03, no AI judgment), surface/lemma binding rules (front-end display + lemma-prefer search implemented 2026-07-03), morphology matrix/import-regression guards (implemented 2026-07-03), read-only inline sense preview panel (implemented 2026-07-03 by GLM-ReadingInlinePreview-First-1), **inline sense confirmation persistence** (implemented 2026-07-03 by GLM-ReadingInlineConfirmationPersistence-1000-1: new additive-only `reading_inline_sense_confirmations` table + `ReadingInlineSenseConfirmation` model + `ReadingInlineSenseConfirmationService` (only writer, upsert via lockForUpdate) + `POST /senses/inline-confirmation` endpoint (validates user/language/chapter/sense ownership + confirmed status) + extended `GET /senses/inline-preview` echoing `persisted_choice`/`confirmation_id`/`confirmed_at` + `InlineSensePreviewPanel.vue` buttons now POST and echo persisted choice with 「已保存：是这个意思」/「已保存：不是这个意思」/「这不是复习评分」/「不会写入复习记录」/「不会改变 FSRS」 safety copy + ADR-0003 product freeze + 19 confirmation guard tests + 6 echo tests + 14 UI guard tests; no ReviewLog, no FSRS, no AI, no WordSense/ReviewCard creation, no ALTER/DROP/TRUNCATE/DELETE), and **inline confirmation usage surface layer** (implemented 2026-07-03 by GLM-ReadingInlineConfirmationUsageSurface-AndMorphology-1000-1: `ReadingInlineSenseConfirmationService::summaryForSenseCandidates()` read-only summary method aggregating match_count/not_match_count/last_choice/last_confirmed_at/recent_examples across all occurrences per sense + extended `GET /senses/inline-preview` payload with per-candidate `usage_summary`/`usage_match_count`/`usage_not_match_count`/`usage_last_choice`/`usage_last_confirmed_at` + `InlineSensePreviewPanel.vue` enhanced with usage summary block showing 「阅读中确认过 N 次」/「阅读中排除过 N 次」/「最近一次」 + friendlier safety copy 「不会改变复习进度（FSRS）」 + `hasUsageSummary()` template gate + CSS styles + 12 new summary guard tests + 4 new UI guard tests for usage surface copy + R3 morphology round with 10 real Playwright clicks (pens/tables/cars/dogs/feet/teeth/ate/driven/smaller/closed, 10/10 correct, 0% repeat vs R2, 8/8 categories, tokenizer confirmed via irregular forms) + MCP Chrome inline-confirmation match/not_match interaction flow verified on goose/geese with network trace proving no ReviewLog/FSRS/AI; no ReviewLog, no FSRS, no AI, no WordSense/ReviewCard creation, no new migration, no ALTER/DROP/TRUNCATE/DELETE), and **inline confirmation management surface layer** (implemented 2026-07-04 by GLM-ReadingInlineConfirmationManagementSurface-1000-1: read-only `GET /senses/inline-confirmations` list endpoint with choice/lemma/surface/word_sense_id/chapter_id/date_from/date_to filters + pagination + WordSense/chapter/sentence summary + strict user/language scoping + `DELETE /senses/inline-confirmations/{id}` scoped revoke endpoint returning `revoked:true` + 6 safety_flags (`no_review_log_created`/`no_fsrs_changed`/`no_review_card_changed`/`no_word_sense_deleted`/`no_review_card_deleted`/`not_a_review_rating`) + `ReadingInlineSenseConfirmationService::listConfirmationsForManagement()`/`revokeConfirmation()` methods (only writer is the single-row DELETE, scoped to current user + current language) + new `ReadingInlineConfirmationManage.vue` page at `/senses/inline-confirmations/manage` with filters (全部/是这个意思/不是这个意思/lemma/文章) + list (surface/lemma/choice/WordSense 摘要/来源句子/文章/最近更新时间) + 「查看来源」/「撤销这条记录」 actions + 「这不是复习评分」/「不会写入复习记录」/「不会改变复习进度（FSRS）」/「不是忘记，不是复习失败，也不是删除词义」 safety copy + revoke confirm dialog + empty state copy + `InlineSensePreviewPanel.vue` 「查看全部阅读确认记录」 link to manage page + 49 backend guard tests (list endpoint isolation by user/language, choice/lemma filters, WordSense summary, revoke deletes only current user/language row, no ReviewLog/FSRS/ReviewCard/WordSense deletion, unknown id safe failure) + 19 UI guard tests (file existence, title copy, not-review-rating copy, no-review-log copy, no-fsrs-change copy, 是这个意思/不是这个意思/撤销这条记录 copy, revoke dialog, revoke-not-forget copy, no FSRS rating buttons, no rating/review/fsrs routes, no AI routes, only safe endpoints, no forbidden revoke-meaning copy, no batch revoke UI, preview panel link to manage, back-to-reading link, empty state copy) + R4 morphology round with 13 real Playwright clicks (windows/horses/sheep/mice/deer/broke/drove/spoken/threw/grown/taller/walked/finished, 11/13 correct, 2 P2 residual on V-ed ambiguous forms consistent with R2 pattern, 0% repeat vs R3, 8/8 categories, tokenizer confirmed via irregular forms mice→mouse/broke→break/drove→drive/spoken→speak/threw→throw/grown→grow) + MCP Chrome full management-surface flow verified on goose/geese (POST inline-confirmation → GET manage list → filter choice=match → DELETE revoke → list updates → re-click token shows no persisted_choice); no ReviewLog, no FSRS, no AI, no WordSense/ReviewCard creation, no new migration, no ALTER/DROP/TRUNCATE/DELETE, no batch revoke, no rating buttons). Real tokenizer/importer coverage via `ChapterService::processChapterText` with real Python spaCy, 8/8 morphology categories real page clicks (18 clicks via Playwright), and 4 adjectival ambiguity real clicks (published/used/broken/left) added 2026-07-03 by GLM-RealMorphologyImportClickCompletion-1; data-layer fixture test renamed to `MorphologyMatrixLemmaBridgeDataLayerTest`. PHP fallback `conservativeFallbackLemma()` morphology defect fix (ECDICT-gated `-ies → -y/-ie` rule + ultra-safe `-ches/-shes/-xes/-zes → strip -es` rule) added 2026-07-03 by GLM-MorphologyLemmaDefectFix-1, covering `technologies→technology` / `watches→watch` / `stories→story` / `bodies→body` / `fixes→fix` / `boxes→box` plus adjective-vs-verb participle split (`was published → publish` / `has broken` P2 residual / `was used → use` / `left the room → leave`); 2 P2 residuals reported (`has broken → break` not yet, `left side → left` not yet) due to single-lemma-per-EncounteredWord design limit; reading inline review scoring still frozen, not implemented. |

Do not treat frozen plans as implementation authorization. They only freeze the route; the next implementation round must still pass Architecture Gate and ADR review.

## 3. Current ADRs

| ADR | Status | Scope |
|---|---|---|
| `docs/adr/ADR-0001-architecture-gate-workflow.md` | Accepted | Architecture gate workflow for high-risk work |
| `docs/adr/ADR-0002-sense-only-and-ai-study-card-boundaries.md` | Accepted / Planned split | Sense-only review boundaries and AI study card product boundaries |
| `docs/adr/ADR-0003-reading-inline-sense-confirmation-persistence.md` | Accepted (2026-07-03) | Reading inline sense confirmation persistence (match / not_match) via new additive-only `reading_inline_sense_confirmations` table; explicitly NOT a review rating; no ReviewLog / FSRS / WordSense / ReviewCard / AI; future rating must open a new ADR |
| `docs/adr/ADR-0004-ai-study-card-v6-real-ai-boundary.md` | Accepted pre-implementation gate (2026-07-07) | AI Study Card V6 real AI boundary. V6 may only recommend candidates after explicit user action; no API key in code/frontend/docs examples/logs; no auto WordSense/ReviewCard creation; no ReviewLog/FSRS mutation; implementation must use a dedicated V6 controller/service boundary. |
| `docs/adr/ADR-0005-ai-study-card-v6-real-provider-implementation-plan.md` | Accepted planning gate (2026-07-07) | AI Study Card V6 real-provider implementation plan. Allows only one future backend-only OpenAI-compatible adapter after provider/secret/timeout/failure/browser-Network approval; does not implement live calls, route, UI, or secrets. |

## 4. Soft Rules Awaiting Hard Verification

Use `docs/plans/spec-to-harness-candidates.md` when a task asks what should become tests, smoke checks, or harness checks next. Do not treat that file as implementation permission; it is a candidate list.

## 5. History Handling

Use `docs/HISTORY_INDEX.md` to understand old documents. Historical documents are retained because they contain useful context, but they are not current task entry points.

If a historical document conflicts with current entry documents, follow this priority:

1. Current task prompt and explicit user decision.
2. `current-working-handoff.md`.
3. `linguacafe-master-plan.md`.
4. `vibe-coding-collaboration-rules.md`.
5. Module contract / ADR files.
6. Historical documents only as background.
