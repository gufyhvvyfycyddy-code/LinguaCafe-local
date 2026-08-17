# LinguaCafe 精简版产品总大计划

> 日期：2026-08-07；2026-08-18 按 English-only / reading-first rebaseline 更新
> 状态：Current supporting product plan；与 `docs/product/LinguaCafe_Product_Rebaseline_English_Reading_First_2026-08-18.md` 冲突时以后者为准
> 性质：精简产品大计划。旧 `docs/plans/linguacafe-master-plan.md` 只继续作为历史总账与既有实现追溯材料。当前 Phase G 执行顺序以 Goal ledger 为准。
> 当前仓库事实在创建本计划时重新核查：`origin/master = 1c9bdcd74fa793356ba3938f21c56405f3261e39`，本地 `master` 与远端存在分叉，工作区存在用户已有改动。后续任何开发仍必须重新 fetch 并保护用户资产。
> 执行规则：每个大阶段可以包含多轮并行任务；阶段内部可以持续推进，但每个大阶段完成后必须停下来由用户确认，不能自动跨入下一大阶段。

---

## 1. 新产品定位

LinguaCafe 的主产品只围绕一件事展开：

> 用户阅读真实英语材料，在具体句子中学习“这个词在这里是什么意思”；自然阅读会参与记忆强化，FSRS 负责补上自然阅读没有覆盖的词义复习。

产品体验参考墨墨背单词的成熟做法：

- 主流程少；
- 用户先回想，再显示答案；
- 答案出现后再展开释义和补充信息；
- 高级统计和工程信息退到次级入口；
- 首页承担每日打卡和今天要做什么；
- 移动端必须有清楚的返回/前进，不依赖桌面快捷键。

LinguaCafe 保留自己的差异：

- 正式学习对象是具体 `WordSense`；
- 原文语境和 `WordSenseOccurrence` 是学习证据；
- 同一个 lemma 可以有多个独立学习词义；
- 阅读中的自然巩固只在正式间隔到期且无需帮助时落到具体 Sense ReviewCard；阅读内失败后的正向复习必须回到外部 Sense Review，不能靠同次阅读反复曝光提高熟练度；
- AI 主要帮助文章翻译、词义生成和 occurrence→WordSense 消歧，不替用户决定所有学习内容。

---

## 2. 冻结的领域模型

### 2.1 正式学习对象

- `WordSense`：一个具体词义，是正式学习内容。
- `ReviewCard.target_type=sense`：该词义的正式 FSRS 调度对象。
- `ReviewLog`：每一次正式评分的事实记录。
- `WordSenseOccurrence`：某个具体词义在文章/例句中的来源证据。
- `EncounteredWord`：阅读颜色、词形出现、兼容总览，不再作为长期正式学习对象。
- `ReviewCard.target_type=word`：legacy，只做迁移和兼容，不进入新功能主线。

### 2.2 词形与 lemma

产品只做词形识别，不做历史词源。

例子：

- `geese → goose`
- `went → go`
- `broken → break`
- `studies → study`

计算主要放服务器；文章包带回已经算好的 `surface / lemma / POS`，移动端直接使用缓存结果。长期目标仍应支持 per-occurrence lemma/POS，避免同一 surface 在不同句子中的上下文差异被一个全局值覆盖。

---

## 3. 冻结的阅读学习规则

### 3.1 第一次阅读新文章

流程固定为：

1. 用户打开文章，先快速浏览。
2. 用户自己点击或长按拖选“不认识”的单词/词组。
3. 系统不再让 AI 自己猜“哪些词用户不认识”。
4. 系统生成 AI 阅读包。
5. 用户复制提示词给自己的 ChatGPT / DeepSeek 等外部 AI。
6. AI 返回严格 JSON。
7. 用户粘贴回 LinguaCafe。
8. 系统导入：
   - 逐句中文译文；
   - 用户标记词/词组的本语境中文释义；
   - 英文释义；
   - POS；
   - 需要时的新词义建议；
   - 已学 lemma 的 occurrence→WordSense 消歧结果。

### 3.2 AI 新词义默认模式

固定为两种设置：

- **确认后加入（默认）**：AI 结果先作为建议，用户确认后才创建 WordSense + Sense ReviewCard。
- **自动加入学习（用户主动开启）**：用户事先已经明确标记“不认识”，AI 成功导入后可以自动创建 WordSense + Sense ReviewCard；必须保留纠错和撤销入口。

设置放在“我的 → 阅读设置 / AI 阅读辅助”，不要每篇文章重复询问。

这里的“确认后加入”只回答一件事：**要不要创建新的 WordSense + Sense ReviewCard**。它与后面的“阅读词义核对”是两个不同动作：后者回答的是“当前文章里的这个 occurrence 到底对应哪一个具体词义”。两种确认在界面、数据和报告中必须使用不同名称，禁止合并成一个模糊的“确认”。

