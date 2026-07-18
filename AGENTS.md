# AGENTS.md — LinguaCafe 长期协作硬规则

本文件只放每次任务都值得加载、长期稳定、违反后代价高的规则。业务现状、阶段计划和历史记录不得堆到这里。

## 1. 权威顺序与最小上下文

发生冲突时，按以下顺序处理：

1. 用户当前明确要求与上级运行环境指令。
2. 本文件的安全、范围和停止规则。
3. 已接受 ADR 与相关模块契约。
4. `docs/DOCUMENTATION_INDEX.md` 指向的当前 handoff、master plan 和 roadmap。
5. 历史文档；只作证据，不作为当前指令。

每个任务先读本文件，再读文档索引，只加载当前模块的契约、实现、测试和一个既有范例。不得默认读取全部计划、全部 ADR 或全部字幕。发现两个权威来源冲突时停止并报告，不得自行选一个。

## 2. 产品范围

### 只做

- 英文学习、英文材料导入、英文阅读、英文词典。
- Word / Sense FSRS。
- 英文 GPT sense-mapping 与已批准的 AI 学习辅助流程。

### 不做

- DeepL 集成。
- 非英文材料处理。
- 全站深度汉化。
- 自动控制 ChatGPT 网页端、自动发送或自动下载。
- phrase FSRS。
- 用户未要求的功能、抽象、依赖、迁移或重构。

GPT sense-mapping 自动化边界固定为：导出 GPT 包 → 人工取得 GPT 输出 → 上传 JSON → 校验 → 预览 → 正式导入。

## 3. 上游与实现原则

- 上游：<https://github.com/simjanos-dev/LinguaCafe.git>。
- 旧功能异常时先对照 upstream；能恢复现有逻辑就不重写。
- 先追真实调用链和全部调用方，再改共享根因。
- 优先复用项目现有实现、标准库和已安装依赖；不为单一用例新增层、接口、DTO、Repository 或配置。
- 最小正确改动优先；不混入顺手清理，不改无关文件。
- 保持 Laravel Controller → Service → Model、Vue 2 + Vuex + Vuetify 的既有风格。

## 4. 任务边界

实施前必须写清：

- 当前目标与明确不做事项。
- 受影响模块和唯一责任。
- 允许文件、禁止文件。
- 数据流、写入入口和兼容边界。
- 最小验证命令与成功/失败标准。

文件范围扩大前停止并取得用户确认。工作区已有改动均视为用户资产：不得覆盖、回退、清理或纳入提交。

## 5. Architecture Gate

风险分级和审批语义以 `docs/adr/ADR-0001-architecture-gate-workflow.md` 为准：

- 中风险：单模块内且不改变既有语义的 props/events、前端 API 调用或工具函数变化。至少使用 `context-engineering`，涉及契约时加 `api-and-interface-design`，实施后使用 `code-review-and-quality`。
- 高风险：后端 endpoint、请求/响应 payload 或公开接口语义变化，跨模块变更、大重构、Vue 组件拆分，以及下列强制高风险区域。

强制高风险区域：

- `TextBlockGroup.vue`、`VocabularySideBox.vue`、`WordSensesList.vue` 或 reader 状态流。
- Vuex/store 逻辑。
- WordSense、ReviewCard、ReviewLog、FSRS、review scheduling。
- AI lookup / AI 写入、sense-only review。
- import/export、source context、原章节定位。
- 数据库迁移、新表、新 Controller、新 Service 或新 store module。

审查只覆盖本任务，必须给出模块责任、seam、耦合、风险、允许/禁止文件、验证和 ADR 需求。不得借闸门发起全仓重构。

所有高风险任务都必须先完成架构审查，再由用户明确确认后实施。以下情况还必须立即停止并请求新的确认：

- 触及多个架构 seam。
- 改变 API/payload 语义、数据模型、FSRS、WordSense 绑定、ReviewLog 或 AI 写入边界。
- 需要 migration、新表、新 Controller、新 Service 或新 store module。
- 审查要求新 ADR。
- 计划文件超出已批准范围。

## 6. 稳定不变量

以下能力受保护，触及时必须证明没有回归：

