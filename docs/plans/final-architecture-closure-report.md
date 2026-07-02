# LinguaCafe 最终架构收口报告

> **任务**：Codex-FinalArchitectureClosureTargetMode-1
> **日期**：2026-07-02
> **性质**：架构收口文档，不是功能实现，不是代码改动。
> **面向**：产品设计者，少用术语。
> **基准 commit**：`72fa4d8 docs: scout ai study card architecture`

---

## 1. 一句话结论

**架构收口可以从 81% 提升到 100%。**

这个 100% 的含义是：

- 旧系统主要地基（WordSense、ReviewCard、ReviewLog、FSRS、EncounteredWord、WordSenseOccurrence、TextBlock / 阅读页）已经检查清楚；
- sense-only 复习主线边界清楚；
- 页面真实验收路线清楚；
- 文档入口清楚；
- 高风险区清楚；
- AI 示意卡第一版可以进入开发设计；
- 下一轮不应该继续无限侦查，而应该进入明确的最小实现任务。

**它不代表**：

- 不代表所有功能已经完成；
- 不代表 AI 示意卡已经实现；
- 不代表前端入口已经统一；
- 不代表页面体验已经完美；
- 不代表可以停止测试护栏建设。

---

## 2. 五条主线最终状态

### 2.1 总体架构收口：81% → 100%

**当前状态**：已完成。

**为什么可以上调**：
- FSRS / TextBlock / WordSense 三大主线的 stabilization、契约测试、文档收口已完成；
- 文档入口体系（DOCUMENTATION_INDEX、HISTORY_INDEX、ADR-0001/0002、current-working-handoff、master plan、hotspot audit、spec-to-harness）已经建立；
- 旧历史文档已降权为参考，不会误导后续 AI；
- 高风险区已通过 ADR-0001 架构闸门规则保护；
- AI 示意卡侦查完成，第一版路线可冻结；
- 前端入口统一路线可冻结；
- 下一阶段进入"最小实现"而非"继续侦查"。

**还缺什么**：无架构阻塞项。剩余事项均为功能实现或体验优化，不再属于"架构收口"范畴。

**下一步应该做什么**：进入 AI 示意卡第一版最小实现（见 §6）。

### 2.2 复习主线稳定：86% → 91%

**当前状态**：sense-only 复习主线已稳定运行。

**为什么可以上调**：
- SenseReview 真实页面 smoke harness 已建立（marker data 命令 + 命令测试 + 可复验 playbook）；
- 评分 / More 菜单 / 查看原文 / 确认 / 改绑 / 拒绝 / 忽略 / 新建等真实写入路径已通过 MCP Chrome 验收；
- FSRS confirmAndApply 拒绝写入路径已有 contract tests；
- WordSense 删除 / 归档 / 恢复语义已有 15 个 contract tests；
- ReviewCardManage logs payload 已有精确字段 / 日期格式 / 同卡过滤 contract tests；
- TextBlock fallback tokenizer 已有单元测试。

**还缺什么**：
- FSRS confirmAndApply 成功写入路径仍无完整 contract tests（有意暂缓，因为成功写入风险高）；
- ReviewCardManage reset / destroy 单卡核心语义仍依赖既有测试，未做额外强化；
- 部分边界场景（stale candidate、appliedCount=0 snapshot）仍为已知风险但未阻塞。

**下一步应该做什么**：复习主线已足够稳定，可以支持新功能开发。后续仅在 FSRS / ReviewCard 语义发生变化时补测试。

### 2.3 页面真实验收：90% → 91%

**当前状态**：主要用户路径已有真实页面验收。

**为什么可以小幅上调**：
- 本轮通过 MCP Chrome 只读复核确认了阅读页、查词侧栏、AI 阅读辅助、复习入口、词义确认入口、复习卡管理入口的真实状态；
- 确认了"待 AI 解释"按钮尚不存在（AI 示意卡第一版入口位置已明确）；
- 确认了导航栏仍暴露"词义确认 / 词义复习"等内部名称（前端入口统一路线已明确）；
- 确认了 legacy word card 兼容层仍以"旧版释义（兼容）"标签存在；
- 确认了首页"开始复习"按钮仍指向 legacy word review 入口（`/review/false/-1/-1`），而非 sense review。

**还缺什么**：
- 首页"开始复习"入口与 sense-only 主线不一致，需在前端入口统一阶段修正；
- 词组拖选体验尚未验收（当前阅读页只支持单词点击）；
- WorkBuddy 后续仍需复验页面体验。

**下一步应该做什么**：在前端入口统一阶段修正首页"开始复习"指向；在 AI 示意卡第一版实现后复验新按钮体验。

