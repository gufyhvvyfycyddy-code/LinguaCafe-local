# Lemma-Origin 架构核验

> **日期**：2026-06-30  
> **任务**：Lemma-Origin-Architecture-1  
> **性质**：只读架构核验，不实现新功能，不改 tokenizer。

## 1. 目标

- 确认当前 English lemma/origin 的边界。
- 确认 tokenizer、spaCy、LemmInflect fallback 的保护条件。
- 确认导入链路中 lemma / surface_form 保存路径。
- 确认风险边界和第一轮允许做的事。
- **不实现新功能，不改 tokenizer，不改数据库。**

## 2. 当前 tokenizer 事实

| 项目 | 结论 |
|------|------|
| English tokenizer | spaCy `en_core_web_sm`，禁用 NER 和 parser，保留 POS tagger 和 lemmatizer |
| LemmInflect | 已导入 (`import lemminflect`)，已作为 fallback 使用 |
| fallback 触发条件 | `language == 'english'` 且 `token.lemma_ == token.text.lower()`（spaCy 无法 lemmatize） |
| fallback POS 限制 | 只对 `VERB` / `NOUN` / `ADJ` / `ADV` 触发 |
| fallback 安全规则 | `candidate != token.text.lower()`、`len(candidate) >= 2`、`|len(candidate) - len(token.text)| <= 4` |
| fallback 是否为 English-only | ✅ 是，其他语言不触发 |
| Python tokenizer 不可用时 | PHP 端 `TextBlockService` 有 fallback（第 157 行），日志记录"继续但无词形还原"。此时 lemma=surface。 |

**位置**：

- Python tokenizer：`tools/tokenizer.py` 第 314-326 行（`tokenizeText` 函数内）
- PHP workaround：`app/Services/TextBlockService.php` 第 157 行

### spaCy `en_core_web_sm` 词形还原特点

spaCy `en_core_web_sm` 依赖 lookup table（词形-原形映射表）。它对于 80-85% 的常见词工作良好，但：

- 对规则变化（-ed, -ing, -s 结尾）覆盖好：`opened→open`, `called→call`, `walking→walk`
- 对不规则变化覆盖有限：`ran→run` 可能失败，`mice→mouse` 可能失败，`better→good` 可能失败
- spaCy 在 `lemma == surface` 时表示"查不到"。

### LemmInflect 补充效果

LemmInflect 专门为英语不规则形态设计，准确率 ~96%。当前 fallback 逻辑已经正确限制了：

- ✅ 只 English
- ✅ 只 VERB / NOUN / ADJ / ADV
- ✅ 只在 spaCy 确认查不到时触发
- ✅ 有长度合理性校验

**但 spaCy 可能在某些不规则词上返回错误的 lemma（而非 surface）**，此时 fallback 不会触发。例如 spaCy 可能把 `children` 返回 `child`（正确），但 `better` 可能返回 `better`（就是 surface），fallback 会正确判断并尝试 LemmInflect。

### 当前 health 端点测试用例

`/tokenizer/health` 端点测试了：`opened`, `called`, `stopped`, `running`, `walking`——这些都是规则变化词。**当前没有测试不规则形态。** LemmInflect 测试也只包含 `opened`, `called`, `children`。

## 3. 当前导入链路事实

```
ImportController → ImportService → [Python tokenizer] → chunk (raw text)
    → ProcessChapter job → ChapterService.processChapterText()
        → TextBlockService
            → tokenizeRawText()   (调用 Python tokenizer HTTP)
            → processTokenizedWords()   (填充 lemma/surface_form)
            → createNewEncounteredWords()   (保存 EncounteredWord)
```

| 阶段 | lemma 状态 |
|------|-----------|
| Python tokenizer 返回 | 每个 token 包含 `l`（lemma）、`w`（surface/word） |
| `processTokenizedWords()` | 直接从 `tokenizedWord->l` 赋值给 `$word->lemma` |
| 保存到 `EncounteredWord` | `encountered_words.lemma` 字段 |
| 后续 WordSense 创建 | 从 EncounteredWord 复制 lemma（`WordSense.lemma`） |
| 阅读页颜色依赖 | 通过 `EncounteredWord.stage`（不是 lemma） |
| 点词查词 | 通过 `surface_form` 匹配（不是 lemma） |

**关键观察**：

