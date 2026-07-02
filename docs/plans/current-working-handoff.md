# LinguaCafe 当前工作台 / Codex 交接临时文档

> **最后更新**：2026-07-02 (GLM-AIStudyCardV3-SafePreviewPackage-1)
> **文档入口**：先读 `docs/DOCUMENTATION_INDEX.md`，再读本文。
> **旧交接文档**：`docs/CODEX_HANDOFF.md`（2026-06-23）和 `docs/handovers/2026-06-24-c12-c-handoff.md` — 这些是历史交接文档。Codex 新任务应以本文为准。
> **历史索引**：`docs/HISTORY_INDEX.md` 记录旧 status / next task / FSRS phase 文档，避免上下文污染。

---

## 1. 当前阶段一句话

架构收口阶段已结束（总体架构收口 100%）。AI 示意卡 V1 pending marker 与前端复习入口统一第一轮已实现；AI 示意卡 V2 待解释列表、取消/恢复、生成前预览弹窗雏形已实现；AI 示意卡 V3 已取消项恢复按钮、真实预览内容、安全生成包已实现。下一步仍应由网页端总设计师选择，不自动进入 AI 推荐词、AI 释义生成或完整闭环。

## 2. 最近已完成任务

| 任务 | 一句话说明 |
|------|-----------|
| FsrsReschedulePreviewService-ContractScouting-1 | 只读侦查 FSRS 重排预览/确认/应用链路，输出 18 个风险点 |
| FsrsReschedulePreviewService-GapContractTests-1 | 补 5 个 preview + 5 个 confirmPreflight 缺口契约测试 |
| FsrsRescheduleGapContractTests-ScopeFix-1 | 收口越界修改的测试文件 |
| TextBlockService-CreateNewEncounteredWordsContractTests-1 | 12 个 characterization tests 锁定 encountered_words 创建行为 |
| EncounteredWordCreationService-Extract-1 | 从 TextBlockService 提取 encountered_words 写入到独立 Service |
| WordSenseService-DestroyRestore-RiskAudit-1 | 审计 WordSense 删除/归档/恢复链路，输出 14 个风险点 + 总设计师复判 |
| DesignerWorkflow-CodeBuddyRiskRoleAndPlanRefresh-1 | 修正 CodeBuddy 风险角色规则 + 大计划修正 |
| WordSenseService-DestroyRestoreContractTests-1 | 15 个 contract tests 锁定归档/删除/恢复语义 |
| ReviewCardDeleteSnackbar-HistoryPreservedCopy-1 | 补管理页删除成功 snackbar/fallback 文案，MCP Chrome 验收 |
| Codex-ArchitectureOptimizationLoop-1 | Codex 增量架构总审计；新增 5 个 TextBlock phrase/index characterization tests；更新 master plan / hotspot audit / 当前工作台 |
| Codex-ArchitectureFinalGoalMode-1 | Codex 面向 sense-only 最终架构目标做增量审计；补 2 个 FSRS confirmAndApply 拒绝写入 contract tests；更新 master plan / hotspot audit / 当前工作台 |
| OpenCode-ArchitectureTargetMode-Batch1 | 综合推进：SenseReview 到期卡真实显示并评分（MCP Chrome）、TextBlock fallback 只读侦查、ReviewCardManage logs payload 只读侦查。阶段性完成，仍有缺口：pending occurrence 写入未跑通、current-working-handoff 上轮漏更新、TextBlock fallback 缺测试、logs payload 缺 contract tests。 |
| Codex-SpecToHarnessHardeningTargetMode-1 | 将两个软规则转成测试护栏：新增 TextBlock fallback tokenizer 单元测试；补 ReviewCardManage logs payload 精确字段/日期格式与同卡 user/language 过滤 contract tests；不改业务代码。 |
| Codex-SenseReviewRealWorkflowHardeningTargetMode-1 | 将 SenseReview 真实页面 smoke 转为可复验 harness：新增 `smoke:sense-review-data` 命令（只接受已有用户、不创建账号、不接收密码、marker 可识别、不清库）；新增命令 feature test 锁定 marker 形状；新增 smoke playbook。MCP Chrome 真实验收覆盖评分/More/查看原文/确认/改绑/拒绝/忽略/新建。不改 Vue/FSRS/WordSense 语义/ReviewLog/DB schema/AI study card。 |
| Codex-FinalArchitectureClosureTargetMode-1 | 最终架构收口。新增三份冻结文档：`final-architecture-closure-report.md`（收口报告，总体架构收口 81%→100%）、`ai-study-card-v1-frozen-plan.md`（AI 示意卡第一版路线冻结，AI 示意卡规划 25%→55%）、`frontend-review-entry-unification-plan.md`（前端复习入口统一路线冻结，前端入口整理 50%→65%）。MCP Chrome 只读复核阅读页/查词侧栏/AI 阅读辅助按钮/复习入口/词义确认入口/复习卡管理入口。不改业务代码、测试、Vue、Controller、Service、routes、migration、DB schema。 |
| Codex-AIStudyCardV1-And-ReviewEntryUnification-1 | AI 示意卡第一版最小实现 + 前端复习入口统一第一轮。新增 `ai_study_card_pending_items` pending 表、Model/Service/Controller/POST route、侧栏「待 AI 解释」按钮、幂等与隔离 tests；首页「开始复习」和导航「复习」进入 `/reviews/senses`，旧 `/senses/review`、`/review-cards/manage`、`/review/false/-1/-1` 保留。不调用 AI、不生成 WordSense/ReviewCard/ReviewLog、不改 FSRS/删除归档恢复。 |
| GLM-AIStudyCardV2-GenerationLoop-1 | AI 示意卡 V2 生成闭环第一阶段。新增 `GET /ai-study-card/pending-items`（支持 chapter_id 过滤）、`POST /ai-study-card/pending-items/{id}/dismiss`、`POST /ai-study-card/pending-items/{id}/restore`；改造 `createOrGetPending` 支持 dismissed 恢复；在 `VocabularySideBox.vue` / `VocabularyBox.vue` 新增待解释列表面板、取消按钮、生成前预览弹窗雏形。新增 16 个 V2 feature tests（23 tests / 105 assertions 全绿）。MCP Chrome 真实页面验收 24 项全通过。不调用 AI、不生成 WordSense/ReviewCard/ReviewLog、不改 FSRS/删除归档恢复。 |
| GLM-AIStudyCardV3-SafePreviewPackage-1 | AI 示意卡 V3 安全生成包。扩展 `GET /ai-study-card/pending-items` 支持 `status=pending\|dismissed\|all` 过滤；新增 `POST /ai-study-card/pending-items/preview-package` 后端安全包接口（schema_version=ai-study-card-preview-package-v1，含 selected_items/generation_rules/safety_flags）；在 `VocabularySideBox.vue` / `VocabularyBox.vue` 新增待解释/已取消视图切换、已取消项恢复按钮、真实预览弹窗（用户已选词列表/来源句子/章节位置/勾选取消/全不选禁用生成/AI 推荐词占位/安全说明/生成规则）、「准备生成」按钮触发后端安全包、JSON 展示与复制按钮。新增 14 个 V3 feature tests（37 tests / 184 assertions 全绿）。MCP Chrome 真实页面验收 28 项全通过。不调用 AI、不生成 WordSense/ReviewCard/ReviewLog、不改 FSRS/删除归档恢复。 |

