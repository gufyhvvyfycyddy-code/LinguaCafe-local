# Feable 接管 LinguaCafe 提示词

你现在接管 LinguaCafe 项目。你会收到：
1. 本项目 ZIP 压缩包；
2. Source GitHub 仓库地址；
3. Architecture review GitHub 仓库地址；
4. Product / launch GitHub 仓库地址。

开始后不要先重新询问项目背景。请按下面顺序执行：

1. 解压项目并首先完整读取：
   - FEABLE_START_HERE.md
   - docs/handovers/FEABLE_HANDOFF_2026-09-08.md
2. 再读取当前源码中的 AGENTS.md、相关 ADR、tests 和当前任务需要的 SKILL.md / 项目规则。
3. 立即重新核验 GitHub 最新 master、open PR/checks、source/architecture/product open Issues、CodeQL、Dependabot。交接文件中的 SHA 和数字只当历史快照。
4. 识别：
   - 已正式完成；
   - 当前未完成；
   - 当前阻塞；
   - 外部 Gate；
   - 当前优先级最高、证据可靠、能安全完成的最小工作单元。
5. 直接继续执行，不要只写计划。

你可以继续：
- 主线功能开发；
- bug 修复；
- 测试和真实验收；
- 依赖与安全收口；
- Android / iOS 发布准备；
- 代码清理；
- 架构优化。

架构优化允许做，但必须服务现实问题，例如：
- 重复 owner；
- 多套状态真相；
- 同一业务规则多处复制；
- 模块接口过宽；
- 难测试；
- 错误恢复链路混乱；
- 可观测性不足；
- Reader、Review、mobile sync、Settings 等高频路径复杂度过高。

不要因为文件大、代码旧或“想现代化”而大重构。

必须保留现有产品语义：
- WordSense 是正式学习内容；
- sense-level ReviewCard + FSRS 是正式调度对象；
- ReviewLog 只记录真实评分和已冻结审计动作；
- AI 推荐不默认自动确认、自动建卡、自动写 ReviewLog、自动改变 FSRS；
- legacy target_type=word 是兼容层，删除必须另做独立决策；
- Laravel + 中央数据库继续是正式权威数据源；
- 移动端不能自行演变成第二套完整权威数据库。

工作规则：
- 优先 isolated worktree；
- 不 reset / clean / stash 用户主工作区；
- 不 bulk stage / bulk commit；
- 不使用 --force；
- 不读取、修改、提交 .env / .env.*；
- 不修改 AGENTS.md；
- 不 migrate:fresh / db:wipe / 清库；
- 不运行 notification script；
- DCP 默认禁止；
- 页面任务必须真实浏览器操作验收；
- Android/iOS 必须区分 build 成功与真实设备/商店验收成功；
- API、curl、静态代码审查和截图不能替代要求的真实 UI 验收；
- 外部 Apple / Google Play / 服务器 / 隐私 / 真实用户 Gate 必须明确标记，不能伪造。

每个代码闭环应尽量执行：
实时状态核验
→ 最小安全任务
→ isolated worktree
→ 实际修改
→ focused tests
→ required build
→ doubt/code review
→ 真实浏览器或设备验收（适用时）
→ git diff --check
→ exact stage
→ commit/push/PR
→ GitHub checks
→ merge
→ 重新读取 remote master
→ 更新/关闭对应 Issue
→ 再读取实时状态
→ 选择下一最小安全任务。

当前优先方向以 FEABLE_HANDOFF 中的实时交接为基础，但继续前必须重新核验 GitHub。
