# LinguaCafe 产品原则与旧代码清理计划

> **性质**：产品定位 / 长期原则 / 功能行为约束
> **最新更新**：2026-07-03 (Codex-MorphologyMatrix-ImportRegression-1)
> **本文件优先于早期计划文档。如冲突，以本文为准。**

---

## 1. 产品定位原则

LinguaCafe-local 是面向当前用户的新产品，不承担原版 LinguaCafe 用户数据迁移义务。

因此：
1. 不需要为了"可能存在的旧用户数据"保留普通用户界面的旧版入口。
2. 普通界面优先服务当前产品主线。
3. 旧功能是否保留，取决于当前代码依赖、当前测试依赖、当前数据依赖，而不是原版历史用户迁移需求。
4. 如果某个旧功能没有当前产品价值、没有当前数据依赖、没有测试 / 代码依赖，应进入删除或隐藏路线。
5. 删除前仍必须先做代码依赖侦查和测试护栏，不允许盲删。

## 2. 旧版入口原则

普通用户界面可以移除"旧版示意 / 旧版释义 / legacy word review"等入口。

但要区分：
1. **用户入口**：可以优先隐藏或删除。
2. **后端兼容层**：必须先侦查依赖，再决定是否删除。
3. **DB 字段 / legacy target_type**：必须先确认当前业务、测试、seed、管理页是否仍引用，再决定是否删除。
4. 文档中不要再写"因为可能有历史用户数据，所以必须保留旧入口"。
5. 新原则是："没有迁移义务；只保留当前系统需要的兼容代码。"

## 3. Finished Reading 原则

原版 LinguaCafe 的 Finished reading 功能是合理的。功能要点：
- 点击章节末尾 Finished reading 后，把本章黄色新词设为 known。
- 更新每日阅读词数统计。
- 设置里可以关闭自动设为 known。
- 单个词可以用快捷键改回 new。

在 LinguaCafe-local 中也保留或恢复这个能力，但必须遵守：
1. 只处理黄色新词（EncounteredWord stage=2）。
2. 不处理绿色学习词（stage < 0）。
3. 不处理已有 WordSense 的词义卡。
4. 不处理 ReviewCard。
5. 不写 ReviewLog。
6. 不触发 FSRS。
7. 不改变归档 / 恢复 / 删除语义。
8. 不把读完章节等同于复习完成。
9. 如果当前本地代码已经有该功能，只记录并保留。
10. 如果当前本地代码没有该功能，只写入后续候选，不在本轮实现。

## 4. AI 例句原则

AI 不应该生成例句。例句必须来自真实原文：
1. 真实阅读材料。
2. 真实章节。
3. 真实句子。
4. 真实位置。
5. 可通过"查看原文 / 溯源"回到上下文。

AI 可以做的是：
1. 判断当前句子中某个词是否匹配已学 WordSense。
2. 判断是否是熟词僻义。
3. 给出词义建议。
4. 给出匹配理由。
5. **不能凭空造例句加入例句池**。

## 5. 熟词僻义原则

熟词僻义和 AI 推荐词必须分开：
1. AI 推荐词：按文章难度推荐用户可能不认识的词。
2. 熟词僻义：用户以前学过这个词，但当前句子里可能是另一个意思。
3. 熟词僻义**不应混入普通推荐词列表**。
4. 熟词僻义需要单独区域、单独统计、单独确认。
5. 只有用户确认后，才能进入新 WordSense / ReviewCard 的后续流程。
6. ~~本轮只写原则和计划，不实现。~~ **2026-07-03 (Trae-LemmaKnownSenseBridge-1) 已落地前置结构：`WordSenseKnownSenseService::listConfirmedSensesForLemma` 只返回当前用户/语言/lemma 的 confirmed WordSense（排除 rejected/ai_suggested）；`WordSensesList.vue` 「已学词义候选」面板与普通 AI 推荐词区域物理分离；「熟词僻义」info alert 明确标注「未调用 AI 判断」。AI 判断「是否是熟词僻义」、自动推荐、自动确认仍未实现。不写 ReviewLog、不改 FSRS、不生成 WordSense/ReviewCard。**

## 6. 阅读中刷卡原则