### 2.4 AI 示意卡规划：25% → 55%

**当前状态**：架构侦查完成，第一版路线已冻结（见 `docs/plans/ai-study-card-v1-frozen-plan.md`）。

**为什么可以上调**：
- 8 大现有能力地图已盘点（阅读页点词、手动添加释义、WordSense、ReviewCard、pending occurrence、SenseReview / SenseMappingReview、AI 阅读辅助、复习主入口）；
- 12 个代码接入点已识别；
- 5 个不能改的危险区已明确；
- 第一版目标已冻结：阅读页标记"待 AI 解释"，只记录不生成；
- 第一版数据边界、前端边界、后端边界、禁止范围、验收方式已写清；
- MCP Chrome 只读复核确认了入口位置（VocabularySideBox）和现有 AI 阅读辅助的边界（手动复制粘贴，不自动生成复习卡）。

**还缺什么**：
- 第一版尚未实现（本轮只冻结路线，不实现功能）；
- 数据契约设计（新表 or 复用 occurrence）需要在新任务中确认；
- AI 推荐词弹窗尚未设计（属于第二版）。

**下一步应该做什么**：进入 AI 示意卡第一版最小实现任务（见 `docs/plans/ai-study-card-v1-frozen-plan.md`）。

### 2.5 前端入口整理：50% → 65%

**当前状态**：统一方向已冻结（见 `docs/plans/frontend-review-entry-unification-plan.md`）。

**为什么可以上调**：
- 当前前端入口现状已通过 MCP Chrome 真实观察记录；
- 未来统一方式已明确（主入口统一为"复习"）；
- 第一轮最小改法已写清（导航栏文案 + 首页"开始复习"指向）；
- 哪些旧页面保留、哪些未来藏到"高级管理"已明确；
- 禁止一次性删除旧页面已写清。

**还缺什么**：
- 前端实际未改动（本轮只冻结路线，不改 Vue）；
- 导航栏仍展示"词义确认 / 词义复习"等内部名称；
- 首页"开始复习"仍指向 legacy word review 入口。

**下一步应该做什么**：在前端入口统一最小实现任务中执行第一轮改法（见 `docs/plans/frontend-review-entry-unification-plan.md`）。

---

## 3. 旧系统地基检查

用简单语言说明每个核心模块负责什么、哪里不能乱改。

### 3.1 WordSense（词义）

- **负责什么**：储存一个词的一个意思。每个 WordSense 记录词元（lemma）、词性（pos）、中文释义、英文释义、例句、搭配、状态（confirmed / rejected / suggested）。
- **为什么重要**：它是 sense-only 复习系统的实际学习对象。一张复习卡对应一个 WordSense。
- **哪里不能乱改**：
  - 删除 / 归档 / 恢复语义（`removeSenseFromReviewSystem`、`archiveSense`、`restoreEncounteredWordIfNoActiveSenses`）受 15 个 contract tests 保护；
  - 状态字段（status）的取值和切换规则不能随意改；
  - 删除时默认保留 ReviewLog 是有意设计，不是 bug。

### 3.2 ReviewCard（复习卡）

- **负责什么**：一张可调度复习的卡。`target_type = sense` 是主线，`target_type = word` 是 legacy 兼容层。
- **为什么重要**：FSRS 调度的对象。每张卡有 stability、difficulty、reps、lapses、due_at、fsrs_state 等字段。
- **哪里不能乱改**：
  - FSRS 字段（stability / difficulty / reps / lapses / due_at / fsrs_state）不能被非 FSRS 逻辑写入；
  - `target_type = sense` 是主线，不能把 legacy word card 重新带回日常复习主线；
  - 管理页字段契约（source_display_status、source_display_label、missing_* 动态字段）已稳定，不能漂移。

### 3.3 ReviewLog（复习记录）

- **负责什么**：记录每次复习的历史。一张 ReviewCard 可以有多条 ReviewLog。
- **为什么重要**：复习历史是用户的学习轨迹，删除不可逆。
- **哪里不能乱改**：
  - 默认保留语义受测试保护；
  - `deleteReviewLogs=true` 参数存在但前端从未传入 true，未来如需启用必须单独立项；
  - 永久删除 ReviewCard 后 ReviewLog 保留（形成孤儿 log）是当前接受的设计，不是 bug。

### 3.4 FSRS（复习调度算法）

