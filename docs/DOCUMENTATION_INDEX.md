# LinguaCafe Documentation Index

> 当前入口：2026-08-29。本文只负责路由，不保存任务历史、长篇状态或重复契约。

## 1. 新任务读取顺序

1. `AGENTS.md` — 每次必读的项目硬规则。
2. `docs/CURRENT_AI_CONTEXT.md` — 当前事实、计划完成度、开放维护项和停止点；默认只加载这份状态文档。
3. 先判断任务类型，再按需加载：
   - 继续一个已明确的当前任务：只读 `docs/plans/current-working-handoff.md` 顶部权威区和该任务相关段落，不默认加载全文。
   - 选择、插入或调整当前产品任务：先读 `docs/product/LinguaCafe_Product_Rebaseline_English_Reading_First_2026-08-18.md`，再读 Goal ledger 当前 Phase；只有需要历史来源时才读旧 master plan / Anki-aligned roadmap。
   - Reader 提前 Good、跨文章/跨 session 的 24h 正向最小间隔、被动/主动认识、同 session/card 单次计分、阅读内“不认识”→Again：读 `docs/adr/ADR-0061-reading-early-review-minimum-spacing-boundary.md`；24h 不足时普通 Reader 完全静默读 `docs/adr/ADR-0063-reading-24h-silent-nonscoring-ux.md`。`ADR-0060/0059` 仅保留 superseded 历史。
   - AI Reading Assist `matched_existing` → 真实 Reader 句子 → WordSenseOccurrence / Sense Review 例句轮换：读 `docs/adr/ADR-0062-reading-ai-matched-existing-source-example-binding.md`；全部真实来源例句无 top-N 上限、完整池随机轮换且不得连续重复读 `docs/adr/ADR-0064-unbounded-real-example-random-rotation.md`。
   - 已明确的模块任务：直接读对应 ADR、模块契约、源码、测试和一个既有范例。
4. 不默认读取全部计划、全部 ADR、全部历史或全部字幕。

不要从 `docs/CODEX_HANDOFF.md`、`docs/NEXT_TASK.md`、`docs/CURRENT_STATUS.md` 或 `docs/FSRS_PHASE*.md` 开始；这些是历史参考。

## 2. 当前状态路由