用户自己标记为“不认识”后，当前 ReadingSession 进入严格失败边界：

- 若 AI/人工最终确认这是一个 `new_sense`，即使开启“自动加入学习”并自动建卡，也**不能在同一次阅读里获得被动 Good，也不能伪造 Again**；它只是第一次进入学习，后续在外部 Sense Review 中开始正式复习。
- 若最终确认它其实对应一个**已经学过的 existing WordSense**，则这次“不认识”是对该已学 Sense 的失败证据；同一 ReadingSession 后面即使再次遇到、点击“认识/记得”，也不能写 Hard/Good/Easy 或被动 Good。精确 WordSense 确定后，最多允许通过正式评分唯一写入链记录一次 `Again`，之后必须等 FSRS 间隔，在外部 `/reviews/senses` 继续复习。

详细间隔边界见 `docs/adr/ADR-0059-reading-reinforcement-spacing-and-reader-review-boundary.md`。

### 3.3 阅读颜色

颜色表达“这个 lemma 与学习系统的关系”，不提前宣称当前 occurrence 已精确匹配某个词义。

- 无色：未建立学习关系，也未被用户特别标记。
- 生词色：本篇预览中用户明确标记“不认识”。
- 已学色：当前账号中这个 lemma 至少有一个正式已学 WordSense。
- 已学色深浅：未来仍可反映相应学习熟悉度，但不能把错误的 occurrence→sense 推断当作事实。

例如用户以前只学过 `bank=银行`，当前文章出现 `river bank`，`bank` 仍显示已学色，鼓励用户回想和点击。如果发现当前义项是“河岸”，可以创建新的 WordSense。

---

## 4. AI occurrence→WordSense 消歧：冻结方案

### 4.1 为什么必须有这一步

完成阅读后的被动复习必须落到具体 WordSense。系统不能只知道“这个 lemma 学过”。

一个额外安全规则随本计划冻结：

> 只要某个 occurrence 将触发被动 Good，而该 occurrence 还没有可靠的人类绑定，就必须先得到具体 sense 判断。即使账号里当前只有一个已学 WordSense，也不能默认当前句子一定就是旧词义，因为它可能是一个尚未建立的新义项。

因此“两个以上 WordSense”只是最明显的必消歧场景；真正的条件是“被动 Good 是否需要落到一个尚未可靠绑定的具体 sense”。

### 4.2 AI 输入

每个待判断 occurrence 只发送必要信息：

- 稳定 occurrence/article position ID；
- sentence_index；
- surface；
- lemma；
- POS（若已知）；
- 当前句子；
- 当前账号已有候选 WordSense：`word_sense_id + sense_zh + sense_en + pos`。

不发送完整词典。

机器绑定的长期身份是“稳定 occurrence/article position ID + 明确的 `word_sense_id`”。`lemma / POS` 是判断证据，不是绑定主键。以后即使 per-occurrence lemma/POS 算法改善，也不能静默改写已经由用户确认的 occurrence→WordSense 绑定；只能把新的不一致标记出来供复核。

### 4.3 AI 输出

必须返回稳定 ID，并采用严格结果：

- `matched_existing`
- `new_sense`
- `ambiguous`

`matched_existing` 才允许带 `matched_word_sense_id`。

`new_sense` 可以带 `sense_zh / sense_en / pos`。

`confidence` 固定为严格枚举：`high | medium | low`。不在产品层再引入一套 0～100 的阈值。只有 schema 校验通过且明确为 `high` 的 `matched_existing` 才可能进入“信任 AI”自动核对路径。

AI 还可以带很短的理由，但理由只用于预览，不参与机器绑定。

### 4.4 两种用户模式

#### 模式 A：词义核对列表（默认）

在文章完成前，用户可以随时打开“词义核对列表”。

列表只显示“未来可能影响这次被动复习结算、但当前具体词义仍需要核对”的 occurrence，不把每个普通词都塞进去。

每项至少显示：

- 当前词形；
- 当前句子片段；
- AI 判断的具体词义；
- 置信度；
- 用户操作：确认 / 改选另一个已学词义 / 标记为新词义 / 本次不计入被动复习。

**Phase A 只建立这个列表、核对动作和可被 Phase B 消费的 occurrence→sense 核对结果；它不在 Finish Reading 时强制拦截，也不写 ReviewLog / FSRS。** 用户可以提前核对，也可以暂时不处理。

从 **Phase B** 开始正式启用被动 Good 后，默认模式才增加完成阅读门：用户点击“完成阅读”时，如果还有会影响本次被动 Good 的未核对项目，系统先打开列表；用户核对或明确选择“本次不计入”后再结算。

#### 模式 B：信任 AI

用户在设置中主动开启后：

