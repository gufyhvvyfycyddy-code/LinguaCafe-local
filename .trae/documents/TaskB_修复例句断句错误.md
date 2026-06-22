# Task B：修复 WordSenseOccurrence 例句断句错误 — 实现计划（v4 终版）

> 日期：2026-06-23
> 状态：可执行
> 依赖：无
> 修订：v4 — 修复 `isDottedAbbreviationPeriod` 处理点缩写链最后一个 `.`

---

## 一、源码现状

### 1.1 sentence_en 的生成链路

```
Python tokenizer (custom_sentence_splitter)
  → 给每个 token 赋 sentence_index (si)
  → 存入 chapter.processed_text

前端 TextBlockGroup.vue
  → buildSelectedSentenceText()  ← 用 sentence_index 拼接句子
  → commit vocabularyBox/setSentenceText
  → Vuex store.sentenceText

前端 WordSensesList.vue
  → createPayload()
  → sentence_en: form.example_sentence_en || sentenceText
  → POST /senses/manual

后端 SenseOccurrenceController::storeManualSense()
  → $data['sentence_en'] 直接透传
  → WordSenseService::createManualSense()
  → createManualOccurrence()
  → WordSenseOccurrence.sentence_en = $data['sentence_en']
```

**结论**：`sentence_en` 由前端 `buildSelectedSentenceText()` 生成，后端不做二次处理。

### 1.2 spaCy 分词器对英文的实际 token 形态

经查 spaCy `en_core_web_sm` 源码（`tokenizer_exceptions.py` 和 `punctuation.py`），确认以下 token 形态：

| 原始文本 | 分词结果 | 原因 |
|---|---|---|
| `15.2 percent` | `["15.2", "percent"]` | 数字+`.`+数字保持为单个 token |
| `Mr. Smith` | `["Mr.", "Smith"]` | `Mr.` 在 tokenizer_exceptions 中 |
| `Dr. Brown` | `["Dr.", "Brown"]` | `Dr.` 在 tokenizer_exceptions 中 |
| `U.S. retail` | `["U.S.", "retail"]` | `U.S.` 保持为单个 token |
| `e.g. tools` | `["e.g.", "tools"]` | `e.g.` 在 tokenizer_exceptions 中 |
| `i.e. they` | `["i.e.", "they"]` | `i.e.` 在 tokenizer_exceptions 中 |

`custom_sentence_splitter` 检查 `token.text` 是否**精确等于** `"."`。由于以上都是单个 token（句号没有被分离），`token.text` 是 `"Mr."` 而非 `"."`，不会触发错误断句。

**结论**：对于标准英文格式，`custom_sentence_splitter` 不会在 `Mr.` `Dr.` `U.S.` `15.2` 处错误断句。但非标准格式（如 `U . S .` 有空格）可能出错。

### 1.3 前端 buildSelectedSentenceText() 的逻辑

```js
buildSelectedSentenceText() {
    var sentenceIndex = this.selection[0].sentence_index;
    var sentenceText = '';
    for (var i = 0; i < this.words.length; i++) {
        if (this.words[i].word == 'NEWLINE' || this.words[i].sentence_index !== sentenceIndex) {
            continue;
        }
        sentenceText += this.words[i].word;
        if (this.words[i].spaceAfter) {
            sentenceText += ' ';
        }
    }
    return sentenceText.trim();
}
```

**问题**：完全依赖 `sentence_index`。如果 tokenizer 给错了 `sentence_index`，会得到错误句子。

### 1.4 `this.words` 数组结构

| 字段 | 说明 |
|---|---|
| `word` | token 文本（如 `"Mr."` `"surged"` `"15.2"` `"."`） |
| `sentence_index` | 句子索引 |
| `is_structure` | 是否为结构 token（`pos === 'STRUCT'`） |
| `spaceAfter` | 后面是否需要空格 |
| `wordIndex` | 在 token 数组中的业务索引（**不等于 `this.words` 的数组下标**） |

