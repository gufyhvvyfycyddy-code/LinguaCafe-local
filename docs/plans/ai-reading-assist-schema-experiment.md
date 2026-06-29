# AI 阅读辅助 Schema 实验

> **实验编号**：AI-Reading-Assist-1
> **文档状态**：设计草案 + 实验材料准备完成，待真实模型调用验证
> **日期**：2026-06-29
> **模型对象**：DeepSeek Flash / DeepSeek Pro

---

## 1. 实验目标

设计 AI 阅读辅助的第一版结构化输出 schema，并比较 DeepSeek Flash 和 DeepSeek Pro 两个模型在相同文章、相同提示词、相同 schema 下的输出稳定性。

**最终目标**：让 AI 返回的内容能被程序直接解析，用户粘贴后即可预览，不需要手动修改格式。

---

## 2. 实验方法

### 2.1 实验流程

```
固定英文样本 → 固定提示词模板 → 分别发送给 DeepSeek Flash 和 DeepSeek Pro
→ 用 7 个维度评分 → 统计打分明细 → 输出推荐结论
```

### 2.2 评分维度

| 维度 | 说明 | 分数范围 |
|------|------|----------|
| JSON 可解析 | 是否能直接 JSON.parse | 0/1/2 |
| 字段完整 | 是否保留全部顶层字段 | 0/1/2 |
| 句子对齐 | sentence_translations 是否按句一一对应 | 0/1/2 |
| 生词准确 | vocabulary_items 是否符合语境 | 0/1/2 |
| 词组识别 | phrase_items 是否有固定搭配识别 | 0/1/2 |
| 不胡编 | 是否没有原文外内容 | 0/1/2 |
| 简洁性 | 是否没有过度解释和多余文本 | 0/1/2 |

**总分**：14 分

### 2.3 实验次数

每个模型至少运行 3 次，取平均分。

---

## 3. 第一阶段样本文本

本文本用于 schema 实验。它包含普通单词、变形词、词组结构，适合测试 AI 在语境释义和词组识别上的能力。

```text
Phenomenology is a philosophical tradition that investigates the structures of experience.

It draws on each other in ways that are not always obvious.

In this sense, we can say that the method is ubiquitous in modern thought.

Their approach emerged from the work of Husserl, who argued that consciousness is always intentional.

He went out of his way to describe how every act of thinking is directed toward something.

The investigators found that different people perceive reality in lesser or greater degree.

To a certain extent, this is true — but the question remains open.

These ways of thinking continue to influence contemporary research across multiple disciplines.
```

### 3.1 样本中的测试要素

| 要素 | 对应文本 |
|------|----------|
| 普通词 | their, ways, approach |
| 难词 | phenomenology, ubiquitous, intentional |
| 变形词 | investigates, perceived, thinking |
| 词组 | draw on each other, in this sense, lesser or greater degree, to a certain extent |
| 固定搭配 | went out of his way |
| 多句 | 共 8 句 |

---

## 4. Schema 设计

### 4.1 顶层结构

```json
{
  "schema_version": "linguacafe_ai_reading_assist_v1",
  "language": "english",
  "source": {
    "chapter_title": "",
    "word_count_estimate": 0
  },
  "sentence_translations": [],
  "vocabulary_items": [],
  "phrase_items": [],
  "warnings": []
}
```

#### 字段说明

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| schema_version | string | 是 | 固定值 `linguacafe_ai_reading_assist_v1` |
| language | string | 是 | 文章语言代码 |
| source | object | 是 | 文章元信息 |
| sentence_translations | array | 是 | 句子译文列表 |
| vocabulary_items | array | 是 | 生词释义列表 |
| phrase_items | array | 是 | 词组/固定搭配列表 |
| warnings | array | 是 | 不确定信息 |

### 4.2 sentence_translations

```json
{
  "sentence_index": 1,
  "source_text": "I eat an apple.",
  "translation_zh": "我吃一个苹果。"
}
```

#### 字段说明

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| sentence_index | int | 是 | 从 1 开始递增 |
| source_text | string | 是 | 原文中的英文句子 |
| translation_zh | string | 是 | 中文译文 |

#### 约束

- `sentence_index` 从 1 开始。
- `source_text` 必须是原文中的英文句子，不能合并或拆分。
- `translation_zh` 必须是中文。
- 不允许把整段译文合成一块。
- 不要求逐词对齐。
- 目标是在阅读页显示为：英文句子 + 下方中文译文。