- `matched_existing`
- 结构校验通过
- `confidence = high`
- 候选 ID 属于当前用户/语言
- occurrence 尚未被显式复习

满足这些条件时，Phase A 可以把它标记为“已由信任 AI 获得可用绑定证据”；等 Phase B 启用结算后，可以直接用于完成阅读的被动 Good，不再要求用户逐项核对。

以下结果永远不能自动产生被动 Good：

- `ambiguous`
- `new_sense`
- `confidence = medium | low`
- 缺失/错误 ID
- schema 不完整
- 用户已显式点开或显式复习过的 occurrence/card

其中 `new_sense` 即使因为“自动加入学习”已经建卡，也仍然属于“本次刚发现自己不认识的新义项”，不会在同一次阅读获得被动 Good。这些项目进入核对/新词义处理，不污染正式调度。

---

## 5. 阅读自然巩固：只在间隔成立时记为 Good

阅读可以替代一部分机械刷卡，但不能绕过 FSRS 间隔。

### 5.1 触发条件

完成一次文章阅读时，某个 sense card 同时满足以下条件才可产生一次被动 `Good`：

1. 当前文章确实出现了该 Sense 对应 occurrence；
2. 该 WordSense 是此前已经学习过的 existing Sense，不是本次阅读刚建立的新 Sense；
3. 该 ReviewCard **已经按照正式 Sense Review 的同一 due 规则到期**；提前再次看到不算一次新的正向复习；
4. occurrence 有可靠具体 Sense 绑定；
5. 用户本次阅读没有点开答案、请求帮助或把该 Sense 标记为“不认识”；
6. 同一 ReadingSession 中这张 ReviewCard 尚未获得任何正向阅读结算。

### 5.2 强度与来源

满足上述条件时，FSRS rating 仍使用 `Good`，不创建“半个 Good”或 Reader 专属熟练度算法。ReviewLog 继续用 `reading_passive` 区分来源。

尚未到期的自然遇见可以保留为阅读证据，但**不改变 FSRS、不提高熟练度**。

### 5.3 去重、预览与幂等

- 同一 ReadingSession，同一 ReviewCard 最多一次正向 reading reinforcement；同一文章出现十次也不能叠加十次。
- 重新打开、刷新或立即另开阅读会话都不能绕过正式 due 时间。
- 用户点开答案、求助或标记“不认识”后，本次阅读对该卡的 passive Good 资格立即失效。
- Finish Reading 继续保持“只读预览 → 用户确认提交”，retry 必须幂等。
- `matched_existing + high` 只解决“这是哪个 Sense”的证据问题，它本身不能绕过 due、帮助状态或失败状态。

---

## 6. 阅读失败与外部复习边界

Reader 不再承担“失败后马上刷到认识”的短间隔复习循环。

### 6.1 已学 Sense 被标记为“不认识”

当用户在阅读里明确点击“不认识”，随后 AI/人工确认它其实对应一个已经学习过的 existing WordSense：

1. 这次操作成为该 ReadingSession 对该 ReviewCard 的失败边界。
2. 同一阅读里后续再次出现这个词义，即使用户此时感觉“认识了”，也不能写 Hard/Good/Easy 或 passive Good。
3. 只有在具体 WordSense 已确定后，才允许通过现有正式评分唯一写入链至多记录一次 `Again`；不能只按 lemma 猜卡。
4. `Again` 之后的下一次成功复习必须由外部 `/reviews/senses` 按 FSRS 到期时间呈现。
5. Reader 不实现 1 分钟/10 分钟等短学习步骤，不实现自己的 cooldown，也不因重复曝光提前升级熟练度。

### 6.2 真正的新 Sense

若“不认识”最终对应 `new_sense`：

- 创建/确认 WordSense + Sense ReviewCard 属于第一次进入学习；
- 本次阅读不写 `Again`，也不写 Good；
- 后续正式学习在外部 Sense Review 中开始。

### 6.3 历史 `reading_explicit`

已有 `reading_explicit` ReviewLog 和历史验收继续保留，不篡改历史。旧“Reader 作为普通 Again/Hard/Good/Easy 四按钮复习页”的 forward 产品要求已由 2026-08-18 间隔规则取代，不再要求维持为普通用户功能。

正式边界见 `docs/adr/ADR-0059-reading-reinforcement-spacing-and-reader-review-boundary.md`。

---

## 7. 单独 Sense Review：冻结体验

### 7.1 问题面

默认显示：

- lemma；
- 当前词形；
- POS；
- 当前用于复习的原文例句。

用户要回答的是：

> “这个词在这个句子里是什么意思？”

### 7.2 显示答案后

默认展开：

- 中文释义；
- **英文释义（有内容时默认显示）**；
- 必要的补充例句/相关搭配。

历史卡或迁移卡如果 `sense_en` 为空，英文释义区域直接隐藏，不显示“暂无英文释义”等占位，也不能为了填满界面自动生成内容。