- `/chapters` 英文导入、tokenizer 与英文 fallback。
- `TextReader.vue` / `TextBlockGroup.vue` 阅读流程。
- 点词侧栏与 ECDICT 查询。
- Word FSRS、Sense FSRS 与各自队列隔离。
- GPT sense-mapping prepare / validate / dry-run / import。
- 注册、登录、本地 Pusher 降级。
- testing 数据库与开发数据库隔离。

正式评分的唯一写入入口为 `ReviewController::rateReviewCard` / `SenseReviewController::rate` → `ReviewCardService::recordReview` → `FsrsSchedulingService::schedule`；管理侧 reset 是 `ReviewCardService::resetCard`，不得伪装成评分入口。依据见 ADR-0003、ADR-0008 和相关回归测试。AI、词典、预览、source context、example pool 和 known-sense lookup 不得创建 WordSense、ReviewCard、ReviewLog 或修改 FSRS。

## 7. Spec、计划与 Harness

一条决定只有同时满足以下条件，才允许成为长期硬规则：

- 已由用户明确冻结；核心产品章程/排除项可在实现前冻结，其他行为规则还必须有已验收实现或可执行证据。
- 后续任务会反复遇到。
- 重新判断或违反它的代价高。
- 能明确写出触发条件、要求、禁止项和验证方式。

否则按类型放置：

- 未冻结需求 / 下一步做法 → task plan 或 handoff。
- 稳定架构或数据决定 → ADR / 模块契约。
- 高风险不变量 → test / smoke / harness。
- 一次性 bug、临时修复、执行报告 → history / issue，不进入硬规则。

文档只能降低出错概率，不能作为验收证据。核心流程、重复踩坑、难排查链路和数据安全边界必须逐步变成可执行检查。禁止为了覆盖率或形式完整堆测试；测试应集中在承重不变量和公开接口。

## 8. 验证矩阵

验证必须与改动风险匹配，且发生在实现输出之外：

- 纯文档：`git diff --check`、链接/引用检查、冲突规则搜索、变更范围检查。
- PHP 业务逻辑：相关最小 PHPUnit；涉及 Feature tests 时先按当前 testing DB playbook 做健康检查。
- Word FSRS：`php artisan test --filter=ReviewFsrsTest`。
- FSRS 调度：`php artisan test --filter=FsrsSchedulingServiceTest`。
- WordSense / Sense：`php artisan test --filter=WordSense`。
- 前端或前后端契约：相关测试加 `npm run development`。
- 可见 UI / reader / review / import-export：自动测试后做真实浏览器验收；API、源码阅读或截图推测不能冒充页面操作。

同时触及多个受保护模块时合并运行对应检查。失败必须如实归因；不得删测试、弱化断言、隐藏失败或扩大范围来换取通过。

## 9. Git 与交付

- 不读取、修改或提交 `.env`、密钥、账号或认证材料。
- 不运行 `migrate:fresh`、`migrate:refresh`、`migrate:reset`、`db:wipe`、drop、truncate 或清库；不得用 SQLite 替代 testing MySQL。
- 不绕过权限、认证、user/language 隔离或既有唯一写入入口。
- 不处理 `.omo/`、无关 `.playwright-cli/` 残渣或其他任务外文件。
- 提交前检查 branch、remote、worktree 和精确文件范围。
- 只按精确路径暂存本任务文件；不得提交 `.env`、密钥、生成物、测试残渣或无关改动。
- commit message 使用英文 `fix:` / `feat:` / `docs:` 前缀。
- 不 force push，不改 upstream 历史。
- 推送前确认本地分支未落后远端；使用现有本地 Git / `gh` 凭据。
- 最终报告只陈述可复验事实：改了什么、验证了什么、仍有什么阻塞。

## 10. 规则收敛

修改 `AGENTS.md` 必须获得用户明确授权；已接受 ADR 的语义变化还必须新增或取代 ADR。修改硬规则时必须做新鲜上下文的对抗审查。最多三轮；每轮只处理会改变行为、安全、范围、权威或验收的实质问题。若下一轮只剩措辞、排序或同义替换，视为收敛并停止，禁止无休止润色。
