# ADR-0048 — M16 Portable Data 与 Anki Interoperability V1

- Status: Accepted / Implemented / Closed
- Date: 2026-07-29
- Milestone: M16

## Context

M16 需要固定模板的 `.apkg`、受控 CSV/JSON 回导和可校验的 LinguaCafe
逻辑数据包。该链路会接触 WordSense、ReviewCard、标签、文章、设置和调度字段，
同时必须避免把 Anki 的集合模型扩散为 LinguaCafe 的通用 Note/Deck 模型。

Anki 官方导入/导出语义允许 packaged deck 携带或省略学习进度；内容共享通常不携带
进度。文本导入以稳定首字段匹配既有笔记，未知 Note Type 不应被自动猜测。

## Decision

### 固定 Anki 契约

- Note Type 固定为 `LinguaCafe WordSense v1`，Deck 固定为
  `LinguaCafe::WordSense`。
- 一个已确认 WordSense 对应一条 note 和一张 card。
- 字段顺序冻结为：
  `LinguaCafeId, Surface, Lemma, POS, SenseZh, SenseEn, ExampleEn,
  ExampleZh, Source, Tags, FsrsState, FsrsDueAt, FsrsStability,
  FsrsDifficulty, FsrsReps, FsrsLapses, FsrsLastReviewedAt`。
- `LinguaCafeId` 是稳定首字段，格式为
  `lc-sense:<不可逆来源命名空间>:<id>`；不伪造 Anki GUID，也避免不同
  LinguaCafe 用户/实例的相同数字 ID 在 Anki 中误覆盖。
- 默认 `.apkg` 为内容导出，Anki card 进入 New 队列且不携带 ReviewLog。
- 只有显式 `include_scheduling=true` 才写入可映射的 Anki legacy queue/due/interval
  状态。LinguaCafe FSRS 原值始终留在字段中；不声明两个调度器语义完全等价。
- 导入只接受上述 Note Type、字段数量和 `lc-sense:` 标识。未知模板、额外 card
  ordinal、媒体、路径穿越条目、超限归档或损坏 SQLite 均拒绝。

### 受控内容回导

- JSON 使用带 `format=linguacafe-wordsense-content`、`format_version=1` 的 envelope。
- CSV 使用冻结表头，`LinguaCafeId` 为稳定键。
- 导入采用 `preview_token → apply`。每项分类为 `create`、`update`、`skip` 或
  `conflict`；冲突不自动覆盖。
- 更新只允许内容字段和标签。默认不写 FSRS；显式包含调度且来源为可信
  LinguaCafe package 时才恢复调度字段。
- preview 固化已校验内容及上传文件摘要；apply 前、恢复点创建后分别重新计算数据库
  目标摘要，任何目标漂移均拒绝。

### LinguaCafe 全量逻辑包

- 扩展名 `.lcpkg`，ZIP 内固定只有 `manifest.json`、`content.json`、
  `articles.json`、`settings.json`、`history.json`。
- manifest 包含格式版本、用户/语言范围、条目数量、逐文件 SHA-256 和总 schema
  标识。包内不包含凭据、token、原始数据库模型行、数据库物理转储或任意文件路径；
  `history.json` 只保存下面定义的、经校验的逻辑评分历史。
- content 包含 WordSense、ReviewCard 调度、标签；articles 包含当前用户/语言的
  book/chapter 结构与文本；settings 只包含可移植学习设置；history 包含绑定
  Sense Card 的评分历史。恢复日志使用 `restore:<原来源>` 标记，保持审计
  可识别且不调用正式评分入口。
- 导入先做归档安全检查、checksum、schema 和用户/语言健康检查，再生成预览。
- apply 前由 M6 `BackupService` 创建恢复点；内容写入在单个数据库事务中完成。
  任何失败整体回滚，并返回恢复点标识用于审计。

## Security and limits

- 上传上限 25 MiB，ZIP entry 上限 32，单 entry 解压上限 20 MiB，总解压上限
  50 MiB；拒绝绝对路径、`..`、symlink 和未知 entry。
- 内容记录上限 5,000；文章上限 2,000；所有查询必须同时绑定 authenticated
  `user_id` 与 `selected_language`。
- 不执行包内脚本，不提取到 Web 可访问目录，不解析任意模板 HTML 为业务数据。
- 普通 JSON/CSV/Anki 导入不创建 ReviewLog；只有显式 `.lcpkg` 恢复可重建经严格
  校验且可映射的历史，并使用 restore provenance。任何导入都不调用评分入口，
  不把恢复动作冒充新发生的正式评分。

## Scope

Allowed:

- M16 新增 Controller/Service、Form Request、测试和 UI panel；
- `ReviewCardManageController`、`ReviewCardExportService`、review-card manage routes/UI；
- M16 文档、roadmap/handoff/index 状态。

Forbidden:

- migration、通用 Note/Deck 模型、任意 Anki 模板转换；
- 真实数据库 migration/物理 restore、普通 APKG/JSON/CSV 的 ReviewLog 导入、评分或
  调度算法改写；`.lcpkg` 只允许按本 ADR 的受控逻辑恢复重建历史。
- M17/M18 功能。

## Verification

- focused feature/unit tests：包结构、默认/显式调度、四类预览、apply、并发漂移、
  用户隔离、模板拒绝、ZIP bomb/path traversal/checksum、恢复点与事务回滚；
- protected WordSense/ReviewCard/FSRS/M6 tests；
- PHP lint、frontend build、UI guard；
- server-bound testing sentinel 后真实浏览器完成导出选择、文件上传、预览、拒绝和
  apply 可观察路径，并清理所有 testing 数据。
