# Lemma-Origin 原词 + 原形显示架构核验

> **日期**：2026-06-30  
> **任务**：Lemma-Origin-DisplayArchitecture-1  
> **性质**：新功能架构先行，不实现 UI，不改业务代码。

## 1. 产品目标

- 用户希望阅读页 / 查词处显示"原词 + 原形"。
- 固定文案格式为 `geese → goose`。
- 目标是让用户一眼知道 `geese` 归到 `goose`，`better` / `best` 归到 `good`。
- 不改变已有功能：添加释义、AI 建议、ReviewCard、FSRS。

## 2. 当前数据事实

| 字段 | 前端 store 路径 | 数据来源 | 是否已有 | 备注 |
|------|----------------|----------|---------|------|
| surface（原词） | `vocabularyBox.word` | `uniqueWord.word` | ✅ | 当前词形 |
| lemma（原形） | `vocabularyBox.baseWord` | `uniqueWord.base_word` | ✅ | base word |
| studyBase | `vocabularyBox.studyBase` | `uniqueWord.study_base` | ✅ | 用户可编辑 |
| reading | `vocabularyBox.reading` | `uniqueWord.reading` | ✅ | 读音 |

**结论：前端已同时拥有 surface 和 lemma，不需要新增后端接口，不需要新增字段。**

### 关键文件

- Vuex store：`resources/js/vuex/VocabularyBox.js`
  - `state.word` — 原词 surface
  - `state.baseWord` — 原形 lemma
  - `state.studyBase` — 学习基准形

- 数据填充：`resources/js/components/Text/TextBlockGroup.vue` 第 1365-1375 行
  ```javascript
  commit('vocabularyBox/setWord', uniqueWord.word);            // surface
  commit('vocabularyBox/setBaseWord', uniqueWord.base_word);   // lemma
  commit('vocabularyBox/setStudyBase', uniqueWord.study_base || uniqueWord.base_word);
  ```

- 当前显示：`resources/js/components/Text/VocabularySideBox.vue` 第 42-65 行
  - 当前格式为垂直标签式：
    ```
    当前词形：geese
    词元：goose
    ```

## 3. 当前界面事实（MCP Chrome 验证）

验证方式：MCP Chrome 打开 `Test Irregular Lemma` 章节，点击 text 中的 `geese`。

查词栏侧栏当前显示内容（简化结构）：

```
单词基础信息
geese                       ← surface word 大标题
当前词形：geese  词元：goose  ← 标签式显示，带 [修改] 按钮
FSRS 熟悉度: XX%
词典搜索: [goose]            ← 搜索框已预填 lemma
```

验证到的关键观察：

1. ✅ surface（原词）已显示在页面中（"geese"）
2. ✅ lemma（原形）已显示在页面中（"goose"）
3. ✅ 词典搜索框已预填 lemma（"goose"），说明后端已正确处理
4. ⚠️ 当前显示格式是标签式，不是箭头式
5. ⚠️ 原词和原形在侧栏中的位置分散（顶部标题 vs 词元标签）
6. ✅ 添加释义面板正常，不受影响
7. ✅ ECDICT 词典查询正常，结果按 lemma（goose）查询

## 4. 候选最小实现边界

**注意：此处只写实现候选方案，不直接实现。**

### 第一轮只做：

1. **改 `VocabularySideBox.vue`**（查词侧栏信息面板）：
   - 把"当前词形：X  词元：Y"标签格式改为箭头格式。
   - 原词与 lemma 相同时只显示原词，不显示箭头。
   - 保留"词元：[修改]"功能和 FSRS 熟悉度。

2. **改 `VocabularyBox.vue`**（浮动查词弹窗）：
   - 仅修正显示方向：原为 `lemma → surface`（错误），修正为 `surface → lemma`。
   - 不改保存逻辑、store、API、WordSense、ReviewCard、FSRS。

### 不改：

- 不改 `TextBlockGroup.vue`。
- 不改 `VocabularyHoverBox.vue`（悬停查词）。
- 不改 `TextReader.vue`。
- 不改后端接口。
- 不改 `VocabularyBox.js` store。
- 不改 WordSense 创建。
- 不改 ReviewCard。
- 不改 FSRS。
- 不改 tokenizer。

