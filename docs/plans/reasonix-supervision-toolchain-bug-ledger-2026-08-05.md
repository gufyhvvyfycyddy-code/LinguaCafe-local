# Reasonix 监督与桌面自动化工具链 Bug 台账

日期：2026-08-05
范围：Reasonix Desktop 1.19.7、本地监督工具 `<reasonix-supervisor-root>`、DevSpace、Playwright、Microsoft WinApp UI Automation、Reasonix 会话日志与交互式 Windows 桌面桥
状态：**Open / Workarounds Active / Not Complete**
上级台账：`docs/plans/local-experience-bug-optimization-ledger-2026-07-23.md`

## 1. 当前结论

当前监督链路已经具备部分可用能力：

- Playwright 可以打开本地监督台并读取当前 Reasonix 会话状态；
- Reasonix 的 `.events.jsonl` / `.jsonl` 文件可以作为消息是否真正进入当前轮次的权威证据；
- Microsoft WinApp UI Automation 可以在部分状态下定位 `composer__input`、加入引导队列按钮和直接引导按钮；
- 两条历史监督消息已经以 `role=user`、`Mid-turn steer queued by the user` 的形式进入当前 Reasonix 轮次。

但这不等于监督链路已经完成。后续已实现单个 Session 1 原子任务、阶段回执、`role=user` 精确验证、屏幕外主窗口恢复和失败草稿清理；真实监督消息 `SUPERVISOR-BRIDGE-C18708708C18` 已在当前会话 sequence 1684 得到确认。与此同时，本轮也发生过更严重的事故：一条预发送失败后遗留的旧草稿先于纠正消息进入会话，并触发 3 个 commit 与 push；Reasonix ask 卡片还把默认选项冒充为用户裁决。因此当前链路仍属于 **Workarounds Active / Not Complete**，尚不能保证高影响动作发生前完成可信监督。

## 2. Workaround 治理规则

### [治理][当前规则] RX-G-001 临时绕开不等于问题解决

1. 每个 workaround 必须保留一个未关闭的 Bug 编号。
2. “通过另一条路径完成了本次操作”只能标记为 `Workaround Active`，不能标记为 `Fixed`、`Accepted` 或 `Closed`。
3. 每个 workaround 必须写明：原问题、临时路径、为什么仍不完整、根治方向和退出验收。
4. 超时、连接重置或窗口重建后，不得盲目重试写操作；必须先按唯一 marker、会话 revision、队列项或回执查询是否已经部分成功。
5. 只有根因被消除，并且正常、失败、超时后重试、会话重建和重复调用场景均有可执行验证，才能关闭问题。
6. 新发现的监督工具链故障必须先补入本台账，再决定是否继续绕开。
7. 本台账中的问题不得因当前 LinguaCafe 产品切片完成而自动关闭；它们属于独立工具链维护负债。

---

## 3. Bug 明细

### [Bug][工具链][P0] RX-001 发送超时后无法确定消息是否真正注入

#### 现象与证据

监督台调用 `send` 时可能返回：

`Interactive winapp task timed out after 60000 ms.`

调用方无法仅凭该错误判断：

- 消息完全没有输入；
- 已输入但未排队；
- 已排队但未直接引导；
- 已直接引导但回执丢失；
- Reasonix 已重建窗口或轮转会话。

2026-08-05 14:53 左右的新监督消息发生超时后，按完整句子搜索当前会话文件结果为 0，说明该次不能宣称引导成功。

#### 当前 workaround

- 每次写操作使用唯一 marker；
- 超时后先搜索全部相关会话文件；
- 未找到 marker 时不宣称成功；
- 找到 marker 时还要确认对应消息是 `role=user` 且属于当前轮次，而不是出现在 Reasonix 自己的分析文本中。

#### 为什么仍未解决

这是事后推断，不是事务性发送。调用方仍然不知道动作停在哪一步，也无法安全决定是否重试。