- AI 最小当前上下文：`docs/CURRENT_AI_CONTEXT.md`
- 工作区只读盘点与稳定化：`docs/plans/workspace-stabilization-plan-2026-08-03.md`；运行 `node scripts/workspace-inventory.mjs`
- 当前工作台与任务追溯：`docs/plans/current-working-handoff.md`
- 当前产品权威：`docs/product/LinguaCafe_Product_Rebaseline_English_Reading_First_2026-08-18.md`
- 当前 Goal / Phase H 执行顺序（Phase G/G-GATE、H-00–H-07 已关闭；当前 H-08）：`docs/plans/LinguaCafe_Goal_Mode_All_Milestones_Sol_Medium_2026-08-09.md`
- H-02 representative 100-user load 验收：`docs/testing/h02-representative-load-acceptance-2026-08-28.md`
- H-03 bottleneck diagnostics 验收：`docs/testing/h03-bottleneck-diagnostics-acceptance-2026-08-28.md`；主流程 flow latency 已证明健康，fresh Apache prefork cold-burst deployment 问题留 H-07
- H-04 backup/restore drill 验收：`docs/testing/h04-backup-restore-drill-acceptance-2026-08-28.md`；真实 MySQL backup→restore、write fence、automatic safety rollback 与 zero-residue 已证明
- H-05 isolation/privacy boundary 验收：`docs/testing/h05-isolation-privacy-boundary-acceptance-2026-08-28.md`；用户/语言隔离、永久账号删除、token/device 撤销、媒体 quarantine/rollback 与真实 Web 删除流程已证明
- H-06 public authentication convergence 验收：`docs/testing/h06-public-authentication-convergence-acceptance-2026-08-29.md`；email/password 单一 owner、双 RateLimiter、当前密码改密与真实登录/退出已证明
- H-07 public runtime/cost gate 验收：`docs/testing/h07-public-runtime-and-cost-acceptance-2026-08-29.md`；架构决定见 `docs/adr/ADR-0066-h07-public-runtime-and-proxy-gate.md`，首次公开测试发布步骤见 `docs/release/h07-public-beta-deployment-runbook.md`
- 长期历史总账与运维登记：`docs/plans/linguacafe-master-plan.md`
- 已确认产品方向与讨论历史：`docs/product/confirmed-product-decisions-and-discussion-roadmap-2026-07-23.md`
- 历史 Anki-aligned 产品/架构路线：`docs/plans/anki-aligned-product-and-architecture-roadmap.md`
- 2026-07-23 历史代码、文档与 Bug 架构快照：`docs/architecture/code-documentation-and-bug-architecture-audit-2026-07-23.md`
- 2026-07-23 Anki 功能与架构历史通俗对比：`docs/architecture/anki-function-and-architecture-sales-comparison-2026-07-23.md`
- 云端主导移动化路线、成本与技术里程碑：`docs/plans/cloud-first-mobile-product-and-technical-milestones-2026-07-28.md`
- 2026-07-23 原项目代码、测试与架构历史对比：`docs/architecture/upstream-code-test-and-architecture-comparison-2026-07-23.md`
- 体验、Bug、架构和治理总台账：`docs/plans/local-experience-bug-optimization-ledger-2026-07-23.md`
- Reasonix 监督、Playwright/WinApp 桥、mid-turn steer 与 workaround 根治台账：`docs/plans/reasonix-supervision-toolchain-bug-ledger-2026-08-05.md`
- 当前执行方式与未来 Codex++ 并行方向：`docs/plans/vibe-coding-collaboration-rules.md` §1.5、`docs/CURRENT_AI_CONTEXT.md` §8；当前 guard 为 `tests/js/CurrentExecutionWorkflowDocsGuard.test.mjs`
- 当前 M0–M18 goal audit: `docs/testing/m0-m18-goal-completion-audit-2026-08-01.md`
- 旧架构阶段 completion audit（历史）：`docs/testing/goal-mode-roadmap-completion-audit-2026-07-23.md`
- 历史索引：`docs/HISTORY_INDEX.md`

已关闭阶段的结论留在对应计划、验收报告或 history 中；不要复制回本索引。

## 3. 按任务加载