- **负责什么**：根据用户评分（Again / Hard / Good / Easy）计算下一张卡的到期时间和稳定性参数。
- **为什么重要**：直接影响用户每天看到什么卡、复习节奏是否合理。
- **哪里不能乱改**：
  - `FsrsSchedulingService` 的算法核心不能改；
  - `ReviewController::rateReviewCard` 的评分入口不能改；
  - `FsrsReschedulePreviewService` 的 confirmAndApply 写入路径受拒绝写入 contract tests 保护；
  - 重排（reschedule）是高风险批量写操作，不能随意触发。

### 3.5 EncounteredWord（阅读词记录）

- **负责什么**：记录用户在阅读中遇到的每个词。支持阅读页颜色（黄/绿/绿深浅）、熟悉度总览、词形出现记录。
- **为什么重要**：阅读页的核心数据来源。stage 字段决定颜色，fsrs_familiarity_percent 决定绿色深浅。
- **哪里不能乱改**：
  - stage 字段的取值和切换规则不能随意改；
  - restore 逻辑（使用 encountered_word_id 匹配）是安全设计，不是 bug；
  - `createNewEncounteredWords` 已提取到 `EncounteredWordCreationService`，受 12 个 characterization tests 保护。

### 3.6 WordSenseOccurrence（词义出现记录）

- **负责什么**：记录某个词在某个句子中出现过，以及它与哪个 WordSense 绑定。支持 confirm / ignore / reject / bind / create-sense 操作。
- **为什么重要**：连接阅读页和复习系统的桥梁。pending occurrence 是 SenseMappingReview 页面的主要数据来源。
- **哪里不能乱改**：
  - occurrence 的 review_card_id 和 auto_fsrs_allowed 字段的清除 / 保留语义受测试保护；
  - archiveSense 不清除 occurrence 引用是已知的设计不一致，已做产品取舍；
  - occurrence 的 status 字段（pending / bound / ignored / rejected）取值不能随意改。

### 3.7 TextBlock / 阅读页

- **负责什么**：把章节文本分词、渲染、高亮、支持点击查词。
- **为什么重要**：阅读是学习的起点，阅读页是用户最常使用的页面。
- **哪里不能乱改**：
  - `TextBlockGroup.vue`（2182 行）是前端最核心组件，受 Architecture Gate 保护；
  - `getReaderData()` 的输出结构（TextBlockGroup.vue props 强依赖）不能改；
  - Python tokenizer 协议（postTokenizer + Bottle HTTP 通信）不能改；
  - 英文 fallback tokenizer 的 lemma 查找链受单元测试保护；
  - phrase / index 逻辑受 5 个 characterization tests 保护。

---

## 4. 已有硬护栏

以下测试 / smoke / playbook 已经存在，分别保护对应的用户体验。

### 4.1 FSRS 风险写入护栏

- **保护什么**：防止 FSRS 重排（reschedule）的高风险写入路径被误触发。
- **位置**：`tests/Feature/FsrsRescheduleConfirmTest.php`
- **覆盖**：
  - `apply=true` 高风险未 `risk_confirm` 时不写 ReviewCard、不建 snapshot、不写 ReviewLog；
  - blocked 超量时即使传 `risk_confirm=true` 也不写；
  - preview / confirmPreflight 的 hash 校验、语言隔离、空状态、risk_assessment。

### 4.2 TextBlock phrase / index 测试

- **保护什么**：阅读页短语高亮、短语查询、phraseIndexes 映射。
- **位置**：`tests/Feature/TextBlockPhraseIndexingTest.php`
- **覆盖**：exact match、跨 NEWLINE、缺词不命中、phraseIndexes 排序映射、用户 / 语言隔离。

### 4.3 TextBlock fallback 测试

- **保护什么**：Python tokenizer 不可用时英文 fallback 分词不会静默漂移。
- **位置**：`tests/Unit/TextBlockFallbackTokenizerTest.php`
- **覆盖**：保守 lemma、irregular table、安全标记、数字 / 标点、空文本异常。

### 4.4 ReviewCardManage logs 测试

- **保护什么**：管理页复习历史抽屉的字段稳定性和隔离。
- **位置**：`tests/Feature/ReviewCardManageTest.php`
- **覆盖**：精确 payload 字段、ISO 日期格式、同一 review_card_id 下 user / language 过滤、排序、limit、空状态、跨卡隔离、legacy / rejected 拒绝。

### 4.5 WordSense 删除 / 归档 / 恢复测试

- **保护什么**：WordSense 的删除、归档、恢复语义不会被误改。
- **位置**：`tests/Feature/WordSenseDestroyRestoreTest.php`（15 个 contract tests）
- **覆盖**：archiveSense 禁用卡 / 不改 occurrence、removeSenseFromReviewSystem(false) 禁用 + 解绑、permanent delete 删卡 + 解绑 + restore Learning→New、deleteReviewLogs 三重过滤、默认保留、另一个 confirmed sense 阻止 restore、Known / Ignored / New 不恢复、encountered_word_id restore 安全设计、legacy word card 不受影响、rejectSense 当前行为、route 权限隔离。