#### 根治方向

建立具有 request ID 的发送事务：

`accepted → composer_written → queued → steered → persisted → acknowledged`

每个阶段必须可查询，重复调用同一 request ID 必须幂等返回原结果。

#### 退出验收

- 人为制造 UIA 超时后能查询同一 request ID 的最终状态；
- 未执行时可安全重试一次；
- 已执行时重试不产生重复队列项；
- 只有日志确认后返回 success；
- 失败回执包含停留阶段和可恢复动作。

### [Bug][工具链][P0] RX-002 “加入引导队列”和“直接引导”不是原子操作

#### 现象与证据

Reasonix 运行中，第一次点击只把文本放入“待处理引导”架；还需要点击该条目旁的“将这条引导加入信息流”，才会调用 mid-turn steer。早期实现只完成第一步，用户看不到引导进入当前轮次。

多次超时和重试曾使界面出现“待处理引导 9”，说明中间状态可以累积和重复。

#### 当前 workaround

- 先用唯一 marker 写入消息；
- 点击加入引导队列；
- 再按 marker 对应文本行定位同一行的 `composer-guidance-item__guide`；
- 点击后从事件日志确认 marker。

#### 为什么仍未解决

两个 UI 动作之间可能发生窗口重建、会话轮转、超时或队列变化。即使 marker 行定位正确，整个流程仍不是原子事务。

#### 根治方向

优先使用 Reasonix 后端 `SteerForTab()` / `TrySteer()` 的正式控制接口，或由 Reasonix 提供单一“直接中途引导”API。UI 层不得承担事务所有权。

#### 退出验收

- 单一调用完成 mid-turn steer；
- 不出现可见待处理队列中间态，或该中间态具有同一 request ID；
- 重复调用不产生第二条消息；
- 运行中、空闲、等待提问三种状态均有明确语义。

### [Bug][工具链][P0] RX-003 Reasonix Desktop 本地会话没有正式外部控制面

#### 现象与证据

- Reasonix Gateway MCP 在线，但 `reasonix_sessions` 对 Desktop 本地任务返回空；
- Reasonix CLI `session list` 看不到当前 Desktop 本地会话；
- CLI `run --resume` 在 Desktop 持有会话锁时不能作为可靠排队入口；
- 当前只能组合 UI Automation 与本地会话文件完成读写。

#### 当前 workaround

- 读取 `%APPDATA%\reasonix\projects\...\sessions`；
- 使用 WinApp 操作当前桌面窗口；
- Playwright 只驱动本地监督台，不直接控制 Reasonix WebView。

#### 为什么仍未解决

UI 与文件系统是实现细节，不是稳定公共接口。Reasonix 更新后控件 class、会话文件格式、锁和恢复机制都可能改变。

#### 根治方向

Reasonix 提供受认证的本地 loopback 控制 API 或 CLI：

- 列出 Desktop 标签页和当前会话；
- 订阅输出；
- 查询 busy / waiting / idle；
- 发送普通消息；
- 直接 mid-turn steer；
- 查询 request ID 状态；
- 不依赖窗口前台状态。

#### 退出验收

监督工具完全不需要鼠标、键盘、窗口句柄或计划任务即可读写当前 Desktop 会话。

### [Bug][工具链][P1] RX-004 Reasonix 1.19.7 覆盖 WebView2 外部调试参数，Playwright 无法直接接入

#### 现象与证据

尝试通过以下方式开放 CDP 均未生效：

- WebView2 `AdditionalBrowserArguments` 注册表策略；
- `WEBVIEW2_ADDITIONAL_BROWSER_ARGUMENTS` 环境变量；
- 通过启动器和直接启动 `reasonix-desktop.exe` 继承环境变量。

实际 `msedgewebview2.exe` 子进程命令行只包含 Reasonix/Wails 自己传入的参数，没有 `--remote-debugging-port`。源码检查表明当前 `go-webview2` 初始化路径会使用应用自身 browser arguments，覆盖外部入口。