结构 token 包括：`NEWLINE`、`PARAGRAPH_BREAK`、`[A]`-`[Z]`（isSectionMarker 判定）。

### 1.5 `isSectionMarker` 实现

```js
isSectionMarker(word) {
    if (typeof word !== 'string') return false;
    if (word.length === 3 && word[0] === '[' && word[2] === ']' && word[1] >= 'A' && word[1] <= 'Z') return true;
    if (word.startsWith('_SECT_') && word.length === 8) return true;
    return false;
},
```

### 1.6 前端测试环境

**不存在**。项目没有 jest/vitest/mocha。断句逻辑只能通过浏览器验收。

---

## 二、实现方案：token-window 抽句

### 2.1 核心思路

不依赖 `sentence_index`。从当前选中词在 `this.words` 中的真实位置出发，**向左、向右扫描**，直到遇到可靠句子边界或硬边界，用扫描得到的 token 范围拼接 `sentence_en`。

### 2.2 硬边界（不可跨越）

以下 token 遇到立即停止扫描，**不包含**在句子中：

- `word === 'NEWLINE'`
- `word === 'PARAGRAPH_BREAK'`
- `is_structure === true`
- `isSectionMarker(word)` 为 `true`（`[A]` `[B]` 等）

### 2.3 句子边界判断（英文）

对 `.` `?` `!` 三个标点做判断。扫描时遇到的 token 分两类：

- **独立标点 token**（如 `"."` `"?"` `"!"`）→ spaCy 拆分出来的
- **带标点的 token**（如 `"Mr."` `"15.2"` `"U.S."` `"left."`）→ spaCy 保持为单个 token

**独立标点 `.` 的判断**：

| 前一个 token 的形态 | 判断 | 原因 |
|---|---|---|
| 在缩写白名单中（如 `"Mr"` `"Dr"`）| **不是边界** | 缩写的一部分 |
| 是首字母缩写链一环（`"U"` `"S"` `"e"` `"g"` 等）| **不是边界** | `U . S .` `e . g .` 结构 |
| 以数字结尾 + 后一个 token 以数字开头 | **不是边界** | 小数点 `15 . 2` |
| 其他情况 | **是边界** | 正常句号 |

**带 `.` 结尾的非独立 token 的判断**（关键修正：不一律放过）：

| token 形态 | 判断 | 如何识别 |
|---|---|---|
| 已知缩写（`Mr.` `Dr.` `e.g.` `i.e.` `U.S.` 等）| **不是边界** | `isKnownAbbreviationToken()` |
| 小数（`15.2` `3.14` 等）| **不是边界** | `isDecimalToken()` |
| 首字母缩写链（`U.S.` `U.K.` 等）| **不是边界** | `isInitialismToken()` |
| 普通词+句号（`left.` `stayed.` `happened.`）| **是边界** | 以上都不匹配 |

### 2.4 缩写白名单（组件外常量，非响应式）

```js
// 放在 <script> 内、export default 之前
const ENGLISH_ABBREVIATIONS = new Set([
    // 人称
    'mr', 'mrs', 'ms', 'dr', 'prof', 'sr', 'jr',
    // 地址/公司
    'st', 'ave', 'blvd', 'rd', 'inc', 'ltd', 'co', 'corp',
    // 拉丁缩写
    'etc', 'vs', 'viz',
    // 时间
    'jan', 'feb', 'mar', 'apr', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec',
    // 军事/头衔
    'gen', 'col', 'capt', 'lt', 'maj', 'sgt', 'cpl', 'pvt',
    'rev', 'hon', 'gov', 'sen', 'rep',
    // 其他
    'no', 'vol', 'pp', 'ch', 'sec', 'fig', 'eq', 'al',
    'dept', 'univ', 'assn', 'bros',
]);

// 复合缩写白名单（带句号的完整 token，如 "e.g." "i.e." "U.S."）
const COMPOUND_ABBREVIATIONS = new Set([
    'e.g', 'i.e', 'u.s', 'u.k', 'u.n', 'a.m', 'p.m',
    'e.g.', 'i.e.', 'u.s.', 'u.k.', 'u.n.', 'a.m.', 'p.m.',
]);
```

