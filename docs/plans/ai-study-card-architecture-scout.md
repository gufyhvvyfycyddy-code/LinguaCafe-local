# AI 示意卡架构侦查报告

> **任务**：OpenCode-AIStudyCardArchitectureScouting-And-ProgressRuleFix-1
> **日期**：2026-07-02
> **性质**：只读侦查，不实现功能，不改代码

---

## 1. 一句话结论

**AI 示意卡还不能直接开工。**

当前系统缺少以下关键前提：
- 没有一个“用户选中词 → 暂存 → 确认生成”的完整中间层；
- AI 推荐词的去重和默认不选规则目前完全不存在；
- 阅读页的词级别交互虽然存在，但只支持高亮/查词，不支持标记“待 AI 解释”；
- 现有 SenseMappingReview / SenseReview 页面虽能做 occurrence 确认，但它们是为“手动精准匹配”设计的，不是为“AI 批量推荐 + 用户快速确认”设计的。

**必须在架构侦查完成后，先做最小架构数据契约设计和页面原型，然后才能进入后端实现。**

---

## 2. 当前相关能力地图

### 2.1 阅读页点词 / 查词

- **文件**：`resources/js/components/Reader/TextBlockGroup.vue` + `TextReader.vue`
- **当前能力**：每个词可点击 → 弹出 VocabularySideBox 显示词典释义、WordSense 信息、已关联的 ReviewCard 状态
- **词组选择**：目前不支持拖选词组。仅支持单个 token 点击
- **风险等级**：低（已有测试保护）
- **未来角色**：阅读页是 AI 示意卡的起点入口

### 2.2 手动添加释义

- **文件**：`VocabularySearchBox.vue` → `VocabularySideBox.vue` + `WordSensesList.vue`
- **当前能力**：用户在阅读页点词后，可在右侧面板手动搜索并添加 WordSense
- **后端**：`ManualWordSenseController::storeManualSense` → `WordSenseService::createManualSense`
- **数据流**：用户输入 lemma, pos, sense_zh → 创建 WordSense → 创建 ReviewCard → 成为可复习卡
- **风险等级**：中（涉及 WordSense 创建和 ReviewCard 创建）
- **未来角色**：手动添加释义是最简单的“直接生成示意卡”路径

### 2.3 WordSense 系统

- **文件**：`app/Models/WordSense.php`、`app/Services/WordSenseService.php`
- **当前能力**：储存词义（lemma, pos, sense_zh, sense_en, 例句, 搭配），状态管理（confirmed / rejected / suggested）
- **测试覆盖**：`tests/Feature/WordSenseTest.php`（3106 行）
- **风险等级**：高（删除/归档恢复语义已有测试保护，不能随意改）
- **未来角色**：AI 示意卡最终会创建 WordSense 记录

### 2.4 ReviewCard 系统

- **文件**：`app/Models/ReviewCard.php`、`app/Services/ReviewCardService.php`
- **当前能力**：FSRS 复习卡（sense-only 主线 + legacy word 兼容）
- **测试覆盖**：`tests/Feature/ReviewCardServiceTest.php`、`tests/Feature/ReviewCardManageTest.php`（256 tests）
- **风险等级**：高（不能改变 FSRS 语义）
- **未来角色**：AI 示意卡最终会创建 ReviewCard

### 2.5 pending occurrence 系统

- **文件**：`app/Models/WordSenseOccurrence.php`、`app/Services/WordSenseOccurrenceService.php`
- **当前能力**：记录阅读中遇到的未处理词义，支持 confirm / ignore / reject / bind / create-sense
- **前端**：`SenseMappingReview.vue`、`SenseReview.vue`
- **风险等级**：中
- **未来角色**：AI 推荐词可能通过 occurrence 系统流入，但现有 UI 需要改造

### 2.6 SenseReview / SenseMappingReview

