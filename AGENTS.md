# AGENTS.md — LinguaCafe 长期协作硬规则

本文件只放每次任务都值得加载、长期稳定、违反后代价高的规则。业务现状、阶段计划和历史记录不得堆到这里。

## 1. 权威顺序与最小上下文

发生冲突时，按以下顺序处理：

1. 用户当前明确要求与上级运行环境指令。
2. 本文件的安全、范围和停止规则。
3. 已接受 ADR 与相关模块契约。
4. `docs/DOCUMENTATION_INDEX.md` 指向的当前 handoff、master plan 和 roadmap。
5. 历史文档；只作证据，不作为当前指令。

每个任务先读本文件，再读文档索引，只加载当前模块的契约、实现、测试和一个既有范例。不得默认读取全部计划、全部 ADR 或全部字幕。不一致先按范围、阶段、supersession 和本节权威层级消解：低权威旧状态不得阻断高权威当前决定，也不得因此自动改写范围外或带有用户改动的文件。只有同一最高有效权威内仍存在会改变行为、数据、安全、兼容或验收的真实冲突时才停止并报告。依据见 ADR-0034。

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

普通任务的文件范围扩大前停止并取得用户确认。明确持续目标中，若新增文件直接服务已冻结切片责任，且架构审查补充了具体文件、seam、数据流、兼容风险和验证，则可更新当前切片 allowlist，无需重复确认；不得借此把整个里程碑变成开放范围。超出切片或权威 roadmap 仍须停止。工作区已有改动均视为用户资产：不得覆盖、回退、清理或纳入提交；但脏工作区本身不构成阻断。修改重叠文件前先读当前文件和 diff，以最小补丁保留既有语义与无关改动。若同一行或同一契约的用户改动与当前切片实质互斥且无法同时保留，必须保持该用户改动原样并只暂停受影响文件/切片；仍有独立切片时先继续，只有没有可运行切片或最终审计需要该冲突文件时才请求具体决定。不得把未提交用户资产放进权威层级自动消解。依据见 ADR-0037。

### 本地 Agent、模型白名单与弹性并行规则

2026-08-17 当前规则以 `D:\Document\lingl\parallel-tasks\LinguaCafe_CURRENT_LOCAL_AGENT_MODEL_AND_PARALLEL_AUTHORITY_2026-08-17.md` 为准。本地 Agent 采用工具级隔离：OpenCode 是免费层，只允许 `opencode/deepseek-v4-flash-free` → `opencode/mimo-v2.5-free`；Reasonix 是付费强化层，只允许 `opencode-go/mimo-v2.5`。正常任务必须优先使用 OpenCode free；只有两个 OpenCode free 都不能完成且确实需要更强独立 Agent 时才允许启动 Reasonix。Reasonix 不得再尝试任何 free 模型；OpenCode 不得使用 paid 模型。禁止其它模型，尤其禁止 paid DeepSeek Flash/Pro。任何本地 Agent 结论都不能取代当前 GPT 窗口 owner 的独立核验。

Codex 新任务通常不得由网页端主动创建；只有用户当前明确要求“使用 Codex 完成某项工作”才获得新任务授权。已经由用户或既有授权启动的 Codex 可以被网页端持续跟踪、steer、复核和收尾。四窗口 fixed DIRECT 不再默认形成 01→02→03→04 线性流水线；必须按真实依赖图安排。无依赖切片立即并行；一个窗口只有后半段依赖 predecessor 时，先完成全部 independent prework，再等待 canonical gate，最后执行 post-gate work。真正同文件 writer、同数据写链、shared testing DB 写入和 Git integration owner 仍按事实串行。

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

所有高风险任务都必须先完成架构审查。普通任务在用户明确确认后实施；明确持续目标按 ADR-0031/ADR-0037 使用“目标模式 roadmap 执行授权”：当目标指向权威 roadmap 或有序里程碑时，目标授权依赖顺序内的全部切片。架构审查和计划/ADR/契约必须先冻结当前切片的范围、seam、数据和兼容语义；若它们只忠实展开已接受 roadmap 与 ADR-0034 决策梯，可标记为 `Accepted under current goal authorization` 并直接实施，不再等待逐份人工 Accept。完成相称验证和逐项完成审计后可自动进入下一命名里程碑；但当前任务锁或执行计划若明确写有 `auto_advance: false`、`supervisor_unlock_required: true` 或等价停止条件，则该局部锁优先，必须停止等待验收或新授权。

