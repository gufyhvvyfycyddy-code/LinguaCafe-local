# LinguaCafe 当前工作台 / Codex 交接临时文档

> **最后更新**：2026-07-02 (Codex-SenseReviewRealWorkflowHardeningTargetMode-1)
> **文档入口**：先读 `docs/DOCUMENTATION_INDEX.md`，再读本文。
> **旧交接文档**：`docs/CODEX_HANDOFF.md`（2026-06-23）和 `docs/handovers/2026-06-24-c12-c-handoff.md` — 这些是历史交接文档。Codex 新任务应以本文为准。
> **历史索引**：`docs/HISTORY_INDEX.md` 记录旧 status / next task / FSRS phase 文档，避免上下文污染。

---

## 1. 当前阶段一句话

处于 Post-Stabilization 架构收口阶段，已完成 FSRS preview/confirmPreflight 测试线、FSRS confirmAndApply 拒绝写入安全护栏、TextBlock encountered_words 提取线、TextBlock phrase/index characterization tests、WordSense 删除/归档测试线、删除成功提示文案收口、TextBlock fallback tokenizer 测试护栏、ReviewCardManage logs payload contract tests、SenseReview 真实页面 smoke harness（marker data 命令 + 命令测试 + 可复验 playbook）。下一阶段仍需由网页端总设计师选择，不自动进入下一任务。

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

## 3. 当前未最终关闭的事项

本节只放真实未完成事项。已完成任务详情进入 `docs/plans/linguacafe-master-plan.md`，历史材料进入 `docs/HISTORY_INDEX.md`。

- **OpenCode-ArchitectureTargetMode-Batch1 剩余缺口**：
  - pending occurrence 写入路径已由 Codex-SenseReviewRealWorkflowHardeningTargetMode-1 的 marker data 命令 + 真实页面 smoke + playbook 覆盖；
  - TextBlock fallback 最小测试缺口已由 Codex-SpecToHarnessHardeningTargetMode-1 关闭；
  - ReviewCardManage logs payload 最小 contract tests 已由 Codex-SpecToHarnessHardeningTargetMode-1 关闭；
  - SenseReview FullMenu / occurrence 写入路径已由 Codex-SenseReviewRealWorkflowHardeningTargetMode-1 关闭（marker data + 真实页面 smoke + playbook）。
- **Codex-ProjectDocsGovernanceTargetMode-1**：
  - 本轮只做文档治理，不改业务代码和测试；
  - 新增入口索引、历史索引、ADR-0002、spec→harness 候选清单；
  - 旧 `CURRENT_STATUS` / `NEXT_TASK` / `FSRS_PHASE*` / 旧 handoff 已降权为历史参考；
  - 完成后仍由网页端总设计师选择下一任务，不自动进入下一阶段。
- **AIStudyCardGenerationWorkflow**：
  - 当前是产品规划和 ADR 边界，不是已实现功能；
  - 后续实现前必须先做架构侦查，不改 DB schema，不删除现有 SenseMappingReview / SenseReview 能力，不删除 legacy word card 兼容层。

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

### A. TextBlockService-RemainingExtractionScouting-1

- phrase/index 最小 characterization tests 已在 Codex-ArchitectureOptimizationLoop-1 中补充；
- 后续若继续该方向，建议聚焦 tokenizer/fallback 或旧 ReaderDataService fallback 分支的只读侦查；
- 不直接拆，先看剩余职责和测试缺口；
- 适合 Codex 接盘，但必须先给禁止范围和验收命令。

### B. FsrsRescheduleConfirmApply-SafeWriteContractTests-1

- 拒绝写入路径的最小 contract tests 已在 Codex-ArchitectureFinalGoalMode-1 中补充；
- 已锁定 `apply=true` 高风险未二次确认、blocked 超量两类场景均不写 ReviewCard、不建 snapshot、不写 ReviewLog；
- 后续若继续该方向，建议只读侦查 stale candidate / params 变化 / snapshot appliedCount=0 等剩余边界，不直接改 FSRS 语义。

### C. ReviewCardDeleteSnackbar-FullMenuSmoke-1

- 只读页面 smoke；
- 如果后续有人质疑页面链路，可补完整管理页 SPA 菜单链验收；
- 不改代码，除非发现真实 UI bug。

### D. AIStudyCardGenerationWorkflow-Scouting-1

- 只读侦查阅读页选词、AI 导出、SenseMappingReview、WordSense、ReviewCard 之间现有关系；
- 不实现，不改 DB schema，不删除现有能力；
- 输出架构接入方案后由网页端总设计师决定是否进入实现。

### E. TextBlockService-TokenizerFallbackScouting-1

- 最小 contract tests 已在 Codex-SpecToHarnessHardeningTargetMode-1 中补充；
- 已覆盖保守 lemma、irregular table、安全标记、数字/标点、空文本异常；
- 不改 tokenizer 算法，不改 ReaderDataService，不改 import 语义。

### F. ReviewCardManage-LogsContractTests-1

- 最小 contract tests 已在 Codex-SpecToHarnessHardeningTargetMode-1 中补充；
- 已覆盖精确 payload 字段、日期格式、同卡脏数据的 user/language 过滤；既有测试已覆盖排序、limit、空状态、跨卡隔离、legacy/rejected 拒绝；
- 不改 response 结构，不改 Controller。

### G. SenseReview-FullMenuSmoke-1

- ✅ 已由 Codex-SenseReviewRealWorkflowHardeningTargetMode-1 完成：marker data 命令 + 命令测试 + playbook + MCP Chrome 真实页面验收；
- 后续仅在 SenseReview 页面交互发生变更时按 playbook 重跑。

### H. SpecToHarness-Hardening-1

- 参考 `docs/plans/spec-to-harness-candidates.md`；
- 只选择一个软约束转测试 / smoke / harness；
- 不把候选清单当作实现授权。

### I. Codex 大任务候选

由网页端总设计师决定。Codex 任务不应从脏上下文开始，必须先看：
1. `docs/plans/current-working-handoff.md`（本文）
2. `docs/plans/linguacafe-master-plan.md`
3. `docs/plans/vibe-coding-collaboration-rules.md`
4. `docs/plans/repo-architecture-hotspot-audit.md`
5. 按需读 `docs/DOCUMENTATION_INDEX.md`、`docs/HISTORY_INDEX.md`、`docs/adr/ADR-0002-sense-only-and-ai-study-card-boundaries.md`

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

## 7. 临时文档使用规则

1. 本文是短期工作台，不是永久事实源。
2. 每次完成一个阶段，可以更新本文。
3. 过期事项要移动到 master plan 或删除。
4. 不允许让本文和 master plan 冲突。
5. 如冲突，以 master plan 为准，并修正本文。

## Recent Update: Codex-SenseReviewRealWorkflowHardeningTargetMode-1

- Added a local marker-data command and feature test for SenseReview real workflow smoke data.
- Added `docs/plans/sense-review-real-workflow-smoke-playbook.md` as the replayable page-smoke guide.
- Real browser acceptance covered `/reviews/senses` rating, More menu, source fallback, and `/senses/review` confirm, ignore, reject, rebind, and create-new paths.
- No Vue, FSRS scheduling semantics, WordSense delete/archive/restore semantics, schema, or AI study card implementation changed.