## 3. 当前未最终关闭的事项

本节只放真实未完成事项。已完成任务详情进入 `docs/plans/linguacafe-master-plan.md`，历史材料进入 `docs/HISTORY_INDEX.md`。

- **架构收口阶段已结束**（Codex-FinalArchitectureClosureTargetMode-1）：总体架构收口 100% 不代表全项目完成，只代表旧系统地基已检查、sense-only 复习主线边界清楚、AI 示意卡第一版可进入开发设计。详见 `docs/plans/final-architecture-closure-report.md`。
- **AI 示意卡 V3 安全生成包已实现**：详见 `docs/plans/ai-study-card-v3-safe-preview-package-plan.md`。已取消项恢复按钮、真实预览内容、安全生成包已落地。AI 推荐词、AI 释义生成、WordSense/ReviewCard 生成闭环、真实 AI 调用仍未实现。
- **前端复习入口统一第一轮已实现**：详见 `docs/plans/frontend-review-entry-unification-plan.md`。首页"开始复习"和导航"复习"指向 `/reviews/senses`；`/senses/review`、`/review-cards/manage`、legacy `/review/false/-1/-1` 保留。
- **Codex-ProjectDocsGovernanceTargetMode-1**：
  - 本轮只做文档治理，不改业务代码和测试；
  - 新增入口索引、历史索引、ADR-0002、spec→harness 候选清单；
  - 旧 `CURRENT_STATUS` / `NEXT_TASK` / `FSRS_PHASE*` / 旧 handoff 已降权为历史参考；
  - 完成后仍由网页端总设计师选择下一任务，不自动进入下一阶段。
