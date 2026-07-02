# 前端复习入口统一路线冻结

> 状态：**第一轮已实现（Round 1 Done）**。旧路由保留，后续 alias / 高级分组细化未实现。
> 起点观察：`docs/plans/final-architecture-closure-report.md` §5、本轮 MCP Chrome 只读复核。
> 配套文档：`docs/plans/ai-study-card-v1-frozen-plan.md`、`docs/adr/ADR-0002-sense-only-and-ai-study-card-boundaries.md`。
> 适用阶段：架构收口 100% 之后的独立小切片，**不与 AI 示意卡第一版耦合**。

---

## 1. 当前前端入口现状

来自本轮 MCP Chrome 只读复核（`isolatedContext=linguaCafeClosure20260702`，登录测试账号后观察）。

### 1.1 顶部导航栏（阅读页 / 全站）

观察到的导航项：

| 导航文案 | 链接 / 行为 | 类型 |
| --- | --- | --- |
| 首页 | `/` | 普通链接 |
| 阅读材料 | `/books` | 普通链接 |
| 词汇 | `/vocabulary/search` | 普通链接 |
| 单词复习 | （展开子菜单） | 折叠菜单，内部名称 |
| 词义确认 | `/senses/review` | 普通链接 |
| 复习卡管理 | `/review-cards/manage` | 普通链接 |
| 设置 | `/user-settings` | 普通链接 |
| 用户手册 | `/user-manual` | 普通链接 |
| 管理员设置 | `/admin` | 仅管理员可见 |

### 1.2 首页「开始复习」按钮

- 首页显著位置有「开始复习」入口。
- 实际跳转目标：`/review/false/-1/-1`，即 **legacy word review**（target_type=word 的旧复习）。
- 这意味着用户从首页进入的「复习」走的是 legacy word card 兼容层，而不是 sense-only 主线。

### 1.3 复习相关路由总览

- `/review/false/-1/-1`：legacy word review（首页「开始复习」入口）。
- `/reviews/senses`：sense-only 复习（SenseReview 主线）。
- `/senses/review`：导航栏「词义确认」入口（与 SenseReview 主线相关，命名「词义确认」对用户不直观）。
- `/review-cards/manage`：复习卡管理（sense card + legacy word card 兼容层管理）。

### 1.4 体验问题清单

1. 「单词复习」是内部术语，普通用户不理解这是「legacy word」。
2. 「词义确认」是内部术语，普通用户不知道这是 sense-only 复习。
3. 「词义复习 / 词义确认」并存会让用户以为是两个功能。
4. 首页「开始复习」走 legacy 而不是 sense-only 主线，与未来方向不一致。
5. 「复习卡管理」暴露给所有用户，但它更像高级管理功能。
6. 用户没有单一清晰的「我要去复习」入口。

---

## 2. 为什么用户不应该看到「词义复习 / 词义确认」这种内部名称

### 2.1 内部术语外露的代价

- 「词义」「sense」是开发层概念，普通用户脑子里只有「单词」和「复习」。
- 用户不知道「词义确认」和「单词复习」区别是什么，会随机点。
- 用户不知道哪个是主线，哪个是兼容层。
- 设计者收到反馈时，无法判断用户实际走的是哪条复习路径。

### 2.2 与 ADR-0002 的对齐

ADR-0002 已经把 sense-only 定为日常复习主线，legacy word card 定为兼容层。导航命名应反映这个决定：

- 主线就叫「复习」。
- 兼容层不应对用户可见，或退到「高级」入口。

### 2.3 与未来 AI 示意卡的对齐

AI 示意卡第一版只引入「待 AI 解释」按钮，不引入新导航项。如果导航里继续保留「词义复习 / 词义确认 / 单词复习」三个内部名称，未来加 AI 示意卡相关入口会让导航更乱。统一在先，AI 示意卡在后。

---

## 3. 未来主入口应统一为「复习」

### 3.1 目标导航结构（建议，非本轮实现）

| 用户可见文案 | 目标 | 说明 |
| --- | --- | --- |
| 复习 | `/reviews/senses` | **唯一**的日常复习入口（sense-only 主线） |
| 复习卡管理 | `/review-cards/manage` | 保留，但归到「高级 / 设置」区域 |
| （不可见） | `/review/false/-1/-1` | legacy word review 保留路由，但不在主导航暴露 |

### 3.2 命名规则

- 主入口文案统一为「复习」，不再使用「词义复习 / 词义确认 / 单词复习」。
- 「复习卡管理」保留名称，但位置后移。
- legacy word review 路由保留，作为兼容层，不在导航暴露。

### 3.3 第一轮实现状态

- 首页「开始复习」已从 `/review/false/-1/-1` 改为 `/reviews/senses`。
- 导航已合并为单一「复习」入口，指向 `/reviews/senses`。
- 导航不再暴露「单词复习 / 词义确认」内部名称。
- `高级复习卡管理` 仍指向 `/review-cards/manage`，作为高级入口保留。
- `/senses/review`、`/review/false/-1/-1`、`/review-cards/manage` 路由均保留。

---

## 4. 三个复习相关路由的未来位置

### 4.1 `/reviews/senses`

- **未来位置**：主入口「复习」指向这里。
- **理由**：sense-only 是 ADR-0002 定的主线。
- **未来变化**：可能改路径为 `/review` 或 `/reviews`，但第一轮不动路径，只改导航文案与跳转目标，避免破坏书签。