### 4.6 SenseReview 真实页面 smoke harness

- **保护什么**：sense-only 复习的真实用户路径。
- **位置**：`php artisan smoke:sense-review-data` + `tests/Feature/SenseReviewSmokeDataCommandTest.php` + `docs/plans/sense-review-real-workflow-smoke-playbook.md`
- **覆盖**：marker data 准备（不创建账号、不接收密码、不清库）、到期卡评分、More 菜单、查看原文 fallback、确认 / 改绑 / 拒绝 / 忽略 / 新建。

### 4.7 文档入口和历史降权规则

- **保护什么**：防止后续 AI 从旧文档恢复过期上下文。
- **位置**：`docs/DOCUMENTATION_INDEX.md`、`docs/HISTORY_INDEX.md`、`docs/adr/ADR-0001`、`docs/adr/ADR-0002`
- **覆盖**：入口顺序、文档分层、历史降权、ADR 决策记录、架构闸门规则、sense-only 和 AI 示意卡边界。

---

## 5. 仍未完成但不阻塞收口的事项

以下事项是真实的未完成项，但它们阻塞的是"功能完成"，不是"架构收口"。

### 5.1 AI 示意卡还没实现

- **现状**：架构侦查完成，第一版路线已冻结（见 `docs/plans/ai-study-card-v1-frozen-plan.md`），但功能未实现。
- **为什么不阻塞收口**：架构边界已清楚，第一版只做"标记待 AI 解释"，不碰 FSRS / ReviewCard / WordSense 核心。
- **阻塞什么**：阻塞 AI 示意卡功能完成。

### 5.2 前端主入口还没真正改成统一"复习"

- **现状**：导航栏仍展示"单词复习"下拉菜单，含"词义确认"和"复习卡管理"。首页"开始复习"仍指向 legacy word review 入口。
- **为什么不阻塞收口**：统一路线已冻结（见 `docs/plans/frontend-review-entry-unification-plan.md`），实际改动属于前端最小实现任务。
- **阻塞什么**：阻塞用户体验一致性。

### 5.3 AI 推荐弹窗还没有

- **现状**：属于 AI 示意卡第二版，本轮不实现。
- **为什么不阻塞收口**：第一版只做"标记待 AI 解释"，不涉及 AI 推荐词。
- **阻塞什么**：阻塞 AI 示意卡完整闭环。

### 5.4 "待 AI 解释"还没有

- **现状**：属于 AI 示意卡第一版，本轮只冻结路线，不实现。
- **为什么不阻塞收口**：架构边界已清楚，实现属于下一轮最小实现任务。
- **阻塞什么**：阻塞 AI 示意卡第一版功能。

### 5.5 词组拖选可能还没有

- **现状**：阅读页当前只支持单词点击，不支持拖选词组。
- **为什么不阻塞收口**：AI 示意卡第一版建议只支持单词，词组拖选属于后续增强。
- **阻塞什么**：阻塞词组级别的 AI 示意卡体验。

### 5.6 页面体验仍需 WorkBuddy 后续复验

- **现状**：本轮 MCP Chrome 只读复核已完成，但 WorkBuddy 产品体验复验尚未安排。
- **为什么不阻塞收口**：架构收口不需要 WorkBuddy 复验，WorkBuddy 复验属于功能验收环节。
- **阻塞什么**：阻塞前端入口统一和 AI 示意卡第一版的最终产品验收。

### 5.7 首页"开始复习"指向 legacy word review

- **现状**：首页"开始复习"按钮指向 `/review/false/-1/-1`（legacy word review 入口），而非 `/reviews/senses`（sense review 入口）。
- **为什么不阻塞收口**：这是前端入口统一阶段要修正的项，不是架构阻塞。
- **阻塞什么**：阻塞用户体验与 sense-only 主线一致。

---

## 6. 下一阶段路线

建议分三步推进。每一步都是独立任务，不自动进入下一步。

### 第一步：AI 示意卡最小可用版

- **用户能看到什么**：阅读页点词后，侧栏出现"待 AI 解释"按钮。点击后提示"已加入待解释"。用户可以继续阅读。
- **系统内部只做什么**：
  - 记录待解释项（word / lemma / chapter / sentence 来源）；
  - 不调用 AI；
  - 不生成 WordSense；
  - 不生成 ReviewCard；
  - 不改 FSRS。
