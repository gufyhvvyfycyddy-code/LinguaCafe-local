# Sense 复习系统服务边界

## 1. 当前目标

1. Sense-only review 是当前主线复习系统。EncounteredWord 主要用于阅读页状态和词汇总览，不参与复习队列。
2. WordSense / ReviewCard `target_type='sense'` 是实际复习对象。
3. 本文档用于约束后续架构变更，不是用户手册。

## 2. 服务职责总览

| 服务 | 职责 | 不应承担 |
| --- | --- | --- |
| **SenseReviewService** | 复习队列调度：dueCards、dueCount、nextDueCard、reviewedTodayCount、dueCardsWithLimits | source context、token payload、card serialization、summary shape |
| **SenseReviewQueryService** | confirmed sense card / review log 共享查询口径 | due 条件、fsrs_enabled/fsrs_due_at、reset 过滤、count 等终端操作 |
| **ReviewLimitSummaryService** | review daily limit summary shape | 正常 daily limit 计算、scoped 空队列构造以外的 summary 生成 |
| **SenseTokenPayloadService** | example sentence token payload 解析与合成 | source context、serializeCard、review queue 逻辑 |
| **SenseSourceContextService** | source context / 查看原文定位 | review queue、daily limit、card serialization |
| **SenseReviewCardSerializerService** | ReviewCard → frontend payload 序列化 | review queue、daily limit、source context |
| **ReviewService** | 全局复习入口（/review），混入 sense cards | source context、token payload 处理、daily limit 自身计算 |
| **ReviewStatsService** | 复习统计展示口径 | 单卡序列化、source context |
| **ReviewCardService** | ReviewCard CRUD、评分写入、ensureSenseCard | stats、source context、summary 构造 |

## 3. SenseReviewService 边界

### 应负责

1. due sense review card 查询入口
2. `dueCards()` / `dueCount()` / `nextDueCard()`
3. daily limit 应用（`dueCardsWithLimits()`）
4. `reviewedTodayCount()`
5. 返回 `cards` + `summary` 给上层调用方

### 不应再负责

1. source context / 查看原文 —— 已迁移到 `SenseSourceContextService`
2. token payload 构造 —— 已迁移到 `SenseTokenPayloadService`
3. review card payload 序列化 —— 已迁移到 `SenseReviewCardSerializerService`
4. summary shape 细节 —— 已迁移到 `ReviewLimitSummaryService`
5. review log / review card 写入 —— 由 `ReviewCardService` 和直接模型操作负责
6. stats 展示口径 —— 由 `ReviewStatsService` 负责

## 4. 查询口径边界

`SenseReviewQueryService` 负责 confirmed sense card 和 confirmed sense review log 的基础查询口径。

规则：

1. `confirmedSenseCardQuery()` 包含：`target_type=sense` join + `user_id` / `language_id` 隔离 + `word_senses.status=confirmed`
2. `confirmedSenseReviewLogQuery()` 包含：`review_cards` join + `word_senses` join + 用户/语言隔离 + `reviewed_at >= $since`
3. 场景条件（`fsrs_enabled`、`fsrs_due_at`、`source != reset`、`rating != reset`）由调用方添加
4. 不要在多个 service 手写重复的 where 条件

## 5. Daily limit summary 边界

`ReviewLimitSummaryService` 负责 summary 字段 shape。

规则：

1. 正常复习 summary 使用 `build()` 方法
2. scoped (book/chapter) 空队列 summary 使用 `emptyScoped()` 方法
3. 不要在 `ReviewService` 或 `SenseReviewService` 中手写 summary array
4. summary 字段是前端稳定契约，不要随便删改

## 6. Token payload 边界

`SenseTokenPayloadService` 负责 example sentence tokens。

规则：

1. source 值（`occurrence`、`word_sense`、`sentence_text_match`、`synthetic`）应保持稳定
2. `serializeCard()` 和 `sourceContext()` 可以共用该服务
3. 不要把 token 处理逻辑重新塞回 `SenseReviewService`

## 7. Source context 边界

`SenseSourceContextService` 负责查看原文相关逻辑。

包含：

1. `sourceContext()` 主入口
2. chapter source available（`source_kind='chapter'`）
3. `card_example` fallback
4. `chapter_recovered` 精确匹配恢复
5. `chapter_fuzzy` / `chapter_fuzzy_title` 模糊恢复
6. write-back（恢复结果写回 WordSense / WordSenseOccurrence）
7. `target_indexes` 和目标词标记
8. `context_tokens` 和周边句子上下文
9. 诊断日志 `logSourceContextResult()`

规则：

1. Controller 应直接调用 `SenseSourceContextService`
2. 不要再通过 `SenseReviewService` 做 gateway
3. source context API 字段（`sense_id`、`source_available`、`source_kind` 等）属于稳定契约

## 8. Review card serializer 边界

`SenseReviewCardSerializerService` 负责 sense review card payload。

规则：

1. `ReviewService` 和 `SenseReviewController` 应直接调用 `serialize()`
2. `SenseReviewService` 不再提供 `serializeCard()` wrapper
3. `type = 'sense'` 仍由 `ReviewService` 在 global review 中追加
4. payload 字段（`review_card_id`、`lemma`、`example_sentence_tokens` 等）属于稳定契约

## 9. 后续开发规则

后续新增复习功能时，按以下顺序判断归属：

1. 先判断属于哪个 service
2. 不要默认往 `SenseReviewService` 塞
3. 查询条件先考虑 `SenseReviewQueryService`
4. payload 字段先考虑 `SenseReviewCardSerializerService`
5. 查看原文先考虑 `SenseSourceContextService`
6. token 处理先考虑 `SenseTokenPayloadService`
7. daily limit summary 先考虑 `ReviewLimitSummaryService`
8. stats 展示先考虑 `ReviewStatsService`

## 10. 禁止回退

禁止以下行为：

1. 把 source context 逻辑重新放回 `SenseReviewService`
2. 把 token payload 逻辑重新放回 `SenseReviewService`
3. 把 `serializeCard()` 重新放回 `SenseReviewService`
4. 在多个 service 重复维护同一套 summary shape
5. 在多个 service 重复维护 confirmed sense card 查询 where 条件
6. 在多个 service 重复维护 non-reset review log 查询 where 条件
7. 为了方便临时绕过专用 service
