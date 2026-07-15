# LinguaCafe Review Settings Preset V1 计划

> **状态**：Preset V1A–V1D Completed / Production Closed
> **日期**：2026-07-15
> **实现基线**：`d9ae9d4f`（V1D 执行前 master；最终关闭 commit 见 Git 历史）
> **当前授权阶段**：Preset V1D 已完成；本轮不自动进入后续阶段
> **后续阶段**：Browser / ReviewCardManage architecture convergence（仅登记，尚未执行）

## 1. 一句话结论

Preset 是用户拥有的命名复习配置，一个用户的一种学习语言在任一时刻只绑定一个 Preset。V1A 建立 Default 与绑定，V1B 完成管理动作，V1C 收敛所有消费者并停止错误的全局回滚状态写入，V1D 完成高级工具 UX 与跨用户、跨语言生产验收。Preset V1 已完整生产关闭。

## 2. 为什么现在做

Settings 架构已经收敛为薄页面容器、独立设置区、单一 API client 和后端领域服务。当前遗留设置仍主要保存在 `settings.user_id = -1` 的全局记录中，无法表达：

- 不同用户使用不同复习目标；
- 同一用户的英语和日语使用不同配置；
- 多种语言共享一套配置；
- 一次修改影响所有绑定对象；
- 配置的复制、重命名和安全删除。

因此 Preset 是 Settings 收敛后的下一项正式产品能力。

## 3. Anki 官方事实

### 3.1 产品语义

Anki Deck Options Preset 的稳定语义：

- 多个 deck 可以共享一个 Preset；
- 修改 Preset 会影响所有使用它的 deck；
- Add Preset 从默认值创建；
- Clone 复制当前 Preset；
- 支持 Rename 和 Delete；
- 新建 deck 默认使用 Default；
- 配置变化默认不追溯修改已排程卡片，重排必须显式执行。

LinguaCafe 没有稳定 deck 树，因此把共享对象映射为“用户 + 学习语言”。

### 3.2 架构语义

Anki 官方实现把 `deckconfig` 作为核心领域模块；Qt `DeckOptionsDialog` 主要打开独立 Web 页面，配置读取、更新和删除进入核心服务。LinguaCafe 采用同一方向：

- Preset 规则和持久化进入后端领域服务；
- HTTP payload 形成稳定契约；
- Vue 页面只展示、选择和提交，不复制配置合并与隔离规则；
- 调度消费者只读取一个“当前有效配置”入口。

### 3.3 官方来源

- Anki Manual — Deck Options / Presets
- Anki repository — `rslib/src/deckconfig`
- Anki repository — `qt/aqt/deckoptions.py`
- Anki repository — legacy `qt/aqt/deckconf.py`（新增、复制、重命名、删除行为参考）

## 4. 字幕工程原则

本计划结合项目库九份 AI 编程 / spec / harness 字幕，采用以下门禁：

1. 先冻结对象身份、归属、绑定和删除语义，再开发 UI。
2. 每条业务规则只有一个后端入口，页面和调度不得各写一份。
3. Spec 只记录已经稳定的决定；仍在探索的 Leech 配置不进入 V1 核心 schema。
4. 权限、用户隔离、语言隔离、迁移兼容和不自动重排必须进入可执行 harness。
5. 每阶段形成可独立验收的竖切，不一次开发完整 Preset 产品。
6. 拆分按职责和数据流，不按文件行数机械切块。
7. 真实页面和数据库事实负责最终验收，Agent 自述不能代替证据。

## 5. Preset V1 产品契约

### 5.1 身份与归属

- Preset 归属于一个用户。
- Preset 名称在同一用户内必须唯一；保存前 trim，空名称拒绝。
- 每个用户必须有且只有一个 Default Preset。
- Default Preset 可以修改和复制，不能重命名为其他名称，不能删除。
- 不同用户可以使用相同 Preset 名称，数据绝不共享。

### 5.2 用户 + 语言绑定

- 每个 `user_id + language_id` 只能绑定一个 Preset。
- 一个 Preset可以同时被同一用户的多种语言绑定。
- 修改 Preset 后，所有绑定语言下次读取时立即使用新配置。
- 新语言首次进入复习设置时绑定用户自己的 Default Preset。
- 不创建 deck/subdeck 树，不把书籍、章节或 Saved Search 绑定为 Preset。

### 5.3 新增、复制、重命名、删除、切换

这些动作在 V1B 实现，V1A 先冻结语义：

- **新增**：从系统默认值创建新 Preset。
- **复制**：完整复制当前 Preset 的 V1 配置，生成新的独立 Preset。
- **重命名**：只改变名称，不改变绑定和配置。
- **切换**：只改变当前用户 + 当前语言的绑定，不修改卡片和 ReviewLog。
- **删除**：Default 禁止删除；删除普通 Preset 时，把该用户所有受影响语言在同一事务内重新绑定到 Default，再删除 Preset，禁止留下孤儿绑定。