#### 当前 workaround

使用“Playwright 本地监督网页 + WinApp UIA + 会话日志”的混合架构，而不是直接连接 Reasonix WebView2。

#### 为什么仍未解决

Playwright 不能直接读取 Reasonix DOM、Console、Network 和运行时状态；仍需依赖较脆弱的桌面 UIA。

#### 根治方向

- Reasonix 官方增加显式、默认关闭的 `--remote-debugging-port=0` 开关；或
- 提供安全的 DevTools attach / local automation mode；或
- 提供等价正式控制 API，使 CDP 不再必要。

#### 退出验收

- 默认关闭；
- 显式开启时只绑定 loopback；
- Playwright 能定位 `wails.localhost` 页面；
- 关闭后无监听端口；
- 不需要修改或替换官方二进制。

### [Bug][环境][P1] RX-005 Windows Application Control 阻止本地补丁版辅助程序

#### 现象与证据

Go 测试二进制和本地重新编译的 Reasonix Desktop 无论位于临时目录还是 `<local-ai-tools-root>` 固定目录，均被 Windows Application Control 阻止运行。

#### 当前 workaround

- 不绕过系统安全策略；
- 不替换官方 Reasonix 二进制；
- 只使用已允许的 Node、PowerShell 和 Microsoft WinApp 工具。

#### 为什么仍未解决

无法通过本地最小补丁验证 CDP 或正式 IPC 改动，也无法发布一个本地原生桥接 helper。

#### 根治方向

- 上游提供签名构建；或
- 为监督 helper 建立可审计的签名和发布流程；或
- 由管理员按发布者/哈希建立最小 allow policy。

不得把关闭 WDAC、复制到特殊目录或使用未知加载器作为解决方案。

#### 退出验收

签名产物在现有安全策略下可运行；签名、来源和哈希可核查；无需降低系统策略。

### [Bug][工具链][P1] RX-006 DevSpace 后台会话与交互桌面隔离，计划任务桥不稳定

#### 现象与证据

DevSpace 命令通常运行在 Windows Session 0，而 Reasonix 窗口位于用户交互 Session 1。直接调用 WinApp 时看不到 Reasonix 窗口，只能使用 `InteractiveToken` 计划任务桥。

期间出现：

- `schtasks /TR` 261 字符限制；
- Git Bash / MSYS 路径自动转换；
- PowerShell/Bash 多层引号和变量提前展开；
- 缺少 BOM 导致脚本乱码或空字符；
- `$LASTEXITCODE:` 被解析成非法变量名；
- 任务等待器提前终止仍在运行的子脚本；
- 旧回执文件被误当作本轮结果；
- 临时任务创建、运行、清理之间存在竞态。

#### 当前 workaround

- 使用 UTF-16LE BOM 的任务 XML；
- 请求/结果文件传参，缩短 `/TR`；
- 唯一任务名和 run ID；
- 回执包含时间戳；
- 关键脚本放在固定目录。

#### 为什么仍未解决

仍然依赖 Windows 计划任务作为进程间 RPC，故障面过大，错误难以区分。

#### 根治方向

建立一个在交互用户会话中常驻、签名、loopback-only 的本地代理；或改用 Reasonix 正式 IPC/API。计划任务只保留为安装/启动手段，不用于每次 UI 操作。

#### 退出验收

连续 100 次只读 probe 和 50 次带 marker 的发送测试无任务残留、无旧回执、无编码/路径错误；会话锁屏、最小化和窗口重建有明确行为。

### [Bug][工具链][P1] RX-007 窗口句柄、最小化状态和 WebView 控件树会在任务中变化

#### 现象与证据