**注意**：`e`、`g`、`i`、`a`、`m`、`p`、`u`、`s`、`k`、`n` 等单字母**不放入普通缩写白名单**。它们通过 `isInitialismToken()` 和首字母缩写链规则处理，避免规则过宽。

### 2.5 算法伪代码

```
function buildSelectedSentenceTextFromTokenWindow():
    if selection is empty: return ''
    if language is not 'english': return buildSelectedSentenceText()

    startIndex = resolveSelectedWordArrayIndex()  // 通过对象引用匹配，不是 wordIndex
    if startIndex < 0: return buildSelectedSentenceText()  // fallback

    // 向左扫描
    left = startIndex
    while left > 0 and tokenCount < 120:
        candidate = this.words[left - 1]
        if isHardBoundary(candidate): break
        if isSentenceBoundary(candidate, left - 1): break
        left--

    // 向右扫描
    right = startIndex
    while right < this.words.length - 1 and tokenCount < 120:
        candidate = this.words[right + 1]
        if isHardBoundary(candidate): break
        if isSentenceBoundary(this.words[right], right): break
        right++

    // 拼接 [left, right] 范围的 token
    text = joinTokens(left, right)
    if text.length > 600: return buildSelectedSentenceText()  // 超长回退
    return text
```

### 2.6 辅助函数清单

| 函数 | 用途 |
|---|---|
| `resolveSelectedWordArrayIndex()` | 通过对象引用匹配 `this.words` 中的真实数组下标（**不直接用 `wordIndex`**） |
| `isHardBoundary(word)` | 检查 `NEWLINE` / `PARAGRAPH_BREAK` / `is_structure` / `isSectionMarker` |
| `isSentenceBoundary(word, index)` | 检查 `word` 是否为句子边界标点 |
| `isKnownAbbreviationToken(word)` | 带 `.` 的 token 是否在复合缩写白名单中 |
| `isDecimalToken(word)` | 是否为小数 token（如 `15.2` `3.14` `1,234.56`） |
| `isInitialismToken(word)` | 是否为首字母缩写链（如 `U.S.` `U.K.`） |
| `isAbbreviationPrecursor(prev)` | 独立 `.` 前的 token 是否在缩写白名单中 |
| `isDottedAbbreviationPeriod(index)` | 独立 `.` 是否处于 `字母 . 字母 .` 点缩写链中（中间点或最后点） |
| `isDecimalSplit(prev, next)` | 独立 `.` 是否处于 `数字 . 数字` 小数点中 |
| `joinTokens(left, right)` | 拼接 token，用 `spaceAfter` 控制空格 |

---

## 三、实现细节

### 3.1 `resolveSelectedWordArrayIndex()` — 核心修正 1

**`wordIndex` 不等于 `this.words` 数组下标**。必须通过对象引用匹配或 `wordIndex` 双重匹配找到真实下标。

```js
resolveSelectedWordArrayIndex() {
    if (!this.selection.length) return -1;

    const selected = this.selection[0];

    for (let i = 0; i < this.words.length; i++) {
        // 优先：对象引用匹配
        if (this.words[i] === selected) return i;

        // 备选：wordIndex 匹配（当对象引用不是同一个时）
        if (
            selected.wordIndex !== undefined &&
            this.words[i].wordIndex !== undefined &&
            this.words[i].wordIndex === selected.wordIndex
        ) {
            return i;
        }
    }

    return -1;
},
```

### 3.2 `isHardBoundary(word)`