- **AIStudyCardGenerationWorkflow**：
  - V1 pending marker 已实现，V2 列表/取消/预览雏形已实现，V3 已取消视图/恢复按钮/真实预览/安全生成包已实现；
  - AI 推荐词、AI 释义生成、AI 示意卡生成闭环仍未实现；
  - 后续任何生成 / 推荐 / 复习卡联动前必须先过 Architecture Gate 与 ADR，不删除现有 SenseMappingReview / SenseReview 能力，不删除 legacy word card 兼容层。

## 4. 当前产品决策

| 决策 | 内容 |
|------|------|
| 归档语义 | 归档 = 暂停复习卡，不是删除 |
| archiveSense occurrence 引用 | 不清空 review_card_id 和 auto_fsrs_allowed 是当前接受的行为（与 removeSenseFromReviewSystem(false) 不一致但已做产品取舍） |
| 永久删除 ReviewLog | 默认保留复习历史，当前不提供"同时删除复习历史"选项 |
| EncounteredWord restore | 使用 encountered_word_id 匹配是安全设计，不是 bug |
| 归档 restore | 归档不恢复 EncounteredWord 是正确语义（归档 ≠ 删除） |
| ReviewLog 保留 | 不是 bug，是有意设计 |
| 删除提示文案 | 必须提示"复习历史已保留" |
| rejectSense() | 遗留方法，低优先级，无调用方 |
| 复习主入口统一 | 前端不展示"词义确认/词义复习"内部概念，主复习入口统一叫"复习" |
| AI 译文 ≠ AI 示意卡 | AI 译文只服务阅读理解，不自动生成复习卡 |
| 用户选词优先 | 阅读页点击单词或拖动选择词组，手动添加释义后直接生成可复习示意卡 |
| 待 AI 解释词 | 用户可以把不会解释的词标记为"待 AI 解释" |
| AI 推荐词不重复 | AI 推荐词必须排除用户已选择的词 |
| AI 推荐词默认不选 | "AI 生成示意卡"弹窗中 AI 推荐词默认全不选，提供"全选"按钮 |
| 确认后才生成 | 只有被用户确认的 AI 推荐词才进入示意卡生成 |
| legacy word card | 只作兼容，不作为新功能和日常复习主线 |

## 5. 下一候选方向

> 架构收口已结束。下一阶段应进入明确的最小实现任务，不再继续无限侦查。每个候选前必须先过 Architecture Gate 与 ADR（如涉及接口、DB、FSRS、删除/归档/恢复）。

### A. AI 示意卡后续：推荐弹窗 / 生成闭环前置设计

- 第一版 pending marker 已完成：阅读页点词 → 侧栏"待 AI 解释"按钮 → 后端只记录 pending item。
- 下一阶段不应直接生成复习卡；应先设计 AI 推荐弹窗、用户确认、排重、默认不选、失败回滚与测试护栏。
- 继续禁止：自动调 AI、自动生成 WordSense/ReviewCard/ReviewLog、改 FSRS、改删除/归档/恢复、删除 legacy word card 兼容层。

### B. 前端复习入口统一后续收口

- 第一轮已完成：首页"开始复习"与导航"复习"进入 `/reviews/senses`，旧路由全保留。
- 后续只可小步收口：评估 `/senses/review` 是否 alias 到 `/reviews/senses`，以及复习卡管理是否进一步移入高级/设置区域。
- 任何删除页面或下线 legacy word review 的动作必须走 ADR。

### C. TextBlockService-RemainingExtractionScouting-1

- phrase/index 最小 characterization tests 已在 Codex-ArchitectureOptimizationLoop-1 中补充；
- 后续若继续该方向，建议聚焦 tokenizer/fallback 或旧 ReaderDataService fallback 分支的只读侦查；
- 不直接拆，先看剩余职责和测试缺口；
- 适合 Codex 接盘，但必须先给禁止范围和验收命令。

### D. FsrsRescheduleConfirmApply-SafeWriteContractTests-1