当前问题面的例句不需要在答案区机械复制第二遍。答案直接在问题内容下面展开。

FSRS 技术字段、历史日志、稳定度、难度、到期时间、复杂诊断退到“更多 / 卡片信息”。

### 7.3 评分与返回

- Again / Hard / Good / Easy 继续保留自然中文文案。
- 移动端提供清楚的 `← 返回 | → 前进`。
- 返回上一项若包含刚发生的可撤销评分，复用已有正式撤销链。
- 桌面 Ctrl+Z / Ctrl+Shift+Z 只作为快捷键。

---

## 8. 首页与主导航：冻结方向

### 8.1 首页

保留首页，做成墨墨式“每日打卡 / 今天学习什么”界面。

首页只展示普通用户今天真正需要的信息：

- 今日是否完成学习打卡；
- 连续学习天数；
- 今日阅读进度；
- 今日待复习 Sense 数；
- 今天已完成的阅读/复习数量；
- 一个最明显的“继续学习”按钮；
- 最近一次未完成文章/复习的继续入口。

首页不默认展示 FSRS 参数、复杂统计图、数据库健康、Browser 管理信息。

### 8.2 四个主导航

四个内容主导航保持：

1. **阅读**
   - 四级真题
   - 六级真题
   - 考研真题
   - 我的材料
2. **复习**
   - 今日 Sense Review
3. **生词**
   - 已建立的 WordSense
   - 搜索、简单编辑、来源查看
4. **我的**
   - 阅读设置
   - AI 辅助设置
   - 下载与离线
   - 账号与同步
   - 高级功能入口

首页作为默认 landing / 打卡页。桌面可以有独立首页入口；移动端底部导航优先保持四个主功能，首页的最终返回入口在该阶段做真实交互验收后冻结，避免为了五个底栏按钮强行挤压。

“四级真题 / 六级真题 / 考研真题 / 我的材料”是阅读页的最终分类方向。Phase F 完成资料分类能力之前，不要求提前展示空的考试目录；现阶段可以继续把用户上传内容放在“我的材料”。Phase F 负责让用户逐步上传的真题按考试类型、年份和套次自动进入对应分类，避免 Phase A/C 先造一套临时元数据再重做。

---

## 9. 真题与材料来源：冻结为用户上传

当前阶段不建立 LinguaCafe 自带的四六级/考研真题内容库。

用户会逐渐自己上传：

- 四级真题；
- 六级真题；
- 考研真题；
- 其他英语材料。

因此当前产品只需要把“考试材料”作为一种用户材料分类和目录体验：

- 考试类型；
- 年份；
- 套次；
- 文章/题型元数据；
- 下载与离线状态。

内容采购、版权授权、官方题库合作当前不作为开发阻塞项。未来公开发行时只分发用户拥有权利或已取得授权的内容。

---

## 10. 本地 / 云端 / 外部 AI 的责任

### 10.1 手机本地

保存“近期马上要用”的东西：

- 用户主动下载的文章/真题；
- token / sentence / lemma / POS 处理结果；
- 当前文章词典摘要；
- 当前文章及最近学习相关 WordSense；
- 未来若干天复习包；
- 例句和必要译文；
- 离线产生、尚未同步的正式操作队列。

### 10.2 LinguaCafe 云服务器

最终权威：

- 账号；
- 文章与版本；
- WordSense；
- ReviewCard；
- ReviewLog；
- FSRS；
- WordSenseOccurrence；
- 完整英文词典；
- tokenizer / contextual lemma；
- AI 阅读包结构与导入校验；
- 同步、幂等、冲突、撤销和备份。

### 10.3 外部 AI

首版继续使用：

`复制提示词 → 用户自己的 AI → 粘贴严格 JSON`

服务器不替用户付费调用 AI，默认 AI token 成本为 0。

未来一键 AI 属于独立商业/隐私 Gate，不因为代码里已有 provider 能力就自动开放。

---

# 11. 大阶段执行路线

本计划故意把阶段做得较大。每个阶段内部可以拆成多个互不冲突的并行任务，持续多轮完成。**每个阶段关闭以后必须停下来让用户确认，再进入下一阶段。**

---

## Phase A — 阅读学习主流程与 AI 阅读包 V2

### 用户最终获得

一篇新文章从“第一次快速浏览”开始，到“标不认识词 → 复制 AI 包 → 粘贴 JSON → 得到译文、词义和消歧结果”，形成完整主流程。

### 范围

1. 新文章预览/标生词和词组体验收束。
2. AI Reading Assist schema 升级：
   - 用户标记词/词组成为唯一重点解释对象；
   - 增加 `sense_en`；
   - 保留逐句译文；
   - 增加 occurrence→WordSense 消歧输入输出；
   - 支持 `matched_existing/new_sense/ambiguous`；
   - 稳定 ID、逐项数量校验、严格 schema。