阅读中刷卡必须按 WordSense，不按 word。
1. 只有 AI 或系统已经匹配到具体 WordSense 时，才可以显示阅读中刷卡界面。
2. 如果匹配不确定，不能直接刷卡。
3. 如果疑似熟词僻义，进入熟词僻义确认。
4. 如果用户点击"不是这个意思"，不得写 FSRS。
5. 阅读中评分必须使用同一套 ReviewLog / FSRS 调度，不另搞一套。
6. 同一张卡当天如果已经以"记得 / 熟练"完成，不应再次默认算正式复习。
7. 如果当天之前点了"忘记 / 模糊"，再次在阅读中主动点击时可以再次弹出卡片，模拟短间隔再练。
8. 需要支持撤销，至少后续设计 Ctrl+Z / 撤销本次评分。
9. 本轮只写原则和计划，不实现。

## 7. 多例句轮换原则

题面例句也必须轮换，不能一直显示同一句。
1. 每个 WordSense 应有例句池。**(2026-07-02 已实现：`WordSenseExamplePoolService::exampleCandidates()`。)** 
2. 题面例句从例句池中轮换。**(2026-07-02 已实现：稳定 seed 轮换（review_card_id + fsrs_reps + day-of-year，crc32）。)**
3. 查看答案后的补充例句必须和题面例句不同。**(2026-07-02 已实现：`pickSupplementaryIndex()` 独立 seed + 保证不同。)**
4. 如果只有一条例句，不显示重复补充例句。**(2026-07-02 已实现：单例句时 supplementary 为 null；前端防御性去重。)**
5. 例句来自真实原文，不来自 AI 伪造。**(2026-07-02 已强制：来源仅 `WordSenseOccurrence` + card example fallback，不调 AI。)** 
6. 同一句不重复添加。**(2026-07-02 已实现：chapter + sentence 去重；card fallback 用 sentence-only 去重。)** 
7. 同章节不同位置、不同句子可以分别添加。**(2026-07-02 已实现：同章节同句子折叠，同章节不同句子保留。)** 
8. 溯源列表支持多个来源。**(2026-07-02 已实现：`SenseSourceContextService::sourceContextList()` + `/senses/{id}/source-context-list` + `SenseExampleDialog.vue` carousel。)** 
9. 来源切换顺序要"轮换 + 轻度洗牌"，避免每次 ABC 顺序完全一样。**(2026-07-02 部分：来源按 manual-sense-add-first + id desc 排序；轻度洗牌未实现。)** 
10. ~~本轮只写原则和计划，不实现。~~ **2026-07-02 已实现原则 1-8；原则 9 仍部分实现。**

## 8. 词形原型绑定原则

词形不能简单绑定到原型。

例如：
- `ways` 通常应查 `way`。
- `running` 可能是 run 的进行时，也可能是形容词"运行中的"。
- `published` 可能是 publish 的过去分词，也可能是形容词"已发表的 / 已出版的"。

因此：
1. 显示保留 surface，例如 `ways`。**(2026-07-03 已实现：阅读页点词显示 surface + lemma + [修改] 入口；`WordSensesList.vue` `lemma-surface-card` 显示当前词形与词元。)**
2. 搜索和添加释义优先使用 lemma，例如 `way`。**(2026-07-03 已实现：搜索框 `value=lemma`；`WordSensesList.vue` `effectiveLemma` (studyBase → baseWord → lemma → surface → word) 优先使用 studyBase/baseWord/lemma。)**
3. 用户手动修正 lemma 后，后续添加新释义应使用修正后的 lemma。**(2026-07-03 已实现：`VocabularySideBox::saveLemma` → `commit setStudyBase` → `POST /vocabulary/word/update`；`effectiveLemma` 优先使用 `studyBase`。测试 `test_add_new_sense_uses_corrected_lemma_after_user_edit` 验证 lemma 与 surface_form 独立存储。)**
4. 当前句子中的绑定必须考虑 surface + lemma + 词性 + 句义。**(仍未实现 — 当前仅前端显示 + lemma 优先搜索；未实现自动上下文感知绑定。)**
5. AI 可以建议，但用户必须能改。**(部分实现：用户可通过 [修改] 入口改 lemma；AI 建议仍未实现。)**
6. 不允许把所有 `published` 都无条件绑定到 `publish`。**(2026-07-03 已遵守：未新增自动绑定逻辑；用户修正始终优先。)**
7. ~~本轮只写原则和计划，不实现。~~ **2026-07-03 (Trae-LemmaKnownSenseBridge-1) 已实现原则 1-3 + 5-6。Codex-MorphologyMatrix-ImportRegression-1 补齐形态变化测试矩阵与文章 fixture 导入回归：覆盖 ways/technologies、mice/children、studies/watches、ran/went、written/published、running/studying、better/worse、used/broken；测试确认 surface 保留、lemma 显示、添加新释义 payload 优先 lemma 且保留 surface_form。原则 4（自动上下文感知绑定）+ AI 建议仍未实现；`published` / `running` / `used` / `broken` 等歧义词不得被测试或产品规则写成不可逆自动绑定。**

