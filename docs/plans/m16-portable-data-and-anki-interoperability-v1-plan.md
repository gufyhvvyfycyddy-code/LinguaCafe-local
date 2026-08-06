# M16 Portable Data 与 Anki Interoperability V1 实施计划

- Status: Accepted / Closed
- Architecture: ADR-0048
- Milestone: M16

## Goal

在不引入通用 Note/Deck 模型的前提下，交付固定 LinguaCafe WordSense `.apkg`、
CSV/JSON 受控回导和可校验、可预览、可回滚的 LinguaCafe 逻辑数据包。

## Slices

1. 冻结内容映射并生成固定 `.apkg`；验证 SQLite schema、模板、默认 New 与显式
   调度路径。
2. 实现 JSON/CSV/固定 `.apkg` 解析、健康检查、四类 preview 和 token-bound
   apply。
3. 实现 `.lcpkg` manifest/checksum、逻辑数据导出、M6 恢复点和事务化回导。
4. 将 portable-data panel 接入 ReviewCard 管理页，完成状态、错误、空结果、
   窄屏和下载/上传流程。
5. 运行 protected tests、构建、代码/对抗审查与 server-bound 真实浏览器验收，
   清理测试资产并更新权威进度文档。

## Closure

全部切片已通过自动化、构建、固定包结构检查、server-bound 真实页面和清理审计。
验收证据见
`docs/testing/m16-portable-data-and-anki-interoperability-v1-acceptance-2026-07-29.md`。

## Success

- Anki 可识别固定模板包；内容默认不携带学习历史，显式选择才映射队列状态。
- JSON/CSV/固定 `.apkg` 重复导入可区分 create/update/skip/conflict，apply 不越权。
- `.lcpkg` 能校验 manifest，导入前有恢复点，失败无部分写入。
- 所有写路径只影响当前 authenticated user/language，不创建 ReviewLog。

## Failure

- 任意模板被猜测导入、未预览直接写、摘要漂移仍 apply；
- 未显式选择便携带调度、导入产生评分日志；
- 无 M6 恢复点写入全量包、ZIP 安全边界缺失或跨用户/语言读取写入。