```js
isHardBoundary(word) {
    if (!word) return true;
    if (word.word === 'NEWLINE' || word.word === 'PARAGRAPH_BREAK') return true;
    if (word.is_structure) return true;
    if (this.isSectionMarker(word.word)) return true;
    return false;
},
```

### 3.3 `isSentenceBoundary(word, index)` — 核心修正 2

```js
isSentenceBoundary(word, index) {
    if (!word) return false;
    const w = word.word;

    // ? 和 ! 总是边界
    if (w === '?' || w === '!') return true;
    if (w.endsWith('?') || w.endsWith('!')) return true;

    // 独立 . token
    if (w === '.') {
        const prev = index > 0 ? this.words[index - 1] : null;
        if (!prev) return true;

        // 前一个 token 以 . 结尾（如 "Mr."）→ 当前 . 是独立标点，不是配套
        if (prev.word.endsWith('.')) return true;

        // 缩写白名单（Mr . Dr . 等）
        if (this.isAbbreviationPrecursor(prev)) return false;

        // 点缩写链（U . S .  e . g .  i . e .  a . m .  p . m .）
        if (this.isDottedAbbreviationPeriod(index)) return false;

        // 小数点
        const next = index < this.words.length - 1 ? this.words[index + 1] : null;
        if (this.isDecimalSplit(prev, next)) return false;

        // 正常句号
        return true;
    }

    // 核心修正：带 . 结尾的非独立 token，不一律放过
    if (w.endsWith('.') && w !== '.') {
        if (this.isKnownAbbreviationToken(w)) return false;   // Mr. Dr. e.g. i.e.
        if (this.isDecimalToken(w)) return false;              // 15.2 3.14
        if (this.isInitialismToken(w)) return false;           // U.S. U.K.
        return true;  // left. stayed. happened. → 是句子边界
    }

    return false;
},
```

### 3.4 `isKnownAbbreviationToken(word)`

```js
// 判断带 . 的 token 是否为已知复合缩写
// 例如：Mr. Dr. e.g. i.e. U.S. a.m. p.m.
isKnownAbbreviationToken(word) {
    if (!word) return false;
    const cleaned = word.replace(/\.+$/, '').toLowerCase();
    return ENGLISH_ABBREVIATIONS.has(cleaned)
        || COMPOUND_ABBREVIATIONS.has(cleaned + '.')
        || COMPOUND_ABBREVIATIONS.has(cleaned);
},
```

### 3.5 `isDecimalToken(word)`

```js
isDecimalToken(word) {
    if (!word) return false;
    return /^\d[\d,]*\.\d+$/.test(word);  // 15.2  3.14  1,234.56
},
```

### 3.6 `isInitialismToken(word)`

```js
// 首字母缩写链：U.S.  U.K.  U.N.  等
isInitialismToken(word) {
    if (!word) return false;
    return /^([A-Z]\.){2,}$/.test(word);
},
```

### 3.7 `isAbbreviationPrecursor(prev)`

```js
// 独立 . 前面的 token 是否是缩写的一部分
// 例如：Mr . Smith → prev = "Mr" → 在缩写白名单中
isAbbreviationPrecursor(prev) {
    if (!prev) return false;
    const cleaned = prev.word.replace(/\.+$/, '').toLowerCase();
    return ENGLISH_ABBREVIATIONS.has(cleaned);
},
```

### 3.8 `isDottedAbbreviationPeriod(index)` — 核心修正 3

**v3 漏洞**：原 `isInitialismChainElement` 只能识别 `U . S .` 中间的 `.`（`next` 是单字母），无法识别最后一个 `.`（`next` 是普通词如 `retail`）。用户点击 `retail` 向左扫描时，最后一个 `.` 被误判为句子边界，导致 `U . S .` 被丢弃。

**修复**：新增 `isDottedAbbreviationPeriod(index)`，同时处理中间点和最后点两种情况。