- `EncounteredWord.word` 是 surface form，`EncounteredWord.lemma` 是 lemma。
- `WordSense.lemma` 和 `WordSense.surface_form` 分别在创建时从 EncounteredWord 复制。
- 阅读页绿色深浅（FSRS 熟悉度）通过 `ReviewCard.stability` + `due_at` + `fsrs_state` 计算，**不依赖 lemma**。
- EncounteredWord 本身不参与 FSRS 评分逻辑。
- 查词/点词通过 surface form 匹配字典，不直接依赖 lemma。

## 4. 风险边界

| 风险 | 说明 | 级别 |
|------|------|------|
| 修改 tokenizer 可能影响所有已导入章节的重新处理 | 当前不重新处理已导入章节，**新增处理只影响新导入** | 低 |
| 修改 lemma 可能影响 WordSense 绑定 | WordSense 创建时复制 lemma，改 lemma 只影响新创建的 WordSense | 低 |
| 修改 tokenizer 可能影响阅读页颜色 | 阅读页颜色通过 EncounteredWord.stage 和 ReviewCard，不直接依赖 lemma | 低 |
| 修改 tokenizer 可能影响查词/点词 | 查词通过 surface form，不直接依赖 lemma | 低 |
| 修改 tokenizer 可能影响复习卡 | 复习卡通过 target_id 指向 WordSense，不是 lemma | 低 |
| **但** 修改 PHP 端的 `processTokenizedWords` 可能影响所有已处理章节 | 只影响新处理章节，不回溯 | 中 |
| 如果 Python tokenizer 不可用时，PHP fallback 会生成无 lemma 的数据 | 已有日志记录，但用户可能不知道影响范围 | 中 |

**第一轮绝对禁止**：

- 不修改数据库结构。
- 不修改 `encountered_words` 表。
- 不修改 `word_senses` 表。
- 不修改 `review_cards` 表。
- 不修改阅读页 UI。
- 不修改查词/点词逻辑。
- 不新增依赖。

## 5. 第一轮允许做什么

- **只做架构核验**（本轮已完成）。
- 如果后续进入实现，第一轮也只能：
  1. 在 Python tokenizer 端增加对不规则英语词形的测试（通过 `/tokenizer/health` 端点）。
  2. 如果有明确缺口，在现有 LemmInflect fallback 逻辑中增加安全补丁。
  3. 必须优先补测试（health 端点测试），再改代码。
  4. 补测试后要跑通现有全部测试。

## 6. 第一批验收样例

以下样例用于验证 English lemma 质量。当前 `/tokenizer/health` 端点应返回这些词的正确 lemma。

| surface | spaCy lemma（预期） | LemmInflect lemma | 说明 |
|---------|---------------------|-------------------|------|
| ran | run | run | 不规则动词过去式 |
| running | run | run | 现在分词 |
| runs | run | run | 第三人称单数 |
| mice | mouse | mouse | 不规则名词复数 |
| geese | goose | goose | 不规则名词复数 |
| better | good | good | 不规则形容词比较级 |
| best | good | good | 不规则形容词最高级 |
| went | go | go | 不规则动词过去式 |
| children | child | child | 不规则名词复数 |
| was | be | be | 不规则动词过去式 |
| studies | study | study | -y 结尾动词 |
| studied | study | study | -y 结尾过去式 |
| worse | bad | bad | 不规则形容词比较级 |
| worst | bad | bad | 不规则形容词最高级 |
| began | begin | begin | 不规则动词过去式 |
| begun | begin | begin | 不规则动词过去分词 |
| known | know | know | 不规则动词过去分词 |
| lying | lie | lie | 特殊-ing 形式 |
| easier | easy | easy | -y 结尾形容词比较级 |
| cheapest | cheap | cheap | 规则形容词最高级 |

## 7. 不做事项

- 不新增依赖。
- 不改数据库结构。
- 不改 WordSense schema。
- 不改 ReviewCard。
- 不改 FSRS。
- 不改阅读页 UI。
- 不改 AI 阅读辅助。
- 不改导入导出格式。
- 不做多语言 lemma 大改。
- 不做自动 API。

## 8. 下一步实现前提

- 必须先有 CodeBuddy 事实报告。
- 必须由网页端 GPT 基于 GitHub 最新代码判断是否进入实现。
- 必须先确认当前 tokenizer 已有能力缺口。
- 如果当前代码已经覆盖主要问题，则不进入实现，只补测试或记录。
- 进入实现前，必须先补 lemma 测试样例到 `/tokenizer/health` 端点。