#### 已知风险

- AI 切句可能不稳定（长句可能被拆成多个，或短句被合并）。
- 如果 AI 不能可靠切句，后续导入器需要做句子对齐修正。

### 4.3 vocabulary_items

```json
{
  "surface": "investigates",
  "suggested_lemma": "investigate",
  "pos": "verb",
  "sentence_index": 2,
  "source_sentence": "phenomenology investigates the conditions...",
  "meaning_zh": "研究；考察",
  "reason": "在本句中表示研究某事的条件",
  "confidence": "high"
}
```

#### 字段说明

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| surface | string | 是 | 原文出现的词形 |
| suggested_lemma | string | 否 | 系统推测原型 |
| pos | string | 否 | 词性 |
| sentence_index | int | 是 | 该词所在的句子序号 |
| source_sentence | string | 是 | 该词所在的完整原文句子 |
| meaning_zh | string | 是 | 本语境中的中文释义（简短） |
| reason | string | 否 | 为什么是这个释义 |
| confidence | string | 否 | high / medium / low |

#### 约束

- AI 只给本语境一种释义。
- `surface` 是原文出现的词形。
- `suggested_lemma` 只是建议，不自动全局写入。
- 用户未来添加时可选择：当前词形 / 系统推测原型 / 手动输入原型。
- `meaning_zh` 必须简短。
- 不要求 AI 给多个义项。
- 不自动创建复习卡。

### 4.4 phrase_items

```json
{
  "phrase": "go out of one's way",
  "sentence_index": 3,
  "source_sentence": "He went out of his way to help.",
  "meaning_zh": "特意努力去做某事",
  "trigger_words": ["went", "way"],
  "reason": "这里不是字面走路，而是固定搭配",
  "confidence": "high"
}
```

#### 字段说明

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| phrase | string | 是 | 原文中的词组 |
| sentence_index | int | 是 | 词组所在句子序号 |
| source_sentence | string | 是 | 完整的原文句子 |
| meaning_zh | string | 是 | 本语境中的中文释义 |
| trigger_words | array | 否 | 建议的触发词，用于提示用户 |
| reason | string | 否 | 为什么识别为词组 |
| confidence | string | 否 | high / medium / low |

#### 约束

- phrase 必须来自原文。
- 只给本语境一种释义。
- 不自动加入复习。
- 用户未来点击词组中的某个词时，系统再提示是否添加整个词组。
- 如果用户只添加单词，系统不强行用词组释义生成单词释义。
- `trigger_words` 用于未来提示："你不懂这个词可能是因为不懂这个词组"。

### 4.5 warnings

```json
{
  "type": "ambiguous_sentence_split",
  "message": "第 12 句切分可能不稳定。"
}
```

#### 字段说明

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| type | string | 是 | 警告类型 |
| message | string | 是 | 人类可读的警告描述 |

#### 用途

- 记录 AI 不确定的地方。
- 未来系统导入时可以显示提醒。
- 不要求用户手动改 JSON。

---

## 5. 提示词模板

### 5.1 完整提示词

```text
你是一个英语阅读辅助分析器。你的任务是根据给定的英文文章，生成结构化的中文辅助阅读数据。

## 任务要求

1. 只分析给定的英文文章。
2. 不补充文章外内容。
3. 不发表评论或解释。

## 输出格式

1. 只返回 JSON。
2. 不返回 Markdown。
3. 不返回代码块标记（不要用 ```json）。
4. 不返回任何解释文字。
5. 字段名必须完全按照下面的 schema。
6. 缺内容用空数组 [] 或空字符串 ""，不要省略字段。
7. 不要输出 trailing comma。
8. 不要输出注释。

## JSON Schema

{
  "schema_version": "linguacafe_ai_reading_assist_v1",
  "language": "english",
  "source": {
    "chapter_title": "",
    "word_count_estimate": 0
  },
  "sentence_translations": [
    {
      "sentence_index": 1,
      "source_text": "",
      "translation_zh": ""
    }
  ],
  "vocabulary_items": [
    {
      "surface": "",
      "suggested_lemma": "",
      "pos": "",
      "sentence_index": 1,
      "source_sentence": "",
      "meaning_zh": "",
      "reason": "",
      "confidence": "high"
    }
  ],
  "phrase_items": [
    {
      "phrase": "",
      "sentence_index": 1,
      "source_sentence": "",
      "meaning_zh": "",
      "trigger_words": [],
      "reason": "",
      "confidence": "high"
    }
  ],
  "warnings": [
    {
      "type": "",
      "message": ""
    }
  ]
}