```js
// 判断独立 . 是否属于 U . S . / e . g . / i . e . / a . m . / p . m . 点缩写链
// 要求至少两个单字母和两个点形成链条，避免任意单字母 + . 都被放行
isDottedAbbreviationPeriod(index) {
    const current = this.words[index];
    if (!current || current.word !== '.') return false;

    const prev = this.words[index - 1];
    const next = this.words[index + 1];

    // 前提：前一个 token 必须是单字母（大小写均可）
    if (!prev || !/^[A-Za-z]$/.test(prev.word)) return false;

    // 情况 1：中间点 — U . S 或 e . g（next 是单字母，链条仍在继续）
    if (next && /^[A-Za-z]$/.test(next.word)) {
        return true;
    }

    // 情况 2：最后点 — U . S . retail 或 e . g . tools
    // 需要确认前面存在：单字母 + . + 单字母 + 当前 .
    // 即 this.words[index-3] 是单字母，this.words[index-2] 是 .
    const beforePrev = this.words[index - 2];
    const beforeBeforePrev = this.words[index - 3];

    if (
        beforeBeforePrev &&
        beforePrev &&
        /^[A-Za-z]$/.test(beforeBeforePrev.word) &&
        beforePrev.word === '.'
    ) {
        return true;
    }

    return false;
},
```

**注意**：`e`、`g`、`i`、`a`、`m`、`p`、`u`、`s` 等单字母**不放入普通缩写白名单**。它们通过本函数处理。只有当处于 `字母 . 字母 .` 结构（至少两个字母两个点）中时才被识别为非边界。单字母 + `.` 后面直接跟普通词的不满足条件。

### 3.9 `isDecimalSplit(prev, next)`

```js
isDecimalSplit(prev, next) {
    if (!prev || !next) return false;
    return /\d$/.test(prev.word) && /^\d/.test(next.word);
},
```

### 3.10 `buildSelectedSentenceTextFromTokenWindow()` 主方法 — 核心修正 4

```js
buildSelectedSentenceTextFromTokenWindow() {
    if (!this.selection.length) return '';

    // 非英文走原逻辑
    if (this.$props.language !== 'english') {
        return this.buildSelectedSentenceText();
    }

    const startIndex = this.resolveSelectedWordArrayIndex();
    if (startIndex < 0) {
        return this.buildSelectedSentenceText();  // fallback
    }

    // 向左扫描（最多 120 token）
    const MAX_TOKENS = 120;
    let left = startIndex;
    let tokenCount = startIndex - left;
    while (left > 0 && tokenCount < MAX_TOKENS) {
        const candidate = this.words[left - 1];
        if (this.isHardBoundary(candidate)) break;
        if (this.isSentenceBoundary(candidate, left - 1)) break;
        left--;
        tokenCount++;
    }

    // 向右扫描（最多 120 token）
    let right = startIndex;
    tokenCount = right - startIndex;
    while (right < this.words.length - 1 && tokenCount < MAX_TOKENS) {
        const candidate = this.words[right + 1];
        if (this.isHardBoundary(candidate)) break;
        if (this.isSentenceBoundary(this.words[right], right)) break;
        right++;
        tokenCount++;
    }

    // 拼接
    let text = '';
    for (let i = left; i <= right; i++) {
        text += this.words[i].word;
        if (this.words[i].spaceAfter && i < right) {
            text += ' ';
        }
    }
    text = text.trim();

    // 超长回退
    if (text.length > 600) {
        return this.buildSelectedSentenceText();
    }

    return text;
},
```

### 3.11 修改调用点

第 1362 行：

```js
// 改前
this.$store.commit('vocabularyBox/setSentenceText', this.buildSelectedSentenceText());

// 改后
this.$store.commit('vocabularyBox/setSentenceText', this.buildSelectedSentenceTextFromTokenWindow());
```

**保留原 `buildSelectedSentenceText()` 不动**，非英文路径和 `getExampleSentence()` 仍用它。

---

## 四、边界策略