- Reasonix 最小化时主窗口可能只剩约 `237×39`，控件坐标变成屏幕外负值；
- 任务运行和恢复会重建主窗口，旧 HWND 随即失效；
- UIA 在列出窗口后读取控件树时可能撞上窗口切换；
- 某些时刻只能看到窗口外壳，稍后才出现 composer 和按钮。

#### 当前 workaround

- 每次操作前重新选择最大可见主窗口；
- 必要时调用“还原”；
- 遇到 stale HWND 自动刷新一次；
- 不保存长期坐标。

#### 为什么仍未解决

这是轮询式恢复，仍存在 TOCTOU：探测和执行之间窗口可再次变化。

#### 根治方向

- 使用 UIA window/control lifecycle 事件；或
- 通过正式 API 取消窗口依赖；
- 每个操作绑定进程 ID、窗口 generation 和控件 runtime ID。

#### 退出验收

窗口最小化、恢复、标签页切换、Reasonix recovery session 和 WebView 重渲染期间，写操作不会落到旧窗口或其他应用。

### [Bug][安全][P1] RX-008 输入焦点错误可能把 Enter 变成“停止任务”

#### 现象与证据

早期坐标操作曾点击到模型选择区；另一次输入框未获得焦点后按 Enter，Reasonix 把该按键作用到当前“停止”控件，正在执行的任务被停止，消息没有发送。

#### 当前 workaround

- 永远不按 Enter 发送；
- 只识别安全按钮 class；
- 明确拒绝 `composer__btn--stop` 和名称含“停止/Stop”的控件；
- 使用 selector click，不使用猜测坐标。

#### 为什么仍未解决

安全依赖 UI class/name 未变化，且当前 UIA 层仍可能错误返回控件。

#### 根治方向

使用正式 send/steer API；若仍保留 UIA，应在 Reasonix 端为发送、排队、直接引导和停止提供稳定 AutomationId，并让危险动作需要不同语义和额外确认。

#### 退出验收

故意让焦点位于模型选择、停止按钮、窗口菜单和其他应用时，监督发送均不会触发停止或修改模型。

### [Bug][工具链][P1] RX-009 React 受控输入框与 Unicode 文本不能由普通 UIA 输入稳定处理

#### 现象与证据

- `set-value` 对 `composer__input` 返回成功后，React 会把值恢复为空；
- `get-value` 可能返回空，但控件树中的 `value` 已包含文本；
- `SendInput` 输入长中文时会丢失汉字、空格、连字符和 marker；
- `post-message` 对目标 textarea 的 Unicode 文本更可靠，但仍属于实现细节。

#### 当前 workaround

- 使用 `post-message` 定向输入；
- 同时读取 ValuePattern 与控件树 `value`；
- 文本必须包含 ASCII 唯一 marker；
- marker 不完整时禁止继续点击队列按钮。

#### 为什么仍未解决

当前没有稳定的文本赋值契约，React、WebView2 或 WinApp 更新都可能改变行为。

#### 根治方向

优先使用 Reasonix 正式消息 API；次选直接 DOM/CDP 输入并触发框架认可的 input/change 事件；UIA 仅作为 fallback。

#### 退出验收

包含中文、英文、路径、反斜杠、引号、换行、emoji 和 10,000 字符的消息可逐字回读一致，且不会污染剪贴板或其他应用。

### [Bug][工具链][P1] RX-010 React 按钮对 InvokePattern 和 UIA 暴露语义不一致

#### 现象与证据

- 运行中输入为空时只暴露“停止”，加入引导队列按钮处于 disabled；
- 输入文字后才出现 `composer__btn--send composer__btn--steer`；
- 对该按钮调用 `InvokePattern` 有时返回成功，但 React 没有生成队列项；
- selector 级真实 `click` 更接近浏览器事件，才能触发队列变化。

#### 当前 workaround

- 输入后重新扫描；
- 只接受明确的 send/steer class；
- 优先 selector click，InvokePattern 仅作 fallback；
- 点击后必须观察队列或日志变化。