### 5.4 生效与重排

- 保存 Preset 后只影响后续设置读取和后续评分。
- 不自动重排已存在的 `fsrs_due_at`。
- 需要改变既有卡片到期日时，仍必须进入现有“预览 → 风险确认 → 正式重排”流程。
- 切换、复制、重命名、删除均不写 ReviewLog，不改变 lifecycle，不创建或删除 WordSense / ReviewCard。

## 6. Preset V1 配置范围

### 6.1 V1 核心字段

只收纳已经存在并有稳定测试契约的设置：

1. `fsrs.desired_retention`
2. `fsrs.parameters`
3. `fsrs.parameters_source`
4. `fsrs.parameters_optimized_at`
5. `daily_limits.new_cards_enabled`
6. `daily_limits.new_cards_per_day`
7. `daily_limits.reviews_enabled`
8. `daily_limits.maximum_reviews_per_day`
9. `daily_limits.new_cards_ignore_review_limit`
10. `queue_order.interday_learning_review_order`
11. `queue_order.new_review_order`
12. `queue_order.review_sort_order`
13. `queue_order.new_sort_order`

配置必须带 `schema_version = 1`，通过单一 Value Object / validator 归一化。

### 6.2 暂不进入 V1 的字段

- today-only 临时覆盖；
- Custom Study 条件和 card limit；
- lifecycle / bury / suspend / archive；
- Card Marker；
- Saved Search；
- 任意 deck/subdeck 结构；
- 自动重排开关；
- UI 主题、字体、词典、API、Jellyfin、Anki 导出设置；
- `fsrs_parameters_previous` 等一次性操作快照。

### 6.3 Leech 配置修正

Anki 的 Leech threshold/action 属于 Deck Options，但 LinguaCafe 当前 `SenseReviewLeechPolicy` 使用更丰富的 stable / struggling / leech 分类，并将暂停动作与 lifecycle 明确分离。当前代码没有稳定的 Leech 设置接口。

因此：

- Leech 阈值和处理方式不进入 Preset V1A/V1B 核心 schema；
- 保持现有 Policy 行为不变；
- 以后以 `Preset V1.1 Leech Configuration Product Gate` 单独设计；
- 禁止为了“对齐 Anki”直接把常量搬进 JSON 或在前端复制分类规则。

## 7. 目标架构

### 7.1 持久化对象

建议使用两个 additive-only 表，最终字段名由实现时按现有 Laravel 规范确定：

1. `review_setting_presets`
   - `id`
   - `user_id`
   - `name`
   - `config_schema_version`
   - `config` JSON/TEXT
   - `is_default`
   - timestamps
   - 同一用户名称唯一
   - 同一用户只能一个 Default

2. `review_setting_preset_bindings`
   - `id`
   - `user_id`
   - `language_id`
   - `preset_id`
   - timestamps
   - `user_id + language_id` 唯一
   - binding 的 user 必须和 Preset owner 一致

不得在旧 `settings` 表中继续拼接语言前缀名称，也不得把完整 Preset 塞进 `users` 表。

### 7.2 领域边界

- `ReviewSettingsPresetConfig`：V1 schema、默认值、验证和归一化；无 DB、Auth、Request。
- `ReviewSettingsPresetService`：新增、复制、重命名、删除、读取；V1A 只实现 Default 建立和读取所需部分。
- `ReviewSettingsPresetBindingService`：用户 + 语言绑定和所有权校验。
- `ReviewSettingsResolver`：返回当前用户 + 当前语言唯一有效配置；调度和设置领域的单一读取入口。
- `LegacyReviewSettingsSnapshotService`：只负责从旧全局设置生成首次 Default 配置；不得长期成为双写层。
- `SettingsService`：继续保持兼容门面，不重新膨胀。

### 7.3 单一数据流

V1A 生效后：

`authenticated user + selected language → binding → preset → ReviewSettingsResolver → existing settings services / FSRS consumers`

旧全局设置只用于首次兼容快照和安全 fallback。Default Preset 建立后，禁止在新 Preset 和旧全局记录之间长期双写。

## 8. 分阶段实施

### Preset V1A — Default Preset Foundation and Transparent Binding

**Completed / Production Closed。实现与验收见 ADR-0024。**

交付：