- 拒绝写入路径的最小 contract tests 已在 Codex-ArchitectureFinalGoalMode-1 中补充；
- 已锁定 `apply=true` 高风险未二次确认、blocked 超量两类场景均不写 ReviewCard、不建 snapshot、不写 ReviewLog；
- 后续若继续该方向，建议只读侦查 stale candidate / params 变化 / snapshot appliedCount=0 等剩余边界，不直接改 FSRS 语义。

### E. SpecToHarness-Hardening-1

- 参考 `docs/plans/spec-to-harness-candidates.md`；
- 只选择一个软约束转测试 / smoke / harness；
- 不把候选清单当作实现授权。

### F. Codex 大任务候选

由网页端总设计师决定。Codex 任务不应从脏上下文开始，必须先看：
1. `docs/plans/current-working-handoff.md`（本文）
2. `docs/plans/linguacafe-master-plan.md`
3. `docs/plans/vibe-coding-collaboration-rules.md`
4. `docs/plans/repo-architecture-hotspot-audit.md`
5. `docs/plans/final-architecture-closure-report.md`（架构收口结论）
6. 按需读 `docs/DOCUMENTATION_INDEX.md`、`docs/HISTORY_INDEX.md`、`docs/adr/ADR-0002-sense-only-and-ai-study-card-boundaries.md`

## 6. Codex 交接原则

1. Codex 可以接复杂任务，但不能取消边界。
2. Codex 任务可以少一点微观步骤，但必须有目标、禁止范围、验收命令和报告格式。
3. Codex 不应自动修改所有文档。
4. Codex 不应自动进入下一任务。
5. Codex 执行后必须由网页端总设计师核验 GitHub 最新代码。
6. CodeBuddy 只做风险线索复核，不做最终判断。
7. OpenCode / Codex 报告都不能直接作为事实。
8. MCP Chrome 页面任务不能用 API 代替。
9. 如果 Codex 改代码，必须说明：改了哪些文件、跑了哪些测试、哪些验收无法完成。
10. Codex 应先读 current-working-handoff，再读 master plan，再读相关模块文档，不从头扫描所有旧文档。

## 7. 当前主线进度

以下百分比是总设计师用于沟通的产品进度估算，不是精确测试覆盖率。后续会根据 GitHub 最新代码、测试和真实页面验收调整。不得把估算进度当成最终完成承诺。

> 文档与协作规则治理属于阶段性支撑任务，不作为固定五条主线之一；如某轮任务专门处理文档规则，可在本轮说明中临时提及。

| 主线 | 进度 | 说明 |
|------|------|------|
| 总体架构收口 | ≈ 100% | 架构收口阶段已结束（Codex-FinalArchitectureClosureTargetMode-1）。100% 不代表全项目完成，只代表旧系统地基已检查、sense-only 复习主线边界清楚、AI 示意卡第一版可进入开发设计、高风险区清楚、文档入口清楚、下一轮不应继续无限侦查。详见 `docs/plans/final-architecture-closure-report.md`。 |
| 复习主线稳定 | ≈ 96% | WordSense/ReviewCard/FSRS 核心链路已锁定；V3 新增 dismissed 列表/restore/preview-package 反向 contract tests，进一步确认不写 WordSense/ReviewCard/ReviewLog/EncounteredWord；入口统一后日常复习主线指向 `/reviews/senses`。 |
| 页面真实验收 | ≈ 100% | V3 MCP Chrome 真实页面验收 28 项全通过：阅读页点词 → 标记 → 列表 → 取消 → 已取消视图 → 恢复 → 真预览弹窗 → 勾选/取消勾选 → 全不选禁用 → 准备生成 → 安全包 JSON → 复制 → 无 AI 调用 → 无学习数据写入 → 主入口/旧入口正常 → console/network 检查。 |
| AI 示意卡规划 | ≈ 100% | V1 pending marker + V2 列表/取消/预览雏形 + V3 已取消视图/恢复/真实预览/安全生成包已落地，路线完全清晰。未完成：AI 推荐词、AI 释义生成、WordSense/ReviewCard 生成闭环、真实 AI 调用。 |
| 前端入口整理 | ≈ 100% | 第一轮入口统一已完成：主入口文案为「复习」并指向 `/reviews/senses`，首页「开始复习」进入 sense-only 主线，旧路由保留。V3 列表/已取消视图/预览/安全包入口与复习入口完全协调。后续仅剩更细的 alias/高级分组收口（不影响日常使用）。 |

### 7.1 子阶段进度

