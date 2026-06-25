# RightClickPanel Word Sense 计划

> **创建时间**：2026-06-25
> **上游依赖**：RightClickPanel-1（已完成）

---

## 一、侦察结论

### RightClickPanel-2-scout 结论（2026-06-25）

**侦察范围**：自动创建复习卡、最相关词义默认展开、快速查词体验

**关键发现**：

| 侦察问题 | 结论 |
|----------|------|
| 自动创建 sense review card？ | ✅ **已自动创建**。`+ 添加为新释义` → POST `/senses/manual` → `createManualSense()` → `createReviewCardForSense()` → `ensureSenseCard()` |
| 是否已满足用户目标？ | ✅ 满足。保存释义后自动创建复习卡，无需手动操作。 |
| 最相关词义默认展开？ | ❌ **尚未实现**。当前默认展开**所有**非空词性组（line 387-389 of WordSensesList.vue），不是只展开最相关的一个。 |
| 是否有现成判断依据？ | 有：`status`（confirmed/pending）、`review_card_id` 存在与否、`pos` 匹配词典预填词性。**无**：source context 匹配、sentence context 匹配、GPT mapping 结果直接返回给阅读页 API。 |

---

## 二、自动创建复习卡详析

### 创建路径

| 路径 | 入口 | 是否自动创建 Card |
|------|------|:--:|
| `createManualSense()` | 阅读页 `+ 添加为新释义` | ✅ |
| `createConfirmedSenseFromOccurrence()` | GPT mapping import → confirm | ✅ |
| `updateManualSense()` | 阅读页编辑已有释义 | ✅ |
| `confirmOccurrence()` | Occurrence 管理页绑定 | ✅ |
| GPT mapping pending occurrence | Import 导入未确认 | ❌（须手动 confirm） |

### `+ 添加为新释义` 完整链路

```
点击按钮 → emit('addDefinitionAsSense')
  → VocabularyBox.addDefinitionAsSense()
  → WordSensesList.openAddFormFromDictionary(payload)
  → 用户点"保存新释义"
  → POST /senses/manual
  → SenseOccurrenceController.storeManualSense()
  → WordSenseService.createManualSense()
    → createSense() — WordSense 状态=confirmed
    → createReviewCardForSense() — ReviewCard(target_type=sense)
    → createManualOccurrence() — WordSenseOccurrence 记录
  → 返回 sense + updated_word
  → 前端显示 "已保存新词义，并已创建词义复习卡"
```

**结论：无需额外开发，自动创建已就绪。**

---

## 三、最相关词义默认展开方案

### 当前行为

```js
// WordSensesList.vue:387-389
this.openPanels = this.senseGroups
    .map((group, index) => group.senses.length ? index : null)
    .filter(index => index !== null);
```

→ 所有非空组全部展开（按 noun → verb → adjective → adverb → preposition → conjunction → phrase → other 顺序）

### 可用判断依据

| 依据 | 可用性 | 精确度 |
|------|--------|--------|
| `status === 'confirmed'` | ✅ API 已返回 | 中 |
| `review_card_id` 存在 | ✅ API 已返回 | 低（所有 confirmed 都有 card） |
| POS 匹配词典预填词性 | ✅ 前端已知（payload.pos） | 高 |
| `source_chapter_id` 匹配当前章节 | ⚠️ API 返回 chapter_id 但未与当前上下文比对 | 高 |
| sentence context | ❌ 当前 `sentenceText` 存在但无 correlation logic | - |
| GPT mapping result | ❌ mapping 结果在 occurrence level，不在 sense candidate API | - |
| FSRS 最近复习状态 | ✅ API 已返回 fsrs_due_at/state | 低 |

### 推荐第一版方案（D.3-a）

**策略一（推荐·轻量）**：只展开**第一个有 confirmed sense 的词性组**

```js
this.openPanels = [];
const firstConfirmedIdx = this.senseGroups.findIndex(
    group => group.senses.some(s => s.status === 'confirmed')
);
if (firstConfirmedIdx >= 0) this.openPanels = [firstConfirmedIdx];
```

**策略二（词典匹配优先）**：当从词典点 `+ 添加为新释义` 进入时，展开词典预填的词性组

```js
// 在 openAddFormFromDictionary 中保存 matchedPos
// 在 fetchSenses 返回后优先展开 matchedPos 组
if (this.matchedPos) {
    const idx = this.senseGroups.findIndex(g => g.pos === this.matchedPos);
    if (idx >= 0 && this.senseGroups[idx].senses.length > 0) {
        this.openPanels = [idx];
    }
}
```

**策略三（最保守）**：展开第一个有 review_card 的非空组

**风险**：
- 用户可能错过其他词性的重要释义
- 策略二依赖词典返回的词性推断（`inferPartOfSpeech`），可能不准确
- 如确实需要手动展开，可加「展开全部」按钮

**建议**：第一版做策略一（最先有 confirmed sense 的组），简单可靠。后续有 source context / POS 匹配时再升级到策略二。

---

## 四、UI 优化建议

### 词典结果高度控制

当前词典结果无高度限制。如果 ECDICT 返回大量释义（如 8+ 条），会导致侧栏很长。建议：

```html
<div class="dictionary-results-container" style="max-height: 300px; overflow-y: auto;">
```

### 旧词条释义默认折叠

✅ 已折叠（默认展开图标为 chevron-down，内容区 `v-if` 控制）

### WordSense 面板位置

当前在词典结果下方。建议保持现状——先看词典释义，再确认/管理词义是合理流程。

---

## 五、已完成任务

### RightClickPanel-3-a（✅ 已完成，2026-06-25）

**内容**：最相关词义默认展开 + 词典结果高度限制。

**实现**：
- **WordSensesList.vue**：`fetchSenses()` 中 `openPanels` 默认只展开最相关词性组。规则：优先展开第一个含 `status=confirmed` 的组 → 否则第一个非空组 → 否则不展开。`createSense()` 保存后自动展开对应词性组（保持已有逻辑）。
- **VocabularySearchBox.vue**：词典结果区域增加 `max-height: 300px; overflow-y: auto` 滚动限制。API 结果和本地词典结果包在 `dictionary-results-scroll` 容器内，加载/无结果状态在容器外不受影响。`+ 添加为新释义` 按钮增加 `title="保存后会加入词义复习"` 提示。
- 纯前端改动，不改后端。

### 推荐下一步（按优先级）

| 优先级 | 任务 ID | 描述 | 预估 effort |
|--------|---------|------|-------------|
| 1 | D.3-a | ~~最相关词义默认展开~~ ✅ 已完成 | - |
| 2 | D.3-b | ~~词典结果高度限制~~ ✅ 已完成 | - |
| 3 | D.3-c | 窄屏浮动小框验收（关闭侧栏 → 切 < 960px → 验证 hover box） | 中（浏览器验收） |
| 4 | D.3-d | POS 匹配优先展开（从词典进入时展开预填词性组） | 小（~30行） |
| 5 | D.3-e | 扩展全部按钮（已读完按钮收起的用户，可一键展开） | 小（~10行） |

---

## 六、不做

- ❌ 不做 GPT mapping 结果集成到 reading page sense candidate API（涉及 occurrence 层改造，超出快速查词范围）
- ❌ 不做 source context 自动匹配（当前 source context 数据在 occurrence 表，不在 sense 表）
- ❌ 不做 full-sentence context similarity comparison