- additive migration、模型和约束；
- V1 config Value Object / validator；
- Default Preset 幂等建立；
- 当前用户 + 当前语言唯一绑定；
- 从旧全局设置生成首次兼容快照；
- `ReviewSettingsResolver` 单一有效配置入口；
- 现有目标保持率、FSRS 参数、每日上限和队列顺序读取接入 resolver；
- 现有 endpoint 和 payload 保持兼容；
- 设置页只增加只读的“当前 Preset：Default”识别，不增加管理菜单；
- 用户隔离、语言隔离、并发幂等、legacy fallback、无自动重排、无 ReviewLog 写入测试；
- 双 viewport MCP Chrome 验收。

停止条件：完成 V1A 后停止，不进入新增/复制/重命名/删除/切换。

### Preset V1B — Management Operations and UI

**Completed / Production Closed。实现与验收见 ADR-0025 和 `review-settings-preset-v1b-execution-plan.md`。**

交付：

- 列表、创建、复制、重命名、删除和切换 API；
- 管理弹窗或独立设置区；
- Default 保护、名称冲突、删除重绑定和多语言共享；
- Default 不可删除或重命名；普通 Preset 名称在当前用户内唯一；
- Add 从系统默认配置创建，Clone 复制当前 Preset；
- 切换只改变当前用户 + 当前语言 binding，不自动复制配置、不重排卡片；
- 删除普通 Preset 时在事务中把全部绑定语言重绑到该用户 Default，再删除；
- 设置页现有五个区域继续作为当前 Preset 的编辑器；
- 管理动作必须显示“影响哪些语言”，共享 Preset 修改必须明确提醒所有绑定语言会同步变化。

### Preset V1C — Multi-language Sharing and Consumer Convergence

**Completed / Production Closed。实现与验收见 ADR-0026。**

交付：

- 多语言绑定真实流程；
- 所有 FSRS、每日上限、队列和工作量模拟消费者复核；
- 删除残留的业务层直接全局 Setting 读取；
- `fsrs_parameters_previous` 不再写入、不再删除，也不再出现在成功响应的保存/删除 key 中；旧数据库行保持原样，仅视为无效历史残留；
- 旧全局记录只保留明确兼容期，不再是运行时主来源。

### Preset V1D — Settings UX and Production Closure

**Completed / Accepted / Production Closed。Settings UX-1 见 ADR-0027；跨用户/跨语言最终生产关闭矩阵已于 2026-07-15 完成。**

交付：

- **Settings UX-1 — Advanced Tools Diagnostic Empty-State and Action Safety：Completed / Accepted**；
- 全量自动回归；
- 两个用户、至少两种语言的 Chrome 真实验收；
- 新增、复制、修改共享、切换、删除重绑定、刷新持久化；
- Network、Console、数据库 delta 和无重排证据；
- 网页端总流程设计师最终 Accept。

生产关闭事实：

- 主账号 English 与 French 均完成 Default → shared preset → clone/rename → delete/rebind → Default 的真实页面流程。
- 共享 Preset 在 French 读取到 English 已保存的每日新卡上限 `21`，证明同一配置对象被多语言共享；删除普通 Preset 后所有受影响语言安全重绑到 Default。
- 创建第二个本地管理员测试账号，通过真实退出、登录和设置页访问确认只能看到自己的 Default Preset，不能看到主账号的临时 Preset。
- 新增、复制、重命名、切换、共享修改、刷新持久化和删除重绑定全部由 Chrome DevTools 在真实页面执行。
- 验收前后主账号 `ReviewLog=166`、`ReviewCard=95`，卡片到期字段 checksum 均为 `8efba9502402eab665070925ee0c6359bbb26f7164c3962e0262fb1defef76fb`；Preset 管理未写 ReviewLog、未改卡片数量、未自动重排。
- 临时普通 Preset 已全部删除，主账号最终只保留 Default；English/French 均绑定 Default。
- 设置相关分组 Feature 回归 101 tests / 513 assertions、Unit 652 tests / 1518 assertions、Node guards、前端构建和双 viewport 页面验收通过。
- 本阶段结束后停止，未进入 Browser / ReviewCardManage。

#### Settings UX-1 修复目标

截图反馈表明，高级工具在无数据或数据不足时会形成“错误警告 + 大量 0 指标 + 仍然突出的无效操作”的噪声。该项放在 V1D，而不插入 V1B/V1C：管理 UI 和消费者数据流先稳定，再一次完成最终信息层级，避免同一设置页连续返工。

目标：

1. 无可用诊断时只显示一个清楚的空状态，不同时出现“没有诊断”与一整块全 0 诊断表。
2. 数据不足时优先显示一条进度信息：`有效记录 N / 300`、还差多少条、当前可训练卡数量；其余细项进入“查看诊断详情”折叠区。
3. “预览优化结果”在 `can_optimize=false` 时禁用或隐藏，并解释怎样才能启用；不得让用户点击一个注定失败的动作。
4. 当前已经使用默认参数时，“恢复默认参数”禁用或显示“当前已是默认参数”，避免制造危险动作错觉。
5. 全诊断网格只在存在有意义数据，或用户主动展开详情时显示；0 值必须有明确语义，不能只是占位。
6. 一个状态只保留一个主色和一个主结论；warning、error、info 不得互相冲突。
7. 900×900 无横向溢出，键盘焦点和 live-region 状态可读。
8. 只改展示和动作可用性，不改变优化阈值、FSRS 参数、ReviewLog、lifecycle、重排和 Preset 数据契约。

