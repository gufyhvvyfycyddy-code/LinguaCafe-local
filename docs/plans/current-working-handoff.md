# LinguaCafe 当前工作台 / Codex 交接临时文档

> **最后更新**：2026-07-02
> **旧交接文档**：`docs/CODEX_HANDOFF.md`（2026-06-23）和 `docs/handovers/2026-06-24-c12-c-handoff.md` — 这些是历史交接文档。Codex 新任务应以本文为准。

---

## 1. 当前阶段一句话

处于 Post-Stabilization 架构收口阶段，已完成 FSRS preview/confirmPreflight 测试线、FSRS confirmAndApply 拒绝写入安全护栏、TextBlock encountered_words 提取线、TextBlock phrase/index characterization tests、WordSense 删除/归档测试线、删除成功提示文案收口。下一阶段仍需由网页端总设计师选择，不自动进入下一任务。

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

## 3. 当前未最终关闭的事项

- **CodexHandoff-DocsAndWorkingPlanRefresh-1**：
  - 已完成并成为当前 Codex 交接入口；
  - 后续 Codex 任务仍应先读本文，再读 master plan、协作规则、hotspot audit。
- **Codex-ArchitectureOptimizationLoop-1**（本轮）：
  - 已选择第一轮低风险实现：只新增 TextBlock phrase/index characterization tests；
  - 不改业务代码，不改 Vue，不改数据库，不改变 import / FSRS / WordSense 语义；
  - 只有当本轮 commit 成功 push 后，本文记录才算 GitHub 已同步。
- **Codex-ArchitectureOptimizationLoop-1 后续清理**：
  - Codex 执行后遗留了本地 `data/` 和 `CODEX_SESSION_DIAGNOSIS.txt` artifact；
  - 已通过 `CodexWorkspaceArtifactCleanup-1` 删除并添加最小 `.gitignore` 保护，防止后续 agent 误提交。
- **CodexWorkspaceArtifactCleanup-Followup-1**：
  - 修正 master plan 头部日期为 followup 任务名；
  - 核查 `.codex/` — 确认其为 Codex/session 本地 artifact（含 agent .toml 文件），已加入 `.gitignore`；
  - 更新 current-working-handoff 记录收口状态；
  - 不改业务代码，不改测试，不继续架构优化。
- **Codex-ArchitectureFinalGoalMode-1**（本轮）：
  - 已选择本轮低风险 P1：只补 FSRS confirmAndApply 拒绝写入安全测试；
  - 不改 FSRS 算法，不改 `FsrsReschedulePreviewService`，不执行真实重排，不改 ReviewCard / ReviewLog / WordSense 语义；
  - 只有当本轮 commit 成功 push 后，本文记录才算 GitHub 已同步。
- **OpenCode-ArchitectureTargetMode-Batch1**（阶段性完成 / 仍有缺口）：
  - ✅ 已完成：SenseReview 到期卡真实显示 + Good 评分真实执行 + ReviewLog 创建 + ReviewCard due_at/reps 更新；
  - ✅ 已完成：TextBlock fallback 只读侦查（fallbackEnglishTokenize 仍可调用但无测试覆盖）；
  - ✅ 已完成：ReviewCardManage logs payload 只读侦查（字段已盘点，contract tests 待补）；
  - ❌ 未完成：pending occurrence 写入未真实跑通（数据准备问题，非代码 bug）；
  - ❌ 未完成：current-working-handoff 上轮漏更新；
  - ❌ 未完成：TextBlock fallback 仍缺测试；
  - ❌ 未完成：logs payload 仍缺 contract tests；
  - ❌ 未完成：SenseReview FullMenu / occurrence 写入路径仍建议后续 smoke。
- **旧交接文档**：
  - `docs/CODEX_HANDOFF.md`（2026-06-23）是旧交接文档，记录了 tokenizer 根治阶段的工作；
  - `docs/handovers/2026-06-24-c12-c-handoff.md`（2026-06-24）是 C.12-c 任务交接文档；
  - 当前 Codex 交接入口以本文为准。
  - **不要让 Codex 直接从旧文档开始执行任务**。

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

- 补 TextBlockService fallbackEnglishTokenize 路径的测试缺口；
- 不改 tokenizer 算法，不改 ReaderDataService，不改 import 语义。

### F. ReviewCardManage-LogsContractTests-1

- 补 logs endpoint 的 contract tests：user/language/card 三重过滤、字段结构、空状态；
- 不改 response 结构，不改 Controller。

### G. SenseReview-FullMenuSmoke-1

- 补 SenseReview 页面完整 More 菜单和 occurrence 写入路径的 MCP Chrome smoke；
- 不改代码，除非发现真实 UI bug。

### H. Codex 大任务候选

由网页端总设计师决定。Codex 任务不应从脏上下文开始，必须先看：
1. `docs/plans/current-working-handoff.md`（本文）
2. `docs/plans/linguacafe-master-plan.md`
3. `docs/plans/vibe-coding-collaboration-rules.md`
4. `docs/plans/repo-architecture-hotspot-audit.md`

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