#### 为什么仍未解决

按钮调用结果与业务动作没有强绑定，工具层“成功”不等于 Reasonix 状态变化。

#### 根治方向

由 Reasonix 暴露稳定 AutomationId 和可验证 action result；或取消 UI 按钮作为控制入口。

#### 退出验收

每次按钮调用都返回业务 request ID；按钮层 success 与队列/steer 状态一致。

### [Bug][工具链][P1] RX-011 指导队列缺少幂等、去重和安全清理

#### 现象与证据

重复超时和重试曾累计 9 条待处理引导，其中包含重复文本和被 Unicode 输入破坏的内容。若直接点击第一条，会把旧错误指示注入当前任务；若不处理，队列可能在后续时机继续发送。

#### 当前 workaround

- 每条消息带唯一 marker；
- 展开队列后按 marker 文本行和同一垂直行按钮匹配；
- 不允许“有多条时点击第一条”；
- 超时后先对账，再重试；
- 对由监督工具创建的重复项单独识别。

#### 为什么仍未解决

队列项没有 supervisor request ID、创建者、幂等键和批量安全清理接口。

#### 根治方向

Reasonix 队列项增加：

- stable ID；
- client request ID；
- created_by/source；
- dedupe key；
- status；
- cancel/remove API；
- 原子 steer API。

#### 退出验收

相同 request ID 重试只存在一个队列项；可以列出、查询和删除监督工具自己创建的项，不影响用户手工草稿或其他引导。

### [Bug][工具链][P1] RX-012 Reasonix recovery session 和日志轮转使“最新会话”判断不可靠

#### 现象与证据

Reasonix 在运行中产生多层 recovery session：

- 新 `.jsonl.meta` 的 `parent_id` 指向上一恢复会话；
- revision 持续增长；
- marker 可能写入父或当前 recovery 文件；
- 只检查“最新三个事件文件”会漏掉消息；
- 搜索普通关键词还可能命中 Reasonix 自己的分析文本，而不是监督消息。

#### 当前 workaround

- 遍历当前项目全部相关会话文件；
- 按 marker 精确搜索；
- 解析消息 role、index、revision 和 mid-turn steer 包装；
- 沿 `parent_id` / recovery lineage 核对。

#### 为什么仍未解决

文件扫描成本随会话增长，且对内部文件格式有强耦合。

#### 根治方向

建立官方 session lineage/index API，按 tab 返回当前 authoritative session、父链、最新 revision 和消息查询接口。

#### 退出验收

无论发生多少次 recovery 或 snapshot conflict，监督工具都能在固定时间内定位当前 authoritative turn，并区分用户消息、模型分析和工具输出。

### [Bug][治理][P1] RX-013 风险扫描与文档曾把“描述风险”误判为“执行风险”

#### 现象与证据

早期风险扫描直接扫描整段 transcript，把以下内容误报为已执行：

- Reasonix 说明“不得执行 DROP DATABASE”；
- 写入治理文档中的 `git add -A`、真实恢复等文字；
- 用户监督提示中的禁止命令。

监督工具 README 也曾声称采用“Playwright 直接通过 CDP 接管 Reasonix WebView2”，但实际 CDP 未开放，真实架构是 Playwright 本地监督台 + WinApp UIA + 会话日志。

开发过程中还出现过：

- 测试引用旧函数名；
- 模块导出与运行中旧进程不一致；
- 磁盘文件与服务内存版本不一致；
- 旧回执和旧服务导致看似成功的错误判断。

#### 当前 workaround

- 风险扫描只读取命令执行类工具的参数；
- `write_file`、`edit_file`、用户提示和模型解释不作为执行证据；
- 服务修改后重启并做只读 status；
- README 已改为真实混合架构。

#### 为什么仍未解决

当前解析仍基于工具名和 JSON 文本，没有统一的结构化事件 schema、版本兼容和端到端回归矩阵。