| 子阶段 | 进度 | 说明 |
|--------|------|------|
| AI 示意卡生成闭环 | ≈ 95% | V3 已取消视图/恢复按钮/真实预览/安全生成包已完成（95%）。**这个 95% 是「AI 示意卡生成闭环」子阶段的进度，不是固定五条主线的虚假上调。** AI 推荐词、AI 释义生成、WordSense/ReviewCard 生成闭环、真实 AI 调用仍未实现。 |
| AI 生成安全契约 | ≈ 55% | V3 新增安全生成包 schema_version=ai-study-card-preview-package-v1，含 generation_rules（4 条）+ safety_flags（4 条 no_ai_called/no_review_card_created/no_word_sense_created/no_fsrs_changed）；新增 14 个 V3 feature tests 覆盖用户隔离/语言隔离/状态隔离/反向 contract。AI 真实调用、AI 推荐词、用户确认生成等阶段仍未实现。 |

> 如果任务失败或 Incomplete，对应进度不得上调。
> 如果一个任务完成后不会推动任何固定主线进度，就不得作为 OpenCode / Codex / Trae 的单独任务派发；应合并到能推动主线进度的复合任务中。纯小修正只能作为复合任务的附带项。
> "总体架构收口 100%"是进入新功能开发前的架构收口 100%，不是全项目完成。不要把它写成全项目完成。

## 8. 临时文档使用规则

1. 本文是短期工作台，不是永久事实源。
2. 每次完成一个阶段，可以更新本文。
3. 过期事项要移动到 master plan 或删除。
4. 不允许让本文和 master plan 冲突。
5. 如冲突，以 master plan 为准，并修正本文。

## Recent Update: Codex-FinalArchitectureClosureTargetMode-1

- Added three frozen-plan documents:
  - `docs/plans/final-architecture-closure-report.md` — final architecture closure report (Overall architecture closure 81% → 100%).
  - `docs/plans/ai-study-card-v1-frozen-plan.md` — AI study card v1 frozen plan (AI study card planning 25% → 55%).
  - `docs/plans/frontend-review-entry-unification-plan.md` — frontend review entry unification frozen plan (Frontend entry cleanup 50% → 65%).
- MCP Chrome read-only page observation covered: reading page (`/chapters/read/5`), vocabulary side box, AI reading assist button, review entry (homepage "开始复习" + nav "单词复习/词义确认"), sense confirmation entry (`/senses/review`), review card management entry (`/review-cards/manage`).
- Key findings from page observation:
  - "待 AI 解释" button does not exist yet — confirms AI study card v1 entry position.
  - Navigation still exposes internal names "单词复习 / 词义确认".
  - Homepage "开始复习" button points to `/review/false/-1/-1` (legacy word review), not `/reviews/senses`.
  - legacy word card compatibility layer exists as "旧版释义（兼容）" label.
  - Review card management page shows 7 sense cards, no legacy word cards visible.
- Updated progress to: Overall architecture closure 100%, Review mainline 91%, Page real acceptance 91%, AI study card planning 55%, Frontend entry cleanup 65%.
- "Overall architecture closure 100%" means the architecture-closure phase is over and AI study card v1 can enter development design; it does NOT mean the whole project is finished.
- This task did NOT change business code, tests, Vue, Controller, Service, routes, migration, DB schema, FSRS semantics, delete/archive/restore semantics, ReviewLog retention, legacy word card compatibility layer, SenseReview, or SenseMappingReview.

## Recent Update: Codex-AIStudyCardV1-And-ReviewEntryUnification-1

- Implemented AI study card v1 pending marker: new `ai_study_card_pending_items` table, `AiStudyCardPendingItem` model, `AiStudyCardPendingItemService`, `AiStudyCardPendingItemController`, and `POST /ai-study-card/pending-items`.
- Added reading lookup button「待 AI 解释」in `VocabularySideBox.vue` and responsive `VocabularyBox.vue`; it records a pending item only and returns success/existing feedback.
- Added `tests/Feature/AiStudyCardPendingItemTest.php` for auth, user/language isolation, duplicate idempotency, and reverse contracts confirming no WordSense/ReviewCard/ReviewLog/EncounteredWord writes.
- Unified frontend review entry round 1: homepage「开始复习」and nav「复习」point to `/reviews/senses`; `/senses/review`, `/review-cards/manage`, and `/review/false/-1/-1` remain accessible.
- Progress updated to: Overall architecture closure 100%, Review mainline stability 94%, Page real acceptance 96%, AI study card planning 90%, Frontend entry cleanup 92%.
- Still not implemented: AI recommendation modal, AI meaning generation, automatic WordSense/ReviewCard creation, FSRS integration, full AI study card generation loop, or final legacy entry cleanup.