3. 增加“词义核对列表”产品、数据契约和核对结果保存；本阶段它是可随时打开的核对工具，不拦截 Finish Reading。
4. 增加“信任 AI”设置，但本阶段只产生可供后续结算使用的绑定证据，不提前写被动 Good。
5. AI 新词义默认“确认后加入”；设置中提供“自动加入学习”；本次新建 sense 永远不获得同一次阅读的被动 Good。
6. 长按拖选词组继续复用现有触摸基础。
7. occurrence→sense 绑定必须依赖稳定 occurrence ID 与 WordSense ID；当前 lemma/POS 只作为判断证据，为 Phase D 的 per-occurrence 改善保留兼容空间。

### 明确不做

- 不在本阶段写被动 FSRS Good；
- 不改正式评分算法；
- 不退休 legacy word card；
- 不开放服务器付费 AI。

### 阶段验收

- 一篇真实文章完整跑通上述 AI 文件闭环；
- 20～50 个目标稳定匹配，超量可自动分包；
- AI 错 JSON、漏项、重复 ID、非法 WordSense ID 全部安全失败；
- 用户词义核对列表可修正 AI 结果，并保存可由 Phase B 消费的核对证据；
- Phase A 的 Finish Reading 不因未核对项目被强制拦截；
- “信任 AI”只改变 occurrence 绑定证据是否需要人工核对，不产生本阶段以外副作用；
- 真实浏览器双 viewport + 触摸词组验收；
- 不写额外 ReviewLog / 不改变 FSRS。

---

## Phase B — 阅读自然巩固 + 失败后外部复习

### 用户最终获得

自然阅读在真正到期、无需帮助时可以替代一次机械 Good；但阅读不会让用户通过短时间反复看到同一个词来刷高熟练度。遇到“不认识”的已学 Sense 后，下一次成功复习必须回到外部复习页按 FSRS 间隔完成。

### 范围

1. 建立 ReadingSession identity 与 Finish Reading 幂等结算。
2. 正式消费 occurrence→Sense 核对/信任证据，但证据只回答“是哪一个 Sense”，不自动等于可评分。
3. 被动阅读 `Good` 增加正式 due 门：
   - 复用外部 Sense Review 的 canonical due 语义；
   - 未到期仅记录 exposure，不写 ReviewLog/FSRS；
   - 同一 ReviewCard/ReadingSession 最多一次；
   - 打开答案、求助、不认识都排除。
4. 已学 existing Sense 在阅读中被标记“不认识”时：
   - exact Sense 确定后至多通过正式 writer 写一次 `Again`；
   - 同次阅读后续任何“认识/记得”都不能变成 Hard/Good/Easy/passive Good；
   - 下一次正向评分只从外部 `/reviews/senses` 到期队列发生。
5. `new_sense` 第一次建卡不制造 Again，后续从外部复习开始。
6. 旧 generic Reader four-rating UI 不再是 forward 产品要求；历史 `reading_explicit` 数据保留。
7. Finish Reading 页面只展示真正会结算的 due passive Good、失败/待外部复习、待确认和排除数量。

### 高风险门禁

必须证明：

- passive Good 使用与外部 Sense Review 一致的 due 事实，不能只检查 enabled/lifecycle；
- 同一 ReadingSession 的 unfamiliar failure 能压住所有后续正向 Reader credit；
- existing Sense 的 Again 只有 exact WordSense 确定后才能写，且只走唯一正式评分链；
- new Sense 不被错误记 lapse；
- retry/延迟 AI 导回不重复写 Again；
- 普通 Sense Review 的 Again/Hard/Good/Easy 和 FSRS 间隔不回归。

### 阶段验收

专用 testing DB + 真实 Reader + 真实外部 Sense Review 必须覆盖：

- due 已学 Sense 自然阅读 → 一次 passive Good；
- not-due 已学 Sense 自然阅读 → 0 ReviewLog/FSRS 变化；
- 同一 Sense 多 occurrence → 最多一次 passive Good；
- 阅读中标记 existing Sense 不认识 → exact resolve 后至多一次 Again；
- 同次阅读稍后再次遇到并点击认识 → 0 正向评分；
- 到 FSRS 允许的下一次时点后，只从外部 `/reviews/senses` 完成下一次正向复习；
- `new_sense` 建卡 → 0 Again / 0 passive Good；
- Finish retry / AI paste-back retry 幂等。

---

## Phase C — Sense Review 墨墨式瘦身 + 打卡首页 + 四主导航

### 用户最终获得

打开软件后先看到一个简单的每日打卡首页；“复习”成为普通用户唯一正式复习入口，卡面清爽，答案后的英文释义默认显示。

### 范围