- **文件**：`SenseReview.vue`（685 行）、`SenseMappingReview.vue`（455 行）
- **当前能力**：SenseReview = 复习卡评分；SenseMappingReview = 词义确认（待确认 occurrences）
- **风险等级**：中（不能随意删除）
- **未来角色**：这些页面未来应被统一复习入口替代

### 2.7 AI 阅读辅助

- **文件**：阅读页 `TextReader.vue` 中“AI 阅读辅助”和“显示 AI 译文”按钮
- **当前能力**：调用后端 AI 翻译接口，显示译文
- **当前实现**：AI 译文功能独立，不创建复习卡
- **风险等级**：低（不影响 FSRS）
- **未来角色**：AI 译文不应自动生成示意卡

### 2.8 复习主入口

- **当前状况**：导航栏中"单词复习"下拉菜单包含"词义确认"和"复习卡管理"两个入口。没有统一的"复习"入口
- **未来角色**：根据产品决策，主复习入口应统一为"复习"

---

## 3. 未来 AI 示意卡理想流程（用户视角）

1. 用户打开一篇文章阅读
2. 用户遇到不认识的词，**点击单词**或**拖动选择词组**
3. 用户有两种选择：
   - **手动添加释义**：立即输入释义 → 直接生成可复习示意卡
   - **标记为"待 AI 解释"**：暂存到待解释列表
4. 用户读完章节后，点击"AI 生成示意卡"按钮
5. 弹出预览窗口：
   - **用户已选择词**：自动进入生成范围（不需要二次确认）
   - **AI 推荐额外词**：系统根据上下文推荐的词
   - AI 推荐词**默认全不选**
   - 提供"全选"按钮
   - AI 推荐词**不会和用户已选择词重复**
6. 用户确认 → 系统为选中的词生成可复习示意卡（WordSense + ReviewCard）
7. 后续这些卡出现在统一"复习"入口

---

## 4. 当前代码接入点

### 阅读页选词 → 需要改造

| 文件 | 当前职责 | 未来作用 | 风险 | 第一轮改？ |
|------|---------|---------|------|-----------|
| `TextBlockGroup.vue` | 显示文本块，高亮已知/新词，支持点击查词 | 增加"标记待AI解释"交互 | 中 | ✅ 可考虑 |
| `TextReader.vue` | 阅读页主组件，管理侧边栏、工具栏 | 增加"AI 生成示意卡"按钮入口 | 中 | ✅ 可考虑 |
| `VocabularySideBox.vue` | 查词弹出侧栏 | 增加"待AI解释"按钮 | 低 | ✅ 可考虑 |

### 手动添加释义 → 可直接复用

| 文件 | 当前职责 | 未来作用 | 风险 | 第一轮改？ |
|------|---------|---------|------|-----------|
| `ManualWordSenseController::storeManualSense` | 创建手动 WordSense | 用户手动添加释义的入口基本可用 | 低 | ❌ 不改 |
| `WordSenseService::createManualSense` | 创建 WordSense + ReviewCard | 核心创建逻辑可复用 | 中 | ❌ 不改 |

### AI 推荐词 → 全新功能

| 预计需要的组件 | 说明 | 风险 | 第一轮做？ |
|--------------|------|------|-----------|
| "待 AI 解释"暂存表或字段 | 存储用户标记的待解释词 | 中 | ✅ 最小设计 |
| AI 推荐词接口 | 调用 AI 推荐候选词 | 中 | ❌ 暂缓 |
| "AI 生成示意卡"弹窗 | 用户确认窗口 | 中 | ✅ 可原型 |

### 现有页面 → 未来需要改造或逐步替换

| 页面 | 当前用途 | 未来处理 |
|------|---------|---------|
| `/senses/review` (词义确认) | 词义确认 | 统一到复习入口下 |
| `/reviews/senses` (词义复习) | 词义复习 | 统一到复习入口下 |
| `/review-cards/manage` (复习卡管理) | 管理页面 | 保留为高级功能 |