---

## 9. 当前代码只读侦查结果

### 9.1 Finished reading

| 项目 | 结果 |
|------|------|
| 是否存在 | ✅ 存在 |
| 前端入口 | `TextReader.vue` 底部 "完成阅读" 按钮 |
| 后端入口 | `ChapterController::finishChapter` → `POST /chapters/finish` |
| 处理逻辑 | `ChapterService::finishChapter()` |
| 是否只处理黄色 New 词 | ✅ 仅处理 `EncounteredWord stage=2` |
| 是否影响绿色 Learning 词 | ❌ 不影响 |
| 是否影响 WordSense | ❌ 不影响 |
| 是否影响 ReviewCard | ❌ 不影响 |
| 是否写 ReviewLog | ❌ 不写 |
| 是否触发 FSRS | ❌ 不触发 |
| 是否影响删除/归档语义 | ❌ 不影响 |
| 当前状态 | 已加测试护栏，建议保留 |
| 本轮补强 | `FinishedReadingSafetyTest` 锁定 yellow `stage=2` → known、green stage 不变、WordSense/ReviewCard/ReviewLog/FSRS 不变、用户/语言隔离、关闭 `autoMoveWordsToKnown` 时不自动 known |
| 本轮修复 | 自动 known 分支增加当前 `language` 过滤，防止构造 payload 改到同用户其他语言词 |

### 9.2 旧版入口

| 项目 | 结果 |
|------|------|
| "旧词条释义" | 本轮已从普通查词组件显示中隐藏 |
| 用户能否看到 | 普通查词 UI 不再展示旧版释义入口文案 |
| 入口用途 | 原用途为显示 / 编辑 ECDICT 旧词典翻译（legacy） |
| 是否属于当前主线 | ❌ 过渡性兼容，普通入口已隐藏 |
| 代码依赖 | 兼容字段和传参仍保留，后端兼容层未删除 |
| 测试依赖 | 新增 `LegacyEntryUiGuardTest` 防止旧入口文案回到普通查词组件 |
| 建议 | 继续保留后端兼容；未来若删除字段/路径，必须先做依赖审计 |

### 9.3 Legacy 兼容层

| 项目 | 结果 |
|------|------|
| `target_type=word` | ✅ 存在，`ReviewCard::TARGET_WORD = 'word'` |
| 后端引用位置 | `ReviewCardService`（4处）、`VocabularyService`、`Goal`、`FsrsDoctor` |
| 普通用户入口 | ❌ 前端无 target_type=word 的用户操作入口 |
| 当前产品价值 | 低（legacy，无新用户入口） |
| 测试依赖 | 部分已有测试仍引用 word card |
| 建议 | 保留后端兼容，不在本轮删除；后续可做代码依赖审计后决定 |

---

## 10. 后续阶段拆分建议

### 第一阶段：原则落地（本轮正好在做）
- 写入产品原则文档
- 修正冲突计划
- 记录侦查结果

### 第二阶段：入口清理（架构侦查后）
- 统一"复习"入口
- 逐步隐藏 SenseMappingReview / SenseReview 的内部概念入口
- 前端入口整理

### 第三阶段：旧词条释义普通入口隐藏（已完成）
- 从 `WordSensesList.vue` 移除 legacy 显示文案
- 从普通查词组件隐藏旧版释义折叠编辑入口
- 新增源码 guard，防止旧入口文案回到 `WordSensesList.vue` / `VocabularySideBox.vue` / `VocabularyBox.vue`
- 后端 legacy 兼容层未删除

### 第四阶段：Legacy word card 代码审计（长远）
- 确认 `target_type=word` 的未使用路径
- 确认测试覆盖
- 做删除候选