- **禁止做什么**：
  - 不实现 AI 推荐弹窗；
  - 不实现 AI 生成释义；
  - 不改删除 / 归档 / 恢复；
  - 不改 legacy word card 兼容层；
  - 不改 DB schema（除非第一版数据契约设计明确需要新表，且经产品确认）。
- **需要哪些测试**：
  - 后端：待解释项的创建 / 查询 / 删除 / 用户隔离 / 语言隔离 contract tests；
  - 前端：MCP Chrome 阅读页 → 点词 → 点击"待 AI 解释" → 确认反馈。
- **需要哪些页面验收**：MCP Chrome 真实页面验收。
- **需要 CodeBuddy / WorkBuddy 吗**：
  - CodeBuddy：复核 DB schema 安全、用户隔离、不碰 FSRS；
  - WorkBuddy：复验页面体验（按钮位置、反馈文案、是否影响查词）。
- **完成后进度**：AI 示意卡规划 → 约 70%。

### 第二步：前端复习入口统一

- **用户能看到什么**：导航栏"单词复习"改为"复习"。首页"开始复习"指向 sense review 入口。"词义确认"和"复习卡管理"藏到"高级管理"或保留为二级入口。
- **系统内部只做什么**：
  - 改导航栏文案和链接；
  - 改首页"开始复习"指向；
  - 不删旧页面路由。
- **禁止做什么**：
  - 不一次性删除旧页面；
  - 不改后端路由；
  - 不改 FSRS；
  - 不改 ReviewCard / WordSense 语义。
- **需要哪些测试**：
  - MCP Chrome 验收导航栏、首页、各入口链接。
- **需要哪些页面验收**：MCP Chrome 真实页面验收。
- **需要 CodeBuddy / WorkBuddy 吗**：
  - CodeBuddy：复核是否误改后端路由；
  - WorkBuddy：复验用户体验（入口是否清晰、名称是否易懂）。
- **完成后进度**：前端入口整理 → 约 80%。

### 第三步：AI 推荐弹窗和卡片生成闭环

- **用户能看到什么**：用户读完章节后，点击"AI 生成示意卡"按钮。弹窗显示：用户已选择词（自动进入）、AI 推荐词（默认不选，提供全选）。用户确认后生成可复习示意卡。
- **系统内部只做什么**：
  - 调用 AI 推荐词接口（或复用已有 AI 阅读辅助数据）；
  - AI 推荐词去重（排除用户已选词）；
  - 用户确认后创建 WordSense + ReviewCard；
  - 不改 FSRS 调度语义。
- **禁止做什么**：
  - 不自动生成（必须用户确认）；
  - 不改删除 / 归档 / 恢复；
  - 不改 legacy word card 兼容层；
  - 不改 ReviewLog 保留语义。
- **需要哪些测试**：
  - 后端：推荐词去重、默认不选、确认后 WordSense + ReviewCard 创建、用户隔离；
  - 前端：MCP Chrome 阅读页 → 标记 → 弹窗 → 确认 → 复习入口可见。
- **需要哪些页面验收**：MCP Chrome 真实页面验收。
- **需要 CodeBuddy / WorkBuddy 吗**：
  - CodeBuddy：复核 FSRS 不被误改、删除语义不变；
  - WorkBuddy：复验弹窗体验、确认流程、卡片生成后复习入口可见。
- **完成后进度**：AI 示意卡规划 → 约 90%。

---

## 7. 架构阻塞检查

经检查，当前**不存在必须先解决的架构阻塞**。

- 复习主线已稳定到可以继续开发新功能；
- WordSense / ReviewCard / ReviewLog / FSRS 的边界已清楚；
- legacy word card 已明确只作为兼容层；
- SenseReview / SenseMappingReview 已明确当前保留、未来逐步统一；
- TextBlock / 阅读页高风险区已明确；
- 文档入口已不会误导后续 AI；
- 旧历史文档已降权；
- 测试和 smoke 护栏已足够支撑下一阶段；
- AI 示意卡第一版已可进入开发路线冻结。

**下一轮不应该继续无限侦查，而应该进入 AI 示意卡第一版最小实现任务。**

---

## 8. 合规确认

本报告是文档，不改业务代码、测试、Vue、Controller、Service、routes、migration、DB schema、FSRS、ReviewLog、删除 / 归档 / 恢复语义、legacy word card、SenseReview / SenseMappingReview。

本报告基于：
- 10 份必读文档的真实内容；
- MCP Chrome 真实页面只读复核（阅读页、查词侧栏、AI 阅读辅助、复习入口、词义确认入口、复习卡管理入口）；
- 当前 GitHub master 最新 commit `72fa4d8`。

本报告不自动进入下一任务。