| 场景 | 策略 |
|---|---|
| 非英文材料 | 走原 `buildSelectedSentenceText()` |
| 硬边界 | `NEWLINE` / `PARAGRAPH_BREAK` / `is_structure` / `isSectionMarker` → 停止 |
| `?` `!` | 总是句子边界 |
| 独立 `.` 前是缩写白名单 | 不是边界 |
| 独立 `.` 处于首字母缩写链 | 不是边界 |
| 独立 `.` 处于小数中 | 不是边界 |
| 带 `.` 的 token 是已知缩写/小数/首字母缩写 | 不是边界 |
| 带 `.` 的 token 是普通词+句号（`left.` `stayed.`） | **是边界** |
| 扫描超过 120 token | 回退 `buildSelectedSentenceText()` |
| 拼接结果超过 600 字符 | 回退 `buildSelectedSentenceText()` |
| `resolveSelectedWordArrayIndex()` 返回 -1 | 回退 `buildSelectedSentenceText()` |
| 旧数据 | 不自动重写 |
| 拖选词组 | 以 `selection[0]` 为起点，逻辑一致 |
| Ctrl+F / uniqueWordMap | 无影响 |

---

## 五、涉及文件

| 层级 | 文件 | 改动类型 |
|---|---|---|
| 前端 | `resources/js/components/Text/TextBlockGroup.vue` | 新增 2 个常量 + 10 个辅助方法 + 主方法 + 修改 1 行调用 |

**不需要改动的文件**：所有后端文件、所有其他前端文件、tokenizer.py。

---

## 六、测试计划

### 6.1 无前端测试框架

项目没有 jest/vitest/mocha。断句逻辑在 Vue 组件内，**无法单独单元测试**。全部通过浏览器验收。

### 6.2 后端测试

**不新增测试。** 后端不参与断句，新测试无法验证前端逻辑。

### 6.3 浏览器验收步骤

**验收 1：小数不截断**
1. 导入含 `Online retail sales surged 15.2 percent.` 的英文材料
2. 点击 `surged` → 添加释义
3. 例句 = `Online retail sales surged 15.2 percent.`

**验收 2：U.S. 不截断**
1. 导入含 `U.S. retail sales increased.` 的英文材料
2. 点击 `retail` → 添加释义
3. 例句 = `U.S. retail sales increased.`

**验收 3：Mr. Dr. 不截断**
1. 导入含 `Mr. Smith called Dr. Brown.` 的英文材料
2. 点击 `Smith` → 添加释义
3. 例句 = `Mr. Smith called Dr. Brown.`

**验收 4：多句不合并**
1. 导入含 `He left. She stayed.` 的英文材料
2. 点击 `left` → 例句 = `He left.`
3. 点击 `stayed` → 例句 = `She stayed.`

**验收 5：? 正常断句**
1. 导入含 `What happened? He left.` 的英文材料
2. 点击 `happened` → 例句 = `What happened?`

**验收 6：! 正常断句**
1. 导入含 `Stop! He left.` 的英文材料
2. 点击 `Stop` → 例句 = `Stop!`

**验收 7：空格型 U . S . 不截断（v4 新增）**
1. 导入含 `U . S . retail sales increased.` 的英文材料（token 被拆成 `U` `.` `S` `.` `retail` ...）
2. 点击 `retail` → 添加释义
3. 例句 = `U . S . retail sales increased.`（不是 `retail sales increased.`）

**验收 8：空格型 e . g . 不截断（v4 新增）**
1. 导入含 `Tools, e . g . hammers, are useful.` 的英文材料
2. 点击 `hammers` → 添加释义
3. 例句 = `Tools, e . g . hammers, are useful.`（不是 `hammers, are useful.`）

### 6.4 回归测试

```bash
php artisan test --filter=WordSense
php artisan test --filter=ReviewFsrsTest
php artisan test --filter=FsrsSchedulingServiceTest
php artisan test --filter=Vocabulary
npm run development
php artisan db:doctor
php artisan tokenizer:doctor --language=english
```