1. SenseStudyCard / SenseReview 产品瘦身：
   - 问题面保留原文例句；
   - Show Answer 后直接展开答案；
   - 中文释义 + 英文释义默认显示；
   - 当前例句不重复渲染；
   - FSRS 技术信息退入“更多/卡片信息”；
   - 现有理解辅助、学习反馈、复杂报告默认降级到次级入口。
2. 移动 `← 返回 | → 前进` 完整产品化。
3. 首页改造成每日打卡：
   - 连续学习；
   - 今日阅读；
   - 今日复习；
   - 完成状态；
   - 继续学习。
4. 四个主导航正式收束：阅读 / 复习 / 生词 / 我的。
5. “生词”入口在本阶段就只面向 WordSense，先提供已有 WordSense 的基础列表/搜索/查看入口；legacy word card 不得作为这个新一级入口的内容主体。Phase D 再完成历史迁移、兼容收口和更完整的 Sense 生词管理。
6. 高级管理功能统一进入“我的 → 高级”或桌面高级区域。
7. 普通用户不再看到“词义核对/旧单词复习/高级卡管理”等内部工程名称作为一级入口。

### 阶段验收

- 1920、900、430/390 等目标 viewport；
- 首页首次打开能直接知道今天做什么；
- Sense Review 从问题面到评分不出现重复例句/工程信息压迫；
- 英文释义默认可见；
- 返回/前进真实可用；
- 一级导航没有旧系统入口；
- 高级能力仍能从明确次级入口访问，没有被误删。

---

## Phase D — Legacy Word Card 迁移、Sense 生词库与词形架构收口

### 用户最终获得

“生词”页面真正以 WordSense 为中心，旧 Word Card 不再影响日常体验；旧学习数据尽可能安全迁到词义体系，无法确认的历史不会被伪造。

### 范围

1. 盘点所有 `target_type=word` 使用者、历史、路由、统计和兼容链。
2. 建立迁移分类：
   - 可唯一映射到一个 WordSense；
   - 需要用户确认；
   - 无法安全映射，保留只读 legacy 历史。
3. 禁止一张旧 word card 暴力复制给多个 sense cards。
4. ReviewLog 历史保留并明确来源，不伪造历史 sense identity。
5. 在 Phase C 已有 WordSense 基础生词入口上，完善搜索、编辑、来源总览和 legacy 历史说明。
6. EncounteredWord 从正式学习权威进一步退到阅读/兼容层。
7. per-occurrence lemma/POS 评估与必要实现；如果新的 lemma/POS 与既有已确认 occurrence→WordSense 绑定不一致，只标记为需要复核，不自动改写或失效既有绑定。
8. 词典只服务查词，不再承担错误的 lemma oracle 角色。
9. 旧页面/路由先隐藏、再做依赖审计，最后决定哪些可以真正退休。

### 阶段验收

- 迁移 dry-run 清单；
- migration/转换前备份；
- 可逆/可追溯；
- 用户/语言隔离；
- 旧 ReviewLog 不丢；
- 迁移后正式队列只出现 sense cards；
- 无法判断的卡不会被强行迁移；
- 阅读颜色仍正确。

---

## Phase E — 移动端日常学习与有限离线产品收束

### 用户最终获得

Android/iOS 的日常使用只围绕首页、阅读、复习、生词、我的；下载过的文章和近期复习可以有限离线使用，恢复网络后安全同步。

### 范围

基于既有 M1–M9 / M3–M8 资产做产品收束，不重复重造已经实现的 API/幂等/离线基础：

1. 直接复用 Phase C 已冻结并验收的首页/四主导航信息架构，把它落到原生/移动壳和有限离线场景；本阶段不重新设计第二套移动信息架构。
2. 下载文章包包含 token/sentence/lemma/POS、词典摘要、相关 WordSense。
3. 短期复习包包含未来几天必要 Sense cards。
4. 离线评分和操作进入幂等队列。
5. 阅读中的 due-only passive Good 与 existing-Sense unfamiliar→Again 使用既有 operation/同步边界；失败后的正向复习只在外部 Sense Review 执行，不在移动 Reader 内做短间隔重学。
6. 冲突提示用普通用户能理解的文案。
7. 长按拖选词组、Bottom Sheet、返回/前进、键盘/安全区完成移动验收。
8. 完整 ECDICT 保留服务器，端侧只缓存文章相关摘要。

### 明确不做

- 不建设 Anki 式完整本地权威数据库；
- 不保证任意时长完全离线；
- 不把完整 70 万+ 词典默认塞进 App；
- 不在此阶段强行完成 iOS 商店发布。

### 阶段验收

- 在线/断网/恢复网络三套路径；
- 评分幂等；
- 同步冲突；
- App 杀进程后待同步动作仍在；
- 下载文章可离线阅读；
- 近期 Sense Review 可离线完成并恢复同步；
- 服务器仍是最终权威。