| 任务 | 首选文档 |
|---|---|
| Architecture Gate | `docs/adr/ADR-0001-architecture-gate-workflow.md` |
| Sense HTTP / Controller | `docs/architecture/sense-http-controller-boundaries.md` |
| Sense Review | `docs/architecture/sense-review-module-boundaries.md` |
| Reviewer Phase 5 closure | `docs/testing/reviewer-architecture-convergence-browser-acceptance-2026-07-18.md` |
| Reader Phase 6A hover policy closure | `docs/testing/reader-hover-lookup-policy-browser-acceptance-2026-07-18.md` |
| Reader Phase 6B hover position closure | `docs/testing/reader-hover-position-policy-browser-acceptance-2026-07-23.md` |
| Reader Phase 6C sentence context closure | `docs/testing/reader-sentence-context-policy-browser-acceptance-2026-07-23.md` |
| Reader Phase 6D drag selection closure | `docs/testing/reader-drag-selection-policy-browser-acceptance-2026-07-23.md` |
| Reader Phase 6E phrase instance selection closure | `docs/testing/reader-phrase-instance-selection-policy-browser-acceptance-2026-07-23.md` |
| Reader Phase 6F sidebar action/focus closure | `docs/testing/reader-sidebar-action-focus-browser-acceptance-2026-07-23.md` |
| Reader Phase 6G hotkey policy closure | `docs/testing/reader-hotkey-policy-browser-acceptance-2026-07-23.md` |
| Reader Phase 6H navigation policy closure | `docs/testing/reader-navigation-policy-browser-acceptance-2026-07-23.md` |
| Reader Phase 6I completion candidate policy closure | `docs/testing/reader-completion-candidate-policy-browser-acceptance-2026-07-23.md` |
| Reader Phase 6J token presentation policy closure | `docs/testing/reader-token-presentation-policy-browser-acceptance-2026-07-23.md` |
| Reader Phase 6K lookup response policy closure | `docs/testing/reader-lookup-response-policy-browser-acceptance-2026-07-23.md` |
| Reader Phase 6L lookup API closure | `docs/testing/reader-lookup-api-browser-acceptance-2026-07-23.md` |
| Reader Phase 6M English fallback tokenizer / Phase 6 closure | `docs/testing/english-fallback-tokenizer-service-browser-acceptance-2026-07-23.md` |
| Reader 数据契约 | `docs/plans/textblock-reader-data-contract.md` |
| Source context | `docs/plans/sense-source-context-contract.md` |
| ReviewCardManage | `docs/plans/review-card-manage-architecture-convergence-plan.md` |
| Review settings preset | `docs/plans/review-settings-preset-v1-plan.md` |
| Anki 参考产品决策 | `docs/product/confirmed-product-decisions-and-discussion-roadmap-2026-07-23.md`、`docs/plans/anki-aligned-product-and-architecture-roadmap.md`，再查 Anki 官方手册/源码 |
| Custom Study / Card Marker | `docs/plans/custom-study-1a-implementation-plan.md`、`docs/adr/ADR-0016-custom-study-preview-session.md`、`docs/adr/ADR-0029-card-marker-and-custom-study-1b.md`、`docs/testing/card-marker-custom-study-1b-browser-acceptance-2026-07-18.md` |
| AI study card / provider boundary | `docs/plans/ai-study-card-v1-frozen-plan.md`、`docs/adr/ADR-0004-ai-study-card-v6-real-ai-boundary.md`、`docs/adr/ADR-0005-ai-study-card-v6-real-provider-implementation-plan.md`、`docs/adr/ADR-0030-ai-study-card-v6-default-off-provider-gate.md`、`docs/testing/ai-study-card-provider-environment-gate-acceptance-2026-07-23.md` |
| AI Study Card Phase 7 service convergence / lifecycle, package, validation, source, generation closure | `docs/superpowers/specs/2026-07-23-ai-study-card-service-convergence-design.md`、`docs/testing/ai-study-card-pending-lifecycle-service-browser-acceptance-2026-07-23.md`、`docs/testing/ai-study-card-candidate-package-service-acceptance-2026-07-23.md`、`docs/testing/ai-study-card-candidate-validation-service-acceptance-2026-07-23.md`、`docs/testing/ai-study-card-source-binding-service-acceptance-2026-07-23.md`、`docs/testing/ai-study-card-generation-service-browser-acceptance-2026-07-23.md` |
| Testing DB | `docs/plans/testing-db-health-playbook.md` |
| 本地真实浏览器验收、localhost、测试身份与工具降级 | `docs/plans/mcp-chrome-local-smoke-playbook.md`、`docs/adr/ADR-0033-real-browser-acceptance-channel-fallback.md`、`docs/adr/ADR-0034-goal-mode-autonomous-decisions-and-deferred-acceptance.md`、`docs/adr/ADR-0037-goal-mode-nonblocking-execution-frontier.md` |
| Text reader smoke | `docs/testing/text-reader-smoke-guard.md` |
| Spec → harness | `docs/plans/spec-to-harness-candidates.md` |
| 当前产品总方向 | `docs/product/LinguaCafe_Product_Rebaseline_English_Reading_First_2026-08-18.md` + Goal Phase G |
| Reader 提前复习 / 24h 最小正向间隔 / 静默 non-scoring / 单次计分 / 不认识→Again | `docs/adr/ADR-0061-reading-early-review-minimum-spacing-boundary.md`、`docs/adr/ADR-0063-reading-24h-silent-nonscoring-ux.md`；`ADR-0060/0059` 历史 |
| AI 阅读 matched-existing 真实来源例句绑定 / 无上限真实例句池 / 随机不连续重复轮换 | `docs/adr/ADR-0062-reading-ai-matched-existing-source-example-binding.md`、`docs/adr/ADR-0064-unbounded-real-example-random-rotation.md`、`docs/plans/reading-inline-review-and-example-pool-plan.md` |
| 历史产品讨论：阅读内评分 / AI 阅读 / 翻译布局 | `docs/product/confirmed-product-decisions-and-discussion-roadmap-2026-07-23.md`（与当前 rebaseline 冲突处仅作历史） |
| 云端主导移动端、有限离线、成本和下一技术里程碑 | `docs/plans/cloud-first-mobile-product-and-technical-milestones-2026-07-28.md`、`docs/plans/mobile-api-v1-contract.md`、`docs/adr/ADR-0031-goal-mode-roadmap-execution-authorization.md`、`docs/adr/ADR-0032-mobile-api-foundation-and-idempotent-rating.md`、`docs/adr/ADR-0034-goal-mode-autonomous-decisions-and-deferred-acceptance.md`、`docs/adr/ADR-0037-goal-mode-nonblocking-execution-frontier.md`、`docs/adr/ADR-0035-mobile-operation-ledger-and-linear-undo-redo.md`、`docs/adr/ADR-0036-m6-resilience-health-and-isolation-boundaries.md`、`docs/adr/ADR-0055-single-owner-restore-without-user-visible-preview.md`、`docs/adr/ADR-0038-m10-unified-search-and-word-sense-tags.md`、`docs/plans/m6-resilience-health-isolation-implementation-plan.md`、`docs/plans/m10-unified-search-tags-browser-foundation-plan.md`、`docs/testing/mobile-api-foundation-acceptance-2026-07-28.md`、`docs/testing/mobile-operation-ledger-acceptance-2026-07-28.md`、`docs/testing/m5-mobile-reader-reviewer-touch-acceptance-2026-07-29.md`、`docs/testing/m6a-safe-backup-acceptance-2026-07-28.md`、`docs/testing/m6b-restore-safety-acceptance-2026-07-28.md`、`docs/testing/m6c-article-health-acceptance-2026-07-28.md`、`docs/testing/m6d-isolation-closeout-acceptance-2026-07-28.md` |
| 恢复与发布程序（CFH-01/02；已关闭，当前无 active task） | `docs/plans/linguacafe-recovery-publication-master-plan-2026-08.md`、`docs/execution/CURRENT_MILESTONE.json`、`docs/audits/cfh-02-m6-exact-slice-manifest-2026-08-05.json`、`docs/plans/cfh-02-m6-publication-plan.md`、`docs/adr/ADR-0055-single-owner-restore-without-user-visible-preview.md`、`docs/testing/cfh-02b-m6a-publication-acceptance-2026-08-05.md`、`docs/testing/cfh-02b-m6b-responsive-restore-acceptance-2026-08-05.md` |
| M10 统一查询、WordSense Tag、Browser 与移动搜索验收 | `docs/testing/m10-unified-search-tags-browser-foundation-acceptance-2026-07-28.md` |
| M11 Review Control 与手动调度 | `docs/adr/ADR-0039-m11-review-control-and-manual-operation-ledger.md`、`docs/plans/m11-review-control-manual-scheduling-plan.md`、`docs/testing/m11-review-control-manual-scheduling-acceptance-2026-07-29.md` |
| M12 Special Study Sessions / Custom Study V2 | `docs/adr/ADR-0040-m12-special-study-sessions.md`、`docs/plans/m12-special-study-sessions-plan.md`、`docs/testing/m12-special-study-sessions-acceptance-2026-07-29.md` |
| M13 Review Settings 与 Workload Planner V2 | `docs/adr/ADR-0045-m13-review-settings-workload-planner.md`、`docs/plans/m13-review-settings-workload-planner-plan.md`、`docs/testing/m13-review-settings-workload-planner-acceptance-2026-07-29.md` |
| M14 Statistics 与 Card Info V3 | `docs/adr/ADR-0046-m14-statistics-card-info-v3.md`、`docs/plans/m14-statistics-card-info-v3-plan.md`、`docs/testing/m14-statistics-card-info-v3-acceptance-2026-07-29.md` |
| G-06F FSRS 参数优化策略与定时命令 | `docs/adr/ADR-0065-fsrs-optimization-policy-and-scheduled-command.md` |
| M15 Browser Knowledge Hygiene V3 | `docs/adr/ADR-0047-m15-browser-knowledge-hygiene-v3.md`、`docs/plans/m15-browser-knowledge-hygiene-v3-plan.md`、`docs/testing/m15-browser-knowledge-hygiene-v3-acceptance-2026-07-29.md` |
| M16 Portable Data 与 Anki Interoperability V1 | `docs/adr/ADR-0048-m16-portable-data-and-anki-interoperability-v1.md`、`docs/plans/m16-portable-data-and-anki-interoperability-v1-plan.md`、`docs/testing/m16-portable-data-and-anki-interoperability-v1-acceptance-2026-07-29.md` |
| M17 Review Experience 与 Accessibility V2（Web；Android 平台证据归 M7） | `docs/adr/ADR-0049-m17-review-experience-accessibility-web-v2.md`、`docs/plans/m17-review-experience-accessibility-web-v2-plan.md`、`docs/testing/m17-review-experience-accessibility-web-v2-acceptance-2026-08-01.md` |
| M18 Media、发音与离线音频完整性 V1 | `docs/adr/ADR-0050-m18-media-pronunciation-offline-audio-v1.md`、`docs/plans/m18-media-pronunciation-offline-audio-v1-plan-2026-08-01.md`、`docs/testing/m18-media-pronunciation-offline-audio-v1-acceptance-2026-08-01.md` |
| M3 文章下载包与短期复习包 V1 | `docs/adr/ADR-0041-m3-mobile-download-packages-v1.md`、`docs/plans/m3-mobile-download-packages-plan.md`、`docs/plans/mobile-api-v1-contract.md`、`docs/testing/m3-mobile-download-packages-acceptance-2026-07-29.md` |
| M4 同步操作队列与冲突模拟器 | `docs/adr/ADR-0042-m4-queued-action-sync-and-conflict-simulator.md`、`docs/plans/m4-queued-action-sync-conflict-simulator-plan.md`、`docs/plans/mobile-api-v1-contract.md`、`docs/testing/m4-queued-action-sync-acceptance-2026-07-29.md` |
| M5 Mobile Reader / Reviewer 触摸适配 V1 | `docs/adr/ADR-0043-m5-mobile-reader-reviewer-touch-adaptation.md`、`docs/plans/m5-mobile-reader-reviewer-touch-adaptation-plan.md`、`docs/testing/m5-mobile-reader-reviewer-touch-acceptance-2026-07-29.md` |
| M7 Android Connected MVP | `docs/adr/ADR-0044-m7-android-connected-mvp.md`、`docs/plans/m7-android-connected-mvp-plan.md`、`docs/testing/m7-android-connected-mvp-acceptance-2026-08-01.md`、`docs/testing/m7-android-connected-mvp-interim-2026-07-29.md`（历史 deferred 记录） |
| M8 Limited Offline MVP | `docs/adr/ADR-0051-m8-limited-offline-mvp.md`、`docs/plans/m8-limited-offline-mvp-plan.md`、`docs/testing/m8-limited-offline-mvp-acceptance-2026-08-01.md` |
| M9 iOS MVP 与发布准备 | `docs/adr/ADR-0054-m9-ios-mvp-and-release-readiness.md`、`docs/plans/m9-ios-mvp-release-plan.md`、`docs/testing/m9-ios-mvp-release-acceptance-2026-08-01.md`、`docs/testing/m9-ios-device-and-store-acceptance-playbook.md`、`docs/release/m9-ios-app-store-materials.md`、`docs/release/mobile-privacy-and-data-deletion.md` |
| Goal-mode deferred capability clusters | `docs/adr/ADR-0052-goal-mode-deferred-evidence-clusters.md` |
| Testing emulator credential transport | `docs/adr/ADR-0053-testing-emulator-credential-transport.md` |

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