在上述冻结条件满足后，目标授权可覆盖里程碑明确需要的 additive migration 文件、testing 专用数据库中的 schema 应用、新表、新 Controller/Service/model/middleware/store module、已审查的多个 seam，以及已冻结的 API/payload、数据模型、正式评分、ReviewLog、WordSense、ReviewCard 或 lifecycle 变化，不因实现形式重复请求确认。

计划未写死的决定按 ADR-0034 的目标模式决策梯处理：用户当前决定 → 适用的已接受 ADR/契约/roadmap 边界 → 经核实的稳定实现先例 → 适用的 Anki 官方行为及 LinguaCafe 映射 → 最小、可逆、兼容且可测试的推荐方案。用户已委托当前 M0–M18 目标内第 4、5 步的可逆选择；执行 Agent 必须记录并验证，不得把现有代码、第三方资料或自己的偏好冒充更高权威。

目标授权仍不得覆盖：开发/预发布/生产或真实用户数据上的 migration 执行、回填、恢复、删除和破坏性操作；任何 `migrate:fresh`、`migrate:refresh`、`migrate:reset`、`db:wipe`、drop 或 truncate；真实 AI provider、密钥、外发、付费、模型或成本上限；部署、签名、商店提交或其他不可逆第三方动作；未被当前目标明确委托且会实质改变产品边界、评分/生命周期/调度、数据丢失风险或关键无障碍承诺的产品选择；同一最高有效权威内的真实冲突；目标外扩张。只有这些具体决定获得用户批准后才并入当前目标。权威、产品边界、数据权威、安全模型、外发或成本假设发生实质变化时必须重新确认。依据见 ADR-0031、ADR-0034。

新发现的实现细节若仍在已批准目标和当前切片内，先补齐架构审查、契约和验证，无需重复请求用户；低权威旧状态按 §1 自动消解。遇到保留停止线时只暂停受影响切片，并继续依赖图中其他已授权、语义独立的切片；只有所有剩余切片都依赖该事项，或最终完成审计必须由用户/外部环境清零时才请求具体确认。超出目标或同一最高有效权威仍冲突时同样按实际依赖范围暂停。依据见 ADR-0037。

若某项证据只因工具、基础设施或当前环境无法取得，可按 ADR-0034/ADR-0037/ADR-0052 使用 `Acceptance Deferred — Not Complete`。该状态绝不等于完成；Architecture Gate 必须证明后续实现仅依赖已执行验证的契约、不触及缺失行为。缺失证据按 Android 设备、iOS 签名设备、商店提交等能力簇跟踪，不设机械的“每条路径最多一个 deferred”预算：同一路径可继续实现可逆、独立可测试的下游，并把每个未观察行为列入相应能力簇；不得把 ancestor deferred 当作完成证据，也不得让未验证行为成为下游实现前提。只有实现本身必须消费缺失行为、触及保留停止线或同一最高权威仍冲突时才暂停受影响切片。最终目标完成审计必须清零全部 deferred 能力簇和其中每个命名检查。

## 6. 稳定不变量

以下能力受保护，触及时必须证明没有回归：

- `/chapters` 英文导入、tokenizer 与英文 fallback。
- `TextReader.vue` / `TextBlockGroup.vue` 阅读流程。
- 点词侧栏与 ECDICT 查询。
- Word FSRS、Sense FSRS 与各自队列隔离。
- GPT sense-mapping prepare / validate / dry-run / import。
- 注册、登录、本地 Pusher 降级。
- testing 数据库与开发数据库隔离。