---

## Phase F — 真题/我的材料产品化与内容目录

### 用户最终获得

用户可以逐渐把自己的四级、六级、考研真题和其他材料放进 LinguaCafe，并像一个正常题库/阅读库一样管理、下载和学习。

### 范围

1. 用户上传材料的简单流程。
2. 考试资料元数据：CET-4 / CET-6 / 考研、年份、套次、题型。
3. 我的材料普通分类。
4. 文章导入后的 tokenizer/AI/WordSense 主流程与普通文章一致。
5. 云端目录 + 按套下载 + 离线状态。
6. 内容更新和文章结构修复有 version，不要求重新发布 App。
7. 当前不做内容采购系统；用户自己上传是首要来源。

### 阶段验收

- 至少各用一套真实用户上传样本完成：上传 → 阅读 → AI 包 → 学词 → 下载 → 离线打开；
- 更新文章版本不会破坏已有 WordSenseOccurrence；
- 用户删除材料时必须先说明对来源和学习历史的影响。

---

## Phase G — English-only / reading-first 产品重基线与减重

当前 Phase G 不再只是“把高级菜单藏起来”。2026-08-18 的实际执行顺序以 Goal ledger 的 G-06A…G-06G 为准，本节只保留产品层摘要：

1. **English-only**：普通用户不再选择学习语言，不再进入 Japanese/JMDict/其它非英文主线；共享 user/language isolation 与历史数据不能因为 UI 收敛被误删。
2. **阅读间隔边界**：自然阅读只有在正式 due 间隔成立且无需帮助时才产生一次 passive Good；已学 Sense 在阅读内标记“不认识”后，同次阅读后续认识不算，下一次成功复习回到外部 Sense Review。
3. **AI 阅读**：全文翻译 + 用户标记词 + 已学 WordSense candidates 一次导出；相同/实质近义必须 `matched_existing`，不能制造重复 Sense；导回严格识别。
4. **稳定 Reader**：翻译显示/隐藏不移动英文；材料外显示真实进度；支持自动继续阅读和多个书签。
5. **学习记录**：首页日历点某天看到实际 WordSense；支持日期范围和 PDF/TXT/CSV 同源导出；每日目标是“在阅读中新学多少个 Sense”。
6. **记忆分析 / FSRS**：所有用户都能看懂容易遗忘/正在巩固/掌握稳定、未来复习压力；手动优化 + 默认每 30 天自动优化；参数优化与旧卡重排严格分开。
7. **工程型 surface 退役**：Saved Search 被学习记录替代；Tag/Marker generic 用户功能倾向退休；Manual Scheduling 离开普通流程；generic Browser 能力拆到生词详情/历史/诊断/数据迁移；Knowledge Hygiene 后台化；Article Health 变成材料异常诊断。

退休步骤仍然坚持“先有替代路径 → fresh caller/data/safety 审计 → 再隐藏/删除”。禁止为了代码干净先删共享 lower owner。

明确离开产品主线的内容仍包括：视频/YouTube/Jellyfin 学习流、字幕核心学习产品、非英文/JMDict、字体管理、历史词源、复杂词典管理和泛媒体管理。

---

## Phase H — 公开测试、100 并发容量与发布门禁

### 用户最终获得

一个可以让真实小规模用户长期使用、成本可控、数据安全、出了问题能恢复的 LinguaCafe。

### 范围

1. 100 人同时在线的阅读/查词/复习负载测试。
2. Laravel/DB/队列慢查询和 N+1 诊断。
3. 推荐首发基础设施预算继续按当前规划约 ¥600～¥1,000/月评估；需要更稳时 ¥1,200～¥2,500/月。
4. 文字/JSON 优先，控制图片、音频流量。
5. 外部 AI 默认仍由用户自己调用，LinguaCafe AI token 成本接近 0。
6. 自动备份、恢复演练、文章健康、用户隔离、审计。
7. 登录优先邮箱/Apple/微信等低频认证；短信只做可选方案。
8. 隐私、账号删除、同步设备撤销。
9. Android 发布准备；iOS 在有 macOS/Xcode/签名能力后做最终真实验收。
10. 真题内容在公开打包时只包含用户有权分发或已授权内容；当前用户自行上传不阻塞产品开发。

### 阶段验收

- 100 并发目标下无明显主流程错误；
- 关键 P95/P99 延迟、数据库连接、队列积压有可观察数据；
- 备份可恢复；
- 评分不重复；
- 用户/语言完全隔离；
- 网络失败不丢正式学习动作；
- 成本估算基于上线时最新价格重新核价；
- Web/Android/可用的 iOS 证据分平台记录，不伪造缺失平台。

---

# 12. 各阶段共同验收门禁

每个大阶段都必须经过以下门禁，但任务可按风险选择具体测试，不要求机械全量跑完：