成功标准：

- 0 条记录、1–299 条有效记录、300+ 条有效记录、默认参数、优化参数、诊断加载失败六种状态都有确定性纯状态测试；当前真实 insufficient/default 状态完成双 viewport Chrome 验收。禁止为了截图制造 300 条正式 ReviewLog。
- 空状态不出现超过 3 个零值统计卡。
- 不可执行按钮无法发出写请求。
- 页面仍通过双 viewport、Console、Network 和无数据库副作用验收。

## 9. V1A 验收矩阵

### 数据与权限

- 用户 A 无法读取或绑定用户 B 的 Preset。
- English 和 Japanese 可绑定不同 Preset，读取互不污染。
- 相同用户 + 语言重复初始化只产生一个 binding 和一个 Default。
- 并发初始化不产生重复 Default 或重复 binding。
- Preset owner 与 binding user 不一致时拒绝。

### 兼容

- 未建立 Preset 时，从当前旧全局设置生成等价 Default。
- 已建立 Preset 后，修改当前配置不再依赖旧全局记录。
- 现有设置 GET/POST endpoint 和 payload 不变。
- 旧调用方仍可通过 `SettingsService` 兼容门面工作。

### 学习安全

- 初始化、读取和保存 Preset 不创建/删除 WordSense、ReviewCard、ReviewLog。
- 不改变 ReviewCard lifecycle。
- 不自动修改任何 `fsrs_due_at`。
- 不运行正式重排、恢复默认参数或撤销。
- 不改变评分 key、score、label、hotkey。

### 页面

- 1920×1080 与 900×900 显示“当前 Preset：Default”。
- 原五个设置区正常加载和保存。
- 刷新后设置保持。
- 无横向溢出，无新增 Console error/warning。
- Network 只访问 LinguaCafe 本地 endpoint。

## 10. 失败与回滚

- migration 或初始化失败必须事务回滚，不留下半个 Preset 或孤儿 binding。
- resolver 无法读取合法 Preset 时 fail closed，并返回可诊断错误；不得静默使用其他用户或其他语言配置。
- 只有“该用户 + 语言尚未建立 Preset”时允许使用 legacy snapshot。
- V1A 回滚时，旧全局设置仍可支持原版本；禁止在迁移中删除旧 Setting 记录。

## 11. 明确禁止

- 不实现 deck/subdeck。
- 不实现 Preset 管理动作和管理 UI（属于 V1B）。
- 不配置 Leech 阈值。
- 不接触 today-only、Custom Study、Card Marker、Browser 重构、Reviewer 重构或 Reader 重构。
- 不改 FSRS 算法。
- 不自动重排旧卡。
- 不删除旧 `settings` 记录。
- 不做长期双写。
- 不读取或修改 `.env`、`AGENTS.md`、`.omo/`、`.playwright-cli/`、`nul`。
- 不清库，不执行 `migrate:fresh`、`db:wipe`、DROP、TRUNCATE 或 `--force`。
- 不运行 notification script，不 DCP，不自动进入 V1B。

## 12. V1A 实现交接（2026-07-15）

状态更新为 **Accepted / Production Closed**。

- 两张 additive-only 表和复合所有权外键已经实现；旧 `settings` 表及其行未删除。
- `ReviewSettingsPresetConfig`、Default/Binding 服务、legacy snapshot 和 `ReviewSettingsResolver` 已成为 V1A 边界。
- Preset JSON 的所有更新通过事务、`lockForUpdate()`、局部合并和全 schema 归一化完成。
- desired retention、FSRS 参数、每日上限、队列顺序、工作量模拟、重排预览/确认和 Study Overview 均使用显式用户 + 语言上下文。
- 现有 endpoint path、请求字段和响应字段保持兼容；generic global endpoint 允许 preset-owned 与真正 global key 混合请求。
- 设置页只增加“当前 Preset：Default / 当前语言”只读识别，没有 V1B 管理动作。
- ADR-0024 记录 V1A，ADR-0025 记录 V1B，ADR-0026 记录 V1C，ADR-0027 记录 V1D 的 Settings UX-1。V1A–V1D 均已生产关闭；V1D 的双用户、English/French、CRUD、共享修改、刷新持久化、删除重绑定和无自动重排矩阵已由网页端使用 DevSpace5 与 Chrome DevTools 完成。