---

## 七、风险点

| 风险 | 等级 | 缓解 |
|---|---|---|
| `this.$props.language` 字段名不确定 | 中 | 执行前先确认实际字段名 |
| `wordIndex` ≠ 数组下标 | 高 | `resolveSelectedWordArrayIndex()` 通过对象引用双重匹配 |
| `w.endsWith('.')` 一律放行过宽 | 高 | 改为三分法：只有当 `isKnownAbbreviationToken` / `isDecimalToken` / `isInitialismToken` 之一为 true 时才放行 |
| 单字母放入白名单过宽 | 中 | `e` `g` `i` 等不放入普通白名单，通过 `isDottedAbbreviationPeriod` 处理 |
| 点缩写链最后一个 `.` 被误判为边界 | 高 | `isDottedAbbreviationPeriod` 同时处理中间点和最后点，向左回溯 `index-3` 确认链条
| 无限扫描 | 低 | 120 token 上限 + 600 字符上限，超限回退 |
| fallback 到原逻辑时行为不变 | 无 | 任何异常都回退到 `buildSelectedSentenceText()` |

---

## 八、不应在本任务中做的事

1. ❌ 不修改 Python tokenizer
2. ❌ 不修改后端任何代码
3. ❌ 不自动重写旧数据
4. ❌ 不修改 `getExampleSentence()`
5. ❌ 不修改 GPT 导入路径
6. ❌ 不新增 PHP Service
7. ❌ 不新增后端测试
8. ❌ 不引入前端测试框架

---

## 九、实现顺序

1. 确认 `this.$props.language` 实际字段名和 `this.words[i].wordIndex` 是否存在
2. 在 `<script>` 顶部（export default 之前）添加 `ENGLISH_ABBREVIATIONS` 和 `COMPOUND_ABBREVIATIONS` 常量
3. 新增 `resolveSelectedWordArrayIndex()` 方法
4. 新增 `isHardBoundary()` 方法
5. 新增 `isKnownAbbreviationToken()` `isDecimalToken()` `isInitialismToken()` 方法
6. 新增 `isAbbreviationPrecursor()` `isDottedAbbreviationPeriod()` `isDecimalSplit()` 方法
7. 新增 `isSentenceBoundary()` 方法（含三分法修正 + `isDottedAbbreviationPeriod` 调用）
8. 新增 `buildSelectedSentenceTextFromTokenWindow()` 主方法（含 120/600 上限）
9. 修改第 1362 行调用点
10. `npm run development` 编译
11. 浏览器验收 8 个场景
12. 运行回归测试

---

## 十、commit 建议

```bash
git add -A
git commit -m "fix: improve sentence extraction for manual word sense occurrences

- Replace sentence_index-based extraction with token-window scanning
- Scan left/right from selected word until hard/sentence boundary
- Three-way . handling: abbreviation / decimal / dotted-abbreviation-chain → not boundary
- resolveSelectedWordArrayIndex() via object reference, not wordIndex
- isDottedAbbreviationPeriod() handles both middle and terminal dots in chains
- 120 token max, 600 char max, fallback to original on overflow
- Non-English: keep original buildSelectedSentenceText() logic
- No tokenizer changes, no backend changes, no old data rewrite"
```

---

## 十一、保护清单

- 英文导入、tokenizer English fallback
- 阅读页 + 点词侧栏 + 词典
- Word Review / Sense Review 及对应测试
- GPT sense-mapping workflow
- 注册、登录、用户创建
- Pusher 本地降级
- `php artisan test` 独立测试数据库
- 不破坏点击单词、拖选词组、右侧面板下拉框、添加释义、Ctrl+F 修复
- 不破坏 `b20f668` 大小写修复
- 不破坏 Task A（添加释义后自动标记 Learning 7）
- 不改 tokenizer、study_base、lemma、LemmInflect、ECDICT、FSRS 调度