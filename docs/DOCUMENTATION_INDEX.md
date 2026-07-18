# LinguaCafe Documentation Index

> 当前入口：2026-07-18。本文只负责路由，不保存任务历史、长篇状态或重复契约。

## 1. 新任务读取顺序

1. `AGENTS.md` — 每次必读的项目硬规则。
2. 先判断任务类型，再按需加载：
   - 继续当前工作或交接：`docs/plans/current-working-handoff.md`。
   - 选择、插入或调整产品任务：再读 master plan 和 Anki-aligned roadmap。
   - 已明确的模块任务：直接读对应 ADR、模块契约、源码、测试和一个既有范例。
3. 不默认读取全部计划、全部 ADR、全部历史或全部字幕。

不要从 `docs/CODEX_HANDOFF.md`、`docs/NEXT_TASK.md`、`docs/CURRENT_STATUS.md` 或 `docs/FSRS_PHASE*.md` 开始；这些是历史参考。

## 2. 当前状态路由

- 当前工作台：`docs/plans/current-working-handoff.md`
- 长期工作登记：`docs/plans/linguacafe-master-plan.md`
- 产品/架构顺序：`docs/plans/anki-aligned-product-and-architecture-roadmap.md`
- AI 协作与交付：`docs/plans/vibe-coding-collaboration-rules.md`
- 历史索引：`docs/HISTORY_INDEX.md`

已关闭阶段的结论留在对应计划、验收报告或 history 中；不要复制回本索引。

## 3. 按任务加载

| 任务 | 首选文档 |
|---|---|
| Architecture Gate | `docs/adr/ADR-0001-architecture-gate-workflow.md` |
| Sense HTTP / Controller | `docs/architecture/sense-http-controller-boundaries.md` |
| Sense Review | `docs/architecture/sense-review-module-boundaries.md` |
| Reader 数据契约 | `docs/plans/textblock-reader-data-contract.md` |
| Source context | `docs/plans/sense-source-context-contract.md` |
| ReviewCardManage | `docs/plans/review-card-manage-architecture-convergence-plan.md` |
| Review settings preset | `docs/plans/review-settings-preset-v1-plan.md` |
| Anki 参考产品决策 | `docs/plans/vibe-coding-collaboration-rules.md` §8.7，再查 Anki 官方手册/源码 |
| Custom Study | `docs/plans/custom-study-1a-implementation-plan.md`、`docs/adr/ADR-0016-custom-study-preview-session.md` |
| AI study card | `docs/plans/ai-study-card-v1-frozen-plan.md`、V6 plan 及 ADR-0004/0005 |
| Testing DB | `docs/plans/testing-db-health-playbook.md` |
| MCP Chrome | `docs/plans/mcp-chrome-local-smoke-playbook.md` |
| Text reader smoke | `docs/testing/text-reader-smoke-guard.md` |
| Spec → harness | `docs/plans/spec-to-harness-candidates.md` |

ADR 列表以 `docs/adr/` 中实际文件为准。只读取与当前接口、数据或架构决定直接相关的 ADR。

## 4. 文档层级

| 层级 | 内容 | 更新时机 |
|---|---|---|
| 根规则 | 安全、范围、停止、验证 | 稳定且反复相关的决定变化时 |
| 当前入口 | 当前工作台、下一候选、阻塞 | 每次交接 |
| 计划/roadmap | 未完成需求和阶段顺序 | 产品落位变化时 |
| ADR | 昂贵、稳定的技术决定及理由 | 决定被接受或取代时 |
| 模块契约 | 责任、接口、数据流、兼容性 | 模块边界变化时 |
| test/smoke/harness | 可执行不变量 | 承重行为稳定或回归出现时 |
| history | 已关闭状态、旧报告、临时过程 | 不再作为当前指令时 |

同一事实只保留一个权威正文；其他文件使用链接。过期文档必须明确标记历史或移入 history。

## 5. 当前长期边界入口

- ReviewCard 生命周期：ADR-0010。
- Review 队列顺序：ADR-0015。
- Custom Study preview session：ADR-0016。
- Review time / daily limits：ADR-0018、ADR-0019。
- EncounteredWord / FSRS transition：ADR-0020、ADR-0021。
- WordSense POS canonicalization：ADR-0022。
- Settings / preset convergence：ADR-0023 至 ADR-0027。

具体类名、payload、验收数字和阶段完成情况留在对应 ADR、计划和测试报告中，不在本索引复制。