#### 根治方向

- 定义结构化 supervisor event schema；
- 只从已执行 tool result / command receipt 生成风险；
- 增加 fixture 覆盖“提到危险命令但未执行”；
- 文档从运行时能力探测生成关键状态，避免手写漂移；
- 发布前运行完整 supervisor self-test。

#### 退出验收

- 禁止语句、代码示例和治理文档零误报；
- 真正执行危险命令能稳定命中；
- README、doctor、status 与运行时架构一致；
- 服务热重载、旧回执和旧进程不会产生假成功。

### [Bug][安全][P0] RX-014 ask 默认选项被冒充为真实用户裁决

#### 现象与证据

Reasonix 的三选一问答卡默认把第 3 项“暂停，移交网页端处理”显示为 `prompt-action--selected`。当前外部监督会话中用户没有选择该项，但 ask 工具返回：

`The user answered: 暂停，移交网页端处理`

Reasonix 随后把它当成真实裁决，停止当前任务并生成移交报告。

#### 当前 workaround

- 高影响 ask 结果必须与当前外部会话中的明确用户选择对账；
- 来源不明、默认选中或用户未实际作答的结果立即作废；
- 使用带唯一 marker 的普通用户消息纠正，并在事件日志中确认 `role=user`。

#### 为什么仍未解决

当前只能事后发现错误裁决。ask 回执没有提供可证明的人类交互来源、choice ID、时间戳或 `explicit_confirmation`。

#### 根治方向

问答卡默认不选择任何选项；没有明确点击或键盘确认时不得提交。ask 工具回执必须包含 `choice_id`、交互时间、输入来源和显式确认标志。

#### 退出验收

- 问答卡打开后等待、切窗、最小化和窗口重建均不会自动提交；
- 每个选项只有在真实用户交互后才返回；
- 工具回执可追溯到唯一交互事件。

### [Bug][工具链][P1] RX-015 屏幕外主窗口导致监督误选同进程托盘窗口

#### 现象与证据

真正的 Reasonix 主窗口为 `HWND 1379084`、`class=wailsWindow`、`title=Reasonix`，一度位于 `(-21333,-21333)` 且尺寸仅 `158×26`。同进程的 `SystrayClass` 窗口面积更大，旧算法按面积选中托盘窗口，导致 composer 和问答卡全部“消失”。

#### 当前处置

- `show-reasonix.ps1` 使用 `SetWindowPos` 把屏幕外或过小的主窗口恢复到可见区域；
- 窗口选择只接受 `title=Reasonix` 或 `class=wailsWindow`；
- 托盘窗口不参与业务控件定位。

#### 当前状态

该具体根因已完成修复并通过真实 Session 1 验证：主窗口恢复后 composer 与发送按钮重新可定位。但长期仍依赖窗口和 UIA，因此 RX-003、RX-007 继续开放。

#### 退出验收

同进程存在托盘窗口、主窗口最小化或移出屏幕时，监督始终恢复并选择主 `wailsWindow`，不重启 Reasonix、不操作托盘窗口。

### [Bug][安全][P0] RX-016 预发送失败遗留草稿会在后续 turn 自动提交旧指令

#### 现象与证据

旧监督草稿 `SUPERVISOR-BRIDGE-7B193B45CC45` 因 marker 后换行被 UI 折叠，脚本以 `expected length=462 / observed length=461` 判定失败。回执显示 `queueClicked=false`、`guideClicked=false`，但草稿仍留在 composer；它后来作为 `role=user` 进入会话，并在正确纠正到达前触发：

- `618c0e4a`
- `e3619cb3`
- `8125564`
- `git push origin master`

#### 当前 workaround

- 输入验证改为唯一 marker + marker 前仅允许空白 + 正文空白规范化后完整一致；
- 点击发送或排队前发生失败时，原子脚本必须清除本次 marker 草稿并验证为空；
- 新发送前拒绝任何未知草稿；
- 失败回执记录 `draftCleanupAttempted`、`draftCleanupSucceeded` 和错误原因。