## 翻译要求

1. 每个英文句子给一个中文译文。
2. 译文与英文句子一一对应。
3. 译文放在 sentence_translations 列表中。

## 生词要求

1. 只列出对中文读者可能造成困难的词（中等以上难度）。
2. 每个词只给本语境中的一种中文释义。
3. 生词放在 vocabulary_items 列表中。
4. 不列出过于基础的词（如 "the", "a", "is", "are", "have" 等）。

## 词组要求

1. 识别固定搭配、短语动词、非字面表达。
2. 每个词组只给本语境中的一种中文释义。
3. 词组放在 phrase_items 列表中。
4. trigger_words 建议列出词组中用户点击后应该触发提示的关键词。

## 输入文章

ARTICLE_TEXT_START

[将英文文章粘贴在这里]

ARTICLE_TEXT_END
```

### 5.2 使用说明

1. 复制以上完整提示词。
2. 将 `[将英文文章粘贴在这里]` 替换为实际英文文章全文。
3. 发送给 DeepSeek Flash 或 DeepSeek Pro（或兼容 OpenAI API 的其他模型）。
4. 将模型返回内容粘贴到 LinguaCafe AI 解析器。

---

## 6. 模型实验记录

### 6.1 实验环境说明

| 项目 | 说明 |
|------|------|
| 实验日期 | 2026-06-29 |
| 模型 1 | DeepSeek Flash（低成本快速模型） |
| 模型 2 | DeepSeek Pro（高精度模型） |
| 调用方式 | 待人工测试（本地环境无法直接调用模型 API） |
| 提示词版本 | v1（见第 5 节） |
| 样本文本 | 第 3 节 8 句样本 |

> ⚠️ **当前状态**：由于本地 OpenCode 环境无法直接调用 DeepSeek API（无 API key 配置，且禁止读取 `.env` 或保存 API key），本轮**未完成真实模型调用**。以下为实验材料和待测试方案。

### 6.2 待执行的测试步骤

1. **打开 DeepSeek Chat 网页端**（chat.deepseek.com）或 DeepSeek API Playground。
2. **使用 DeepSeek Flash 模型**。
3. **粘贴第 5 节完整提示词**，将 `[将英文文章粘贴在这里]` 替换为第 3 节样本文本。
4. **记录输出**。
5. **重复步骤 2-4 共 3 次**（每次重新发送）。
6. **切换到 DeepSeek Pro 模型**，重复步骤 3-5。
7. **按照第 2.2 节评分标准打分**。
8. **填写下节对比表**。

### 6.3 对比表模板

| 维度 | Flash 第 1 次 | Flash 第 2 次 | Flash 第 3 次 | Flash 平均 | Pro 第 1 次 | Pro 第 2 次 | Pro 第 3 次 | Pro 平均 |
|------|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| JSON 可解析 (0-2) | | | | | | | | |
| 字段完整 (0-2) | | | | | | | | |
| 句子对齐 (0-2) | | | | | | | | |
| 生词准确 (0-2) | | | | | | | | |
| 词组识别 (0-2) | | | | | | | | |
| 不胡编 (0-2) | | | | | | | | |
| 简洁性 (0-2) | | | | | | | | |
| **总分 (14)** | | | | | | | | |

### 6.4 预期判断标准

| 指标 | 合格线 |
|------|--------|
| JSON 可解析 | 3 次中至少 2 次可直接 JSON.parse |
| 字段完整 | 不省略必填字段 |
| 句子对齐 | 8 句中至少 7 句对齐 |
| 生词准确 | 至少识别 4 个合理生词 |
| 词组识别 | 至少识别 2 个合理词组 |
| 不胡编 | 无原文外内容或明显错误释义 |
| 简洁性 | 返回内容只含 JSON，无多余文本 |

### 6.5 对比结论（待实验后填写）

| 项目 | Flash | Pro |
|------|-------|-----|
| 平均分 | | |
| JSON 稳定性 | | |
| 释义准确度 | | |
| 词组识别能力 | | |
| 推荐为第一版默认 | | |
| 可作为低成本备选 | | |

---

## 7. 导入容错设计

### 7.1 支持格式

导入器应支持以下输入格式：

1. **纯 JSON**：AI 只返回 JSON 文本，无多余内容。最好。
2. **JSON 代码块**：AI 用 ```json 包裹 JSON。导入器应自动提取。
3. **带少量说明文字**：AI 在前面或后面写了少量说明文字。导入器应尝试提取第一个 JSON 对象。