### 箭头格式建议位置

当前第 46-50 行：

```html
<div class="text-h6 default-font mb-1">{{ word }}</div>        ← surface 大标题
<div class="text-caption text--secondary">
    当前词形：<strong>{{ word }}</strong>                        ← surface 标签
    <span class="mx-2">词元：<strong>{{ studyBase || baseWord || word }}</strong></span>  ← lemma 标签
```

改为：

```html
<div class="text-h6 default-font mb-1">{{ word }} → {{ studyBase || baseWord }}</div>  ← 箭头格式

<!-- 保留词元修改功能，折叠或缩小显示 -->
```

## 5. 风险边界

| 风险 | 说明 | 级别 |
|------|------|------|
| 前端显示改动不影响后端 | 只改 VocabularySideBox.vue 的模板，不改 store / API | 低 |
| 误改 `baseWord` 语义 | baseWord 是 EncounteredWord.base_word，不应与 WordSense.lemma 混淆 | 中 |
| `studyBase` 用户可编辑 | studyBase 是用户手动设置的基准形，应优先于 baseWord 显示 | 低 |
| `word`（surface）和 `baseWord`（lemma）在不同场景不同 | 查词栏已有筛选逻辑 `studyBase || baseWord || word` | 低 |
| 箭头格式在窄屏幕的换行 | 建议 flex-wrap 或 small 样式适配 | 低 |
| 如果 `surface === lemma`（如 `lake`→`lake`），可以省略箭头 | 可选优化，第一轮不做 | 低 |

**绝对不能混用的字段**：

- `vocabularyBox.word` = surface（原词形）= EncounteredWord.word
- `vocabularyBox.baseWord` = lemma（词元）= EncounteredWord.base_word
- `WordSense.lemma` = WordSense 创建时从 EncounteredWord 复制的 lemma（独立字段）
- `WordSense.surface_form` = WordSense 创建时复制的 surface

**修改 VocabularySideBox.vue 不会影响**：

- WordSense 创建
- ReviewCard 创建
- EncounteredWord 保存
- Tokenizer 结果
- 导入链路
- FSRS 评分

## 6. 后续实现验收标准

第一轮 UI 实现完成后，用 MCP Chrome 验证：

1. 打开阅读页章节。
2. 点击 `geese` → 看到 `geese → goose`。
3. 点击 `better` → 看到 `better → good`。
4. 点击 `best` → 看到 `best → good`。
5. 点击 `went` → 看到 `went → go`。
6. 点击 `ran` → 看到 `ran → run`。
7. 点击 `mice` → 看到 `mice → mouse`。
8. 点击 `children` → 看到 `children → child`。
9. 点击 `was` → 看到 `was → be`。
10. 点击 `lake`（lemma=surface）→ 显示 `lake`（不显示箭头或显示 `lake → lake`，由实现决定）。
11. 不影响添加释义功能。
12. 不影响 ECDICT 词典查询。
13. 不影响 AI 阅读辅助。
14. 不影响复习卡管理。

### MCP Chrome 真实点击验收记录（Workflow-ContinuePromptRule-And-LemmaDisplayClick-1）

**测试日期**：2026-07-01 | **测试人**：OpenCode + MCP Chrome

| 操作 | 结果 | 实际显示（accessibility tree 首两项） |
|------|------|--------------------------------------|
| 点击 geese | ✅ | `geese` (surface) → `goose` (lemma) |
| 点击 better | ✅ | `better` (surface) → `good` (lemma) |
| 点击 best | ✅ | `best` (surface) → `good` (lemma) |
| 词典搜索框 | ✅ | 仍预填 lemma（goose / good） |
| 添加释义 | ✅ | 按钮正常，ECDICT 结果正常 |

**验证环境**：LinguaCafe v0.14.1, Chrome 浏览器, MCP chrome-devtools 工具链。测试章节 "Test Sentences"（chapter 7）。
