# LinguaCafe 当前工作台 / Codex 交接临时文档

> **最后更新**：2026-07-02 (Codex-FinalArchitectureClosureTargetMode-1)
> **文档入口**：先读 `docs/DOCUMENTATION_INDEX.md`，再读本文。
> **旧交接文档**：`docs/CODEX_HANDOFF.md`（2026-06-23）和 `docs/handovers/2026-06-24-c12-c-handoff.md` — 这些是历史交接文档。Codex 新任务应以本文为准。
> **历史索引**：`docs/HISTORY_INDEX.md` 记录旧 status / next task / FSRS phase 文档，避免上下文污染。

---

## 1. 当前阶段一句话

架构收口阶段已结束（总体架构收口 100%）。下一步进入 AI 示意卡第一版实现路线（已冻结，未实现）和前端复习入口统一路线（已冻结，未实现）。下一阶段任务必须由网页端总设计师选择，不自动进入下一任务。

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

## 3. 当前未最终关闭的事项

本节只放真实未完成事项。已完成任务详情进入 `docs/plans/linguacafe-master-plan.md`，历史材料进入 `docs/HISTORY_INDEX.md`。

- **架构收口阶段已结束**（Codex-FinalArchitectureClosureTargetMode-1）：总体架构收口 100% 不代表全项目完成，只代表旧系统地基已检查、sense-only 复习主线边界清楚、AI 示意卡第一版可进入开发设计。详见 `docs/plans/final-architecture-closure-report.md`。
- **AI 示意卡第一版路线已冻结**（未实现）：详见 `docs/plans/ai-study-card-v1-frozen-plan.md`。第一版只记录"待 AI 解释"项，不调用 AI、不生成复习卡、不改 FSRS、不改 DB schema。实现轮必须先过 Architecture Gate 与 ADR。
- **前端复习入口统一路线已冻结**（未实现）：详见 `docs/plans/frontend-review-entry-unification-plan.md`。第一轮最小改法：首页"开始复习"指向 `/reviews/senses`、导航栏合并"单词复习/词义确认"为"复习"、复习卡管理归组到"高级"。本轮不修改任何 Vue / 路由 / Controller。
- **Codex-ProjectDocsGovernanceTargetMode-1**：
  - 本轮只做文档治理，不改业务代码和测试；
  - 新增入口索引、历史索引、ADR-0002、spec→harness 候选清单；
  - 旧 `CURRENT_STATUS` / `NEXT_TASK` / `FSRS_PHASE*` / 旧 handoff 已降权为历史参考；
  - 完成后仍由网页端总设计师选择下一任务，不自动进入下一阶段。
- **AIStudyCardGenerationWorkflow**：
  - 当前是产品规划、ADR 边界和第一版路线冻结，不是已实现功能；
  - 后续实现前必须先过 Architecture Gate 与 ADR，不改 DB schema，不删除现有 SenseMappingReview / SenseReview 能力，不删除 legacy word card 兼容层。

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

### A. AI 示意卡第一版最小实现（推荐下一阶段）

- 路线已冻结：`docs/plans/ai-study-card-v1-frozen-plan.md`。
- 第一版只做：阅读页点词 → 侧栏"待 AI 解释"按钮 → 后端只记录 pending item。
- 不调 AI、不生成复习卡、不改 FSRS、不改删除/归档/恢复、不改 legacy word card 兼容层。
- 需要新表（`ai_study_card_pending_items` 或同等语义名称），实现轮经 ADR 评审后再写 migration。
- 需要新 Controller + Service，不塞进现有 ReviewController / SenseReviewController。
- 验收：后端 contract tests（含反向 contract test 确认不写 ReviewCard/ReviewLog/WordSense）+ 前端 smoke + MCP Chrome + CodeBuddy + WorkBuddy。

### B. 前端复习入口统一第一轮最小改法

- 路线已冻结：`docs/plans/frontend-review-entry-unification-plan.md`。
- 第一轮只做 3 件事：首页"开始复习"指向 `/reviews/senses`、导航栏合并"单词复习/词义确认"为"复习"、"复习卡管理"归组到"高级"。
- 不删路由、不改页面内部文案、不改 Vuex store、不改后端 Controller。
- 验收：MCP Chrome 真实观察 + WorkBuddy 网页端体验师复验。

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
| 复习主线稳定 | ≈ 91% | WordSense/ReviewCard/FSRS 核心链路已锁定；SenseReview smoke harness 已建立（marker data + 命令测试 + playbook + MCP Chrome 真实页面验收）；FSRS confirmAndApply 拒绝写入 contract tests 已补。剩余：stale candidate / params 变化 / appliedCount=0 snapshot 等剩余边界只读侦查。 |
| 页面真实验收 | ≈ 91% | 阅读页、查词侧栏、AI 阅读辅助按钮、复习入口、词义确认入口、复习卡管理入口已 MCP Chrome 只读复核。剩余：AI 示意卡第一版入口（待实现）、前端复习入口统一第一轮（待实现）。 |
| AI 示意卡规划 | ≈ 55% | 架构侦查完成（`ai-study-card-architecture-scout.md`）；第一版路线已冻结（`ai-study-card-v1-frozen-plan.md`）。本轮只冻结路线，不实现功能。真正实现第一版后可推进到约 70%。 |
| 前端入口整理 | ≈ 65% | 统一方向已冻结（`frontend-review-entry-unification-plan.md`）：未来主入口统一为"复习"、复习卡管理归组到"高级"、legacy word review 不在导航暴露。本轮不修改任何 Vue / 路由 / Controller。第一轮最小改法实现后可推进到约 80%。 |

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