1. **最新代码核查**：重新 fetch `origin/master`，记录 branch/HEAD/worktree；保护所有用户已有改动。
2. **架构落位**：明确 Controller/Service/UI/数据 owner，不允许继续向超大共享组件塞条件分支。
3. **数据边界**：涉及 WordSense / ReviewCard / ReviewLog / FSRS / import / sync 时，正常路径和拒绝路径都必须测。
4. **Testing DB**：所有会产生学习数据的浏览器验收只允许在明确绑定 testing DB 的服务器中完成。
5. **真实浏览器**：页面、按钮、触摸、弹窗、阅读、复习必须真实操作；API 200 不能替代。
6. **幂等与冲突**：评分、Finish Reading、离线同步、AI 导入、迁移、恢复必须验证重复请求和冲突。
7. **回归**：受保护的 Reader / Sense Review / tokenizer / import / login / sync 不得被破坏。
8. **并行安全**：同一文件、同一数据库写入 owner、同一个共享 testing DB 写测试不能被两个窗口同时持有。
9. **报告文件**：每个并行任务必须把最终报告写到本地指定路径，报告成为下一轮提示词输入之一。
10. **问题披露**：所有副执行窗口的正式报告必须包含独立的 `执行中遇到的问题 / Issues Encountered` 小节；无问题也必须明确写“无”。任务/prompt/owner 冲突、文件占用或锁、用户资产重叠、testing DB/端口/依赖/构建/登录问题，以及 DevSpace、Reasonix、MCP Chrome/浏览器等工具失败、超时、会话或结果恢复异常都必须保留。问题后来恢复或改走 fallback 也不能删；必须说明处理方式、是否解决、残余风险和是否影响验收证据可信度。关键证据未取得时不得写成通过。
11. **阶段停止**：阶段完成后输出阶段验收报告并停止，由用户确认后才能进入下一阶段。

---

# 13. 并行工作、执行模型与提示词文件

详细工作方式单独见：

`D:\Document\lingl\parallel-tasks\LinguaCafe_Parallel_Working_Schemes_2026-08-07.md`

当前执行方式以 `LinguaCafe_CURRENT_LOCAL_AGENT_MODEL_AND_PARALLEL_AUTHORITY_2026-08-17.md` 和 `docs/plans/vibe-coding-collaboration-rules.md` 为准：4 个 GPT-5.6 Sol fixed DIRECT 窗口按真实依赖图并行；OpenCode free 只作辅助，DeepSeek V4 Flash Free → MiMo V2.5 Free；两个 free 都不足时才允许 Reasonix paid MiMo。Codex 新任务只有用户当前明确点名才允许。执行窗口完成当前 DIRECT 后必须停止，不能自动跨入下一任务；主窗口验收后再生成下一轮提示词。正式评分/ReviewLog/FSRS、Finish Reading、legacy migration、sync/idempotency、共享 testing DB 和高冲突文件继续保持单-owner。

架构拆分时必须先结合当前产品权威、最新代码、适用的架构技能与用户提供的架构/Vibe Coding 字幕，再以 MaiMemo 官方材料作为背词产品第一外部参考，其他成熟付费学习产品用于交叉检查。字幕和外部产品只能提供设计方法/模式，不能覆盖 LinguaCafe 已冻结的 WordSense、统一正式评分内核和服务器最终权威。

本计划只冻结以下原则：

1. 当前用户工作方式是 1 个总设计师主窗口负责验收/设计，另开最多 4 个 fixed DIRECT 执行窗口；每轮给用户的并行提示词固定为 4 份独立文本框。
2. 四个执行窗口不按编号强制线性等待；无依赖立即并行，只有真实 predecessor 才等待。
3. 主窗口在执行窗口工作时继续做产品设计、报告验收、下一批切片设计和无冲突的只读工作。
4. 并行任务提示词都保存为本地文件。
5. 执行窗口完整读取提示词并建立自己的 To-do 后，删除**自己的那一个提示词文件**，不得删除其他任务文件。
6. 执行完成后必须把报告写入提示词指定的本地报告文件；聊天输出只作为辅助，不代替本地报告。
7. 总设计师生成下一轮任务前，必须读取最新报告文件、重新核查代码状态和当前阶段依赖。
8. 同一阶段可以多轮并行；大阶段之间不自动跨越。

---

# 14. 当前下一步

Phase A–F 已有历史实现/验收证据；当前 forward 工作从 Goal Phase G 继续。下一实现批次不能从旧 Phase A 重新开始。

当前 next executable 是 G-06A，同时允许与其文件/数据不冲突的 G-06B Architecture Gate、G-06C 独立实现切片提前并行 prestage。每轮仍由主窗口先 fresh 验收当前 HEAD/REPORT，再给出 4 份 fixed DIRECT；执行窗口完成后停止，不能自行进入下一 milestone。