### 7.2 容错处理

| 情况 | 处理方式 |
|------|----------|
| 纯 JSON | 直接 parse |
| 被 ```json 包裹 | 提取代码块内容后 parse |
| 前后有说明文字 | 正则提取第一个 { 到最后一个 } 之间的内容 |
| trailing comma | 尝试正则替换末尾逗号后 parse |
| 不可修复的错误 | 显示错误位置，不要求用户手动改 JSON |

### 7.3 导入流程

```
用户粘贴 AI 返回内容
  ↓
导入器解析 JSON
  ↓
  ┌─ 解析成功 → 进入预览
  └─ 解析失败 → 提示错误位置 + 建议重新生成
      ↓
预览页面：
  - 句子译文数量
  - 生词数量
  - 词组数量
  - 警告数量
  ↓
用户确认 → 数据写入（不自动创建复习卡）
  ↓
  ┌─ 用户确认 → 写入辅助数据
  └─ 用户取消 → 放弃
```

### 7.4 预览页面内容

1. **句子译文**：显示英文句子 + 中文译文列表，用户可逐句核验。
2. **生词**：显示 surface / suggested_lemma / meaning_zh，用户可勾选要添加的词。
3. **词组**：显示 phrase / meaning_zh，用户可勾选要添加的词组。
4. **警告**：显示 AI 自查的警告信息。

### 7.5 第一版限制

- 不自动批量创建复习卡。
- 不自动绑定 WordSense。
- 不自动创建 ReviewCard。
- AI 释义和词典释义并存，不互相覆盖。
- 用户确认后才写入辅助数据。

---

## 8. 风险与建议

### 8.1 已知风险

| 风险 | 影响 | 应对 |
|------|------|------|
| AI 切句不稳定 | 句子译文对齐错误 | 导入器做句子对齐修正 |
| AI 可能忽略部分句子 | 生词/词组遗漏 | 预览时提示句子完整性 |
| AI 可能重复同一释义词 | 冗余数据 | 导入时去重 |
| DeepSeek Flash 输出质量不稳定 | 需要多次尝试 | 设置 min_attempts=3 提示词约束 |
| DeepSeek Pro 可能过度解释 | 输出过长 | 提示词要求简短释义 |
| 无法解析 JSON 的场景 | 用户无法导入 | 导入器做多层容错，最终给清晰错误 |

### 8.2 下一阶段建议

1. **先做手动复制提示词 + 粘贴解析**（AI-Reading-Assist-2 + 3），不做实时 API 调用。
2. **在手动流程中通过用户真实使用收集 AI 输出样本**，积累至少 20 条后再进入自动模式。
3. **如果 DeepSeek Pro 在实验中输出质量明显优于 Flash**，第一版默认提示用户使用 Pro。
4. **如果 Flash 也能达到合格线**，可以作为低成本备选。

---

## 9. 实验材料包

### 9.1 样本文本（可直接复制）

```text
Phenomenology is a philosophical tradition that investigates the structures of experience.

It draws on each other in ways that are not always obvious.

In this sense, we can say that the method is ubiquitous in modern thought.

Their approach emerged from the work of Husserl, who argued that consciousness is always intentional.

He went out of his way to describe how every act of thinking is directed toward something.

The investigators found that different people perceive reality in lesser or greater degree.

To a certain extent, this is true — but the question remains open.

These ways of thinking continue to influence contemporary research across multiple disciplines.
```

### 9.2 提示词模板（可直接复制）

完整提示词见第 5.1 节。使用时将 `[将英文文章粘贴在这里]` 替换为样本文本。

### 9.3 入模板（可直接复制）

将第 9.1 节的样本文本粘贴到第 5.1 节提示词的 `ARTICLE_TEXT_START` 和 `ARTICLE_TEXT_END` 之间，然后空行分隔。