正式评分的唯一写入入口为 Web `ReviewController::rateReviewCard` / `SenseReviewController::rate` → `ReviewCardService::recordReview`，以及 Mobile `MobileSenseReviewController::store` → `MobileIdempotencyService::execute` → `ReviewCardService::recordReviewWithLog`；两者最终都只能进入 `FsrsSchedulingService::schedule`。管理侧 reset 是 `ReviewCardService::resetCard`，不得伪装成评分入口。依据见 ADR-0003、ADR-0008、ADR-0032 和相关回归测试。AI、词典、预览、source context、example pool 和 known-sense lookup 不得创建 WordSense、ReviewCard、ReviewLog 或修改 FSRS。

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
- “真实浏览器验收”按结果定义，不绑定某一个工具。允许依次使用当前可用的 Chrome DevTools/MCP、受控 Playwright、Computer Use/系统浏览器或项目批准的其他浏览器自动化通道。必须真实渲染目标页面、使用 DOM/用户事件完成操作，并保留登录、Console、Network 与可观察数据变化证据。
- 浏览器验收优先使用官方 OpenAI Browser/Chrome 插件；其普通失败后才按 ADR-0033 切换下一平台允许的真实浏览器通道，不必等待用户再次批准。单一工具失败不构成 `Incomplete`。所有允许通道均失败或操作必须由人完成时，在持续目标中先记录 deferred 并继续独立切片；只有没有任何可运行切片或最终完成审计需要该动作时才请求用户。
- 若 system、developer 或工具输出明确禁止同一结果的 workaround、间接执行、原始命令或 alternate surface，该安全拒绝高于本规则，不得换通道规避；只把普通连接/会话/进程失败按 ADR-0033 自动降级。含义不清时按更严格类别处理。
- 为本地验收，执行 Agent 可以启动或重启任务需要的 Laravel、前端、队列、tokenizer 与浏览器进程。任何会产生写入的页面操作前，必须取得绑定同一监听 host/port 和进程的 server-bound 证据，确认该实际 HTTP 进程为 `APP_ENV=testing`、连接专用 testing 数据库并能读取 testing-only sentinel；单独运行的 PHPUnit/testing DB 健康检查只是前置条件，不能证明当前服务器。证据可由 testing-only 本地健康端点，或绑定 PID/端口的启动证明加同源 sentinel 请求提供，不得暴露凭据。默认 `php artisan serve` 或无法证明数据库指向的服务器只允许只读诊断。当前 fixed DIRECT 明确提供的固定本地测试身份，在 server-bound evidence 已证明实际 HTTP 进程连接专用 testing 数据库后，优先于泛化的临时最小权限身份规则。若该固定身份在同一 testing 数据库中不存在，且当前 DIRECT 明确允许创建同名本地测试身份，则按应用正常首次设置流程创建**同名** testing 身份；如果应用强制第一个本地用户成为管理员，这个 testing-only 首管理员身份允许用于当前 DIRECT 的真实页面验收，但不代表生产权限模型，也不得扩大到任务外管理员操作。禁止改用随机替代邮箱绕过固定身份。只有当前 DIRECT 未提供固定身份，才创建最小权限、任务专属 testing 身份。随机密码只在内存中直接填入正常 UI，不进入仓库、完整 shell 参数、输出、截图、Network 报告或浏览器密码库。若本地 Android emulator 已真实渲染 UI、官方 Computer Use 无法激活其键盘且 sentinel 已绑定，可按 ADR-0053 在单一不记录日志的临时进程中生成密码，并通过 scoped ADB 逐字符 UI key event 输入；命令源码和进程参数不得出现完整密码，登录后立即清空，且不得用于现有身份、远端设备或非 testing 数据。可以创建唯一 marker 的任务数据，并执行当前切片已授权且可由 testing harness 或产品入口收口的页面动作。必须记录前后数据、token/session 和间接写入并复核清理；不得在普通开发数据库创建临时账号后直接删号，不得对普通开发数据库执行评分、Tag、Marker、导入、fixture 或其他验收写入，不得借此清库、绕过认证、修改其他用户数据或扩大到任务外写入。依据见 ADR-0034、ADR-0037、ADR-0053。
- API、数据库查询、日志与截图可以补强真实页面证据，但不能替代页面操作。禁止用 fetch/axios 直接调用写接口后声称完成了按钮验收，也禁止用数据库手工改值伪造页面结果。

同时触及多个受保护模块时合并运行对应检查。失败必须如实归因；不得删测试、弱化断言、隐藏失败或扩大范围来换取通过。

## 9. Git 与交付

- 不读取、修改或提交 `.env`、密钥、既有账号凭据或认证材料。ADR-0034 允许的 testing 临时身份只能使用内存随机密码和正常认证入口，不得持久化凭据。
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