### 4.2 `/senses/review`

- **未来位置**：第一轮保留路由，但导航入口文案改为「复习」并指向 `/reviews/senses`；若两个路径行为一致，长期把 `/senses/review` 作为 alias 重定向到 `/reviews/senses`。
- **理由**：避免双入口混淆。
- **第一轮不动路径**，只动导航指向。

### 4.3 `/review-cards/manage`

- **未来位置**：移到「设置 / 高级管理」区域，不在主导航。
- **理由**：这是管理工具，不是日常学习入口。
- **第一轮不动路径**，只在导航里调整分组。

### 4.4 `/review/false/-1/-1`（legacy word review）

- **未来位置**：不在导航暴露，路由保留作为兼容层。
- **理由**：legacy word card 兼容层不能删，但不应引导用户主动进入。
- **第一轮不动路由**，只把首页「开始复习」按钮的跳转目标改为 `/reviews/senses`。

---

## 5. 第一轮最小改法

第一轮只做 **3 件事**，其它一律不动：

### 5.1 改首页「开始复习」跳转目标

- 从 `/review/false/-1/-1` 改为 `/reviews/senses`。
- 不改按钮文案（仍叫「开始复习」）。
- 不改其它首页元素。

### 5.2 改导航栏复习入口

- 把「单词复习」折叠菜单 + 「词义确认」合并为单一导航项「复习」。
- 指向 `/reviews/senses`。
- 不删除任何路由，只是导航里不再暴露。

### 5.3 把「复习卡管理」归组

- 在导航里把「复习卡管理」从主位置移到「设置 / 高级」分组（或靠近「设置」）。
- 不删除路由，不改页面内容。

### 5.4 第一轮显式不做

- 不改路由路径。
- 不删 `/senses/review`、`/review/false/-1/-1`、`/review-cards/manage` 任何路由。
- 不改任何页面内部文案。
- 不改 Vuex store。
- 不改后端 Controller。
- 不改 SenseReview / SenseMappingReview。
- 不动 legacy word card 兼容层逻辑。

---

## 6. 禁止一次性删除旧页面

### 6.1 禁止行为

- 禁止一次性删除 `/senses/review` 页面。
- 禁止一次性删除 `/review/false/-1/-1` 路由。
- 禁止一次性删除 `/review-cards/manage` 页面。
- 禁止把 legacy word card 兼容层从 UI 一刀切掉。

### 6.2 理由

- 旧书签 / 旧外链会断。
- legacy word card 兼容层仍有数据，强删会让用户感觉数据丢失。
- ADR-0002 明确 legacy 是兼容层，不是要立刻下线的功能。
- 渐进式收口风险低、可回滚。

### 6.3 收口节奏

- 第一轮：导航统一，路由全保留。
- 第二轮（未来）：把 `/senses/review` 改为 alias 重定向到 `/reviews/senses`。
- 第三轮（未来，需 ADR）：评估是否把 legacy word review 页面降级为「高级管理」入口，不在主导航暴露。
- 任何「删除页面」动作必须走 ADR。

---

## 7. 需要的 MCP Chrome 页面验收

### 7.1 第一轮改完后必须真实观察

- 首页「开始复习」按钮 → 真实跳转到 `/reviews/senses`。
- 导航栏「复习」入口 → 真实跳转到 `/reviews/senses`。
- 导航栏不再出现「单词复习 / 词义确认」内部名称。
- 「复习卡管理」入口仍可访问，页面内容不变。
- 直接访问 `/senses/review` 仍能打开（路由保留）。
- 直接访问 `/review/false/-1/-1` 仍能打开（路由保留）。
- 直接访问 `/review-cards/manage` 仍能打开（路由保留）。
- sense-only 复习流程不受影响（点词、判定、FSRS 调度正常）。
- legacy word review 仍可正常完成一次复习（兼容层未坏）。

### 7.2 验收规则

- 用 `mcp-chrome-local-smoke-playbook.md` 的隔离上下文登录流程。
- 不允许用 `axios` / `fetch` 替代真实页面观察。
- 不允许只看后端测试通过就声明完成。
- 必须真实点「开始复习」按钮、真实观察跳转目标。
- 必须真实在导航里确认文案变化。

---

## 8. 需要 WorkBuddy 页面体验复验

### 8.1 复验清单

- 普通用户视角：导航是否清晰、是否只有一个「复习」入口、是否还会被「词义 / 单词」术语困扰。
- 首页「开始复习」是否进入预期的 sense-only 主线。
- 「复习卡管理」是否仍可发现（不应对用户彻底消失，但不应在主位置打扰）。
- 老用户视角：直接访问旧 URL 仍可用，不会感觉功能丢失。
- 是否有 console error。
- 是否有非预期 network 请求。

### 8.2 复验边界

- WorkBuddy 只做页面体验复验，不改代码。
- WorkBuddy 反馈进 ADR / 计划文档，不直接改 Vue 组件。

---

## 9. 进度声明

- 路线冻结后：前端入口整理 50% → 65%。
- 第一轮最小改法实现并验收后：前端入口整理 92%（本轮任务指定口径）。
- 本轮只完成入口统一第一轮，不代表最终清理完成。
- 后续仍未完成：`/senses/review` alias、legacy word review 更深层降级、复习卡管理更完整的高级/设置分组。