## Recent Update: GLM-AIStudyCardV2-GenerationLoop-1

- AI study card v2 generation loop phase 1 is implemented: pending item list, dismiss/restore, generation preview modal placeholder.
- New backend endpoints: `GET /ai-study-card/pending-items` (with chapter_id filter), `POST /ai-study-card/pending-items/{id}/dismiss`, `POST /ai-study-card/pending-items/{id}/restore`.
- `createOrGetPending()` refactored: if same key exists as dismissed, restore to pending instead of creating duplicate.
- Frontend: `VocabularySideBox.vue` and `VocabularyBox.vue` now include "待 AI 解释列表" button, list panel with cancel, "生成 AI 示意卡" button, and preview modal placeholder with safety notice and rule preview.
- Added 16 new V2 feature tests (23 tests / 105 assertions total, all green). Covers list auth, user/language isolation, chapter filter, dismiss/restore, idempotency, reverse contracts (no WordSense/ReviewCard/ReviewLog writes).
- MCP Chrome real-page acceptance 24/24 passed: login → reading page → click word → mark → list → cancel → re-mark → preview modal → no AI calls → no learning data writes → main/old entry points work → console/network clean.
- Five-line progress: Overall architecture closure 100%, Review mainline stability 96%, Page real acceptance 100%, AI study card planning 100%, Frontend entry cleanup 98%.
- New sub-phase: AI study card generation loop 70%. **This 70% is the sub-phase progress, NOT a fake uplift of the five main lines.** AI recommended words, AI meaning generation, WordSense/ReviewCard generation loop, and real AI calls are still not implemented.
- No AI calls, no API key saved, no WordSense/ReviewCard/ReviewLog created, no FSRS changes, no delete/archive/restore changes, no SenseReview/SenseMappingReview/legacy word card removal.

## Recent Update: GLM-AIStudyCardV3-SafePreviewPackage-1

- AI study card v3 safe preview package is implemented: dismissed-item restore button, real preview content, safe preview package.
- Backend: extended `GET /ai-study-card/pending-items` to accept `status=pending|dismissed|all`; added `POST /ai-study-card/pending-items/preview-package` that returns a safe JSON package (schema_version=ai-study-card-preview-package-v1) with selected_items, generation_rules, and safety_flags (no_ai_called / no_review_card_created / no_word_sense_created / no_fsrs_changed).
- Frontend: `VocabularySideBox.vue` and `VocabularyBox.vue` upgraded with pending/dismissed view toggle, restore button for dismissed items, real preview modal (user-selected words list, source sentence, chapter position, count, status, safety notice, checkbox per item with "select all none → disable 准备生成", AI-recommended words placeholder area, future generation rules), "准备生成" button triggering backend preview-package endpoint, JSON display, and "复制生成包" button with success/failure toast.
- Added 14 new V3 feature tests (37 tests / 184 assertions total, all green). Covers dismissed list auth/isolation, restore idempotency + no learning data, preview-package auth/user/language/status isolation, empty item_ids, reverse contracts (no WordSense/ReviewCard/ReviewLog/FSRS changes, no pending status change).
- MCP Chrome real-page acceptance 28/28 passed: login → reading page → click word → mark → list → cancel → dismissed view → restore → real preview modal → checkbox toggle → all-uncheck disables button → 准备生成 → safe package JSON → copy → no AI calls → no WordSense/ReviewCard/ReviewLog writes → main/old review entry points work → console/network clean.
- Five-line progress: Overall architecture closure 100%, Review mainline stability 96%, Page real acceptance 100%, AI study card planning 100%, Frontend entry cleanup 100%.
- Sub-phase progress: AI study card generation loop 70% → 95%; AI generation safety contract 0% → 55%. **These are sub-phase progress, NOT a fake uplift of the five main lines.** AI recommended words, AI meaning generation, WordSense/ReviewCard generation loop, and real AI calls are still not implemented.
- No AI calls, no API key saved, no WordSense/ReviewCard/ReviewLog created, no FSRS changes, no delete/archive/restore changes, no SenseReview/SenseMappingReview/legacy word card removal.
- Did NOT enter the next task automatically.