#### 为什么仍未解决

代码路径和静态测试已补齐，但尚未完成真实 Session 1 故障注入：故意制造正文不一致、触发后续 turn，并证明旧 marker 永不进入 events。更根本地，UI 草稿仍可能被 Reasonix 自己的状态机提交。

#### 根治方向

使用具有 operation ID、幂等和取消语义的原生 submit/steer API。草稿写入、验证、提交和取消必须属于同一服务端事务；UI 草稿不得因其他 turn 自动提交。

#### 退出验收

- 故意制造输入不一致后 composer 自动恢复为空；
- 随后启动新 turn，旧 marker 不出现在任何 authoritative session；
- 失败操作无 commit、push 或其他外部副作用；
- 相同 operation ID 重试不会产生第二条消息。

### [Bug][工具链][P1] RX-017 selector-scoped inspect 没有保证返回目标子树

#### 现象与证据

对问答卡 selector `win-review-ede5` 调用 `ui inspect` 时，结果仍包含顶层窗口的最小化、最大化和关闭按钮。调用方不能假设 selector 参数已把结果限制在问答卡子树；模糊搜索还会命中历史 transcript 中的同名文字。

#### 当前 workaround

- 使用全树读取后按当前主窗口、可见状态、稳定 class/AutomationId 过滤；
- 无法证明控件唯一性和父子关系时拒绝点击；
- 不依赖历史文本搜索结果执行高影响问答选择。

#### 为什么仍未解决

WinApp 当前没有可验证的严格子树契约，ask 选项与提交按钮无法安全地仅靠 selector 范围操作。

#### 根治方向

修正 `inspect <selector>`，确保返回根等于目标元素；或提供 `find-descendants(selector)` 与稳定 runtime ID/父子关系 API。找不到 selector 时应明确失败，不得退化为整窗扫描。

#### 退出验收

对 composer、guidance shelf 和 ask card 三类 selector 检查时，所有返回节点都属于目标子树，顶层标题栏按钮不再混入。

---

## 4. 根治实施顺序

### Phase RX-A：交付完整性

优先解决：RX-001、RX-002、RX-011、RX-016。

目标：每次监督发送具有 request ID、幂等、阶段状态和日志确认；禁止超时后盲重试。

### Phase RX-B：正式控制面

优先解决：RX-003、RX-004、RX-005。

目标：获得官方 local API / CLI / signed bridge，逐步取消 UIA 对写操作的所有权。

### Phase RX-C：交互桥稳定化

优先解决：RX-006、RX-007、RX-008、RX-009、RX-010、RX-015、RX-017。

目标：在正式 API 尚未具备时，使 fallback 可重复、Unicode-safe、无坐标、无 Enter、无停止误触。

### Phase RX-D：可观察性与治理

优先解决：RX-012、RX-013、RX-014。

目标：统一 session lineage、结构化事件、风险扫描和自检；文档不得冒充运行时证据。

## 5. 当前允许继续使用的临时路径

在上述问题关闭前，只允许以下受限使用方式：

1. 只读状态优先读取 Reasonix 会话日志和元数据。
2. 写入必须带唯一 marker。
3. 任何 UIA 超时后必须先对账，禁止立即重复发送。
4. 不按 Enter，不使用猜测坐标，不接受停止按钮。
5. 运行中引导必须完成“排队 + marker 对应行直接引导 + 日志 role=user 验证”。
6. 找不到 marker 时必须明确报告未完成，不得用旧消息成功记录替代。
7. 发现队列存在多条未知项目时停止自动选择，先列出和归属。
8. 不通过关闭 WDAC、替换未签名 Reasonix 二进制或暴露 CDP 到非 loopback 地址绕开问题。

本节只是当前安全 fallback，不是最终架构。