---

## 5. 不能直接改的危险区

1. **不改 FSRS 语义** — `FsrsSchedulingService`、`ReviewController::rateReviewCard` 不应修改
2. **不改删除/归档/恢复** — `WordSenseService::removeSenseFromReviewSystem`、`archiveSense` 受测试保护
3. **不删除 SenseMappingReview / SenseReview** — 这些页面当前仍有用户使用
4. **不删除 legacy word card 兼容层** — 数据安全要求保留
5. **不改 DB schema** — 如需新增字段/表，必须先做数据契约设计和 ADR
6. **AI 译文不生成复习卡** — AI 译文功能应保持独立
7. **AI 推荐词默认不选** — 前端和后端同时保证
8. **AI 推荐词不与用户已选词重复** — 后端去重 + 前端二次校验

---

## 6. 第一轮可实现的最小目标建议

建议第一轮最小目标为：

**让用户能在阅读页把词或词组标记为"待 AI 解释"，后端只记录待解释项，不生成复习卡。**

具体范围：
1. **阅读页**：在 `VocabularySideBox.vue` 或文字上下文菜单增加"标记为待AI解释"按钮
2. **后端**：新增 `pending_ai_explanations` 表（或复用 `word_sense_occurrences` 的某种状态）记录用户标记的词
3. **不调用真实 AI** — 标记只是占位，不触发任何 AI 请求
4. **不生成 ReviewCard** — 标记的词不自动变成复习卡
5. **不改 FSRS**
6. **页面给用户明确反馈** — 标记后词颜色变化或图标提示
7. **后续再接 AI 推荐弹窗**

为什么是这个最小目标：
- 不碰高风险的 WordSense / ReviewCard / FSRS
- 让用户立刻有一个可用功能
- 为后续 AI 推荐弹窗积累用户标记数据
- 架构边界清楚，容易回滚

---

## 7. 测试和验收建议

### 第一轮实现前

| 类型 | 内容 |
|------|------|
| 单元测试 | `pending_ai_explanations` 创建/查询/删除/用户隔离 |
| 功能测试 | API 端点：标记/取消/列表 |
| MCP Chrome | 阅读页 → 标记待AI解释 → 确认反馈 |
| CodeBuddy | 检查 DB schema 安全、用户隔离、不碰 FSRS |

### 第一轮实现后（AI 推荐词阶段）

| 类型 | 内容 |
|------|------|
| 单元测试 | 推荐词去重、用户已选词排除、默认不选 |
| 功能测试 | 弹窗全选/单选、确认后 WordSense 创建 |
| 页面 smoke | 阅读页 → 标记 → AI 生成弹窗 → 确认 → 复习入口可见 |
| WorkBuddy | 页面体验复验 |
| CodeBuddy | 不改 FSRS、不改删除语义、不改 legacy card |

---

## 8. 三方侦查分工

| 角色 | 下一步 |
|------|--------|
| **网页端总设计师** | 决定是否进入"标记待AI解释"最小目标阶段；产品取舍（UI 样式、按钮位置、文案） |
| **CodeBuddy** | 复核本侦查报告：代码事实是否准确、风险等级是否合理、危险区是否有遗漏 |
| **OpenCode / Codex / Trae** | 如果需要进入实现，先补数据契约设计和 ADR；执行侧方案实现 |

---

## 9. 进度影响

本轮完成后：
- **AI 示意卡规划**：≈ 10% → **≈ 25%**
  - 已完成：产品目标冻结、架构侦查、代码接入点识别、最小目标建议
  - 未完成：架构侦查未推动进入实现阶段
- **总体架构收口**：≈ 79% → **≈ 81%**
  - 新增侦查文档和风险排查，收口进度小幅推进

> 注意：这不是功能实现完成，只是架构侦查完成。不能写成"AI 示意卡已完成"。
