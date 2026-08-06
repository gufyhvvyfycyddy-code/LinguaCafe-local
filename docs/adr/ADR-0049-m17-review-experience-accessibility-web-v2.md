# ADR-0049 — M17 Review Experience 与 Accessibility V2（Web）

- Status: Accepted — Web slice closed
- Date: 2026-08-01
- Milestone: M17 Web slice

## Context

M13 已冻结并保存问题/答案计时、屏幕计时器和显式自动推进偏好，但复习页尚未
执行这些偏好。M17 还需要上一张卡信息、复习中快速操作以及键盘、触摸、焦点和
读屏一致性。原生触觉和通知仍依赖 M7/M9 设备路径，不属于本 Web 切片。

Anki 官方行为作为参考：计时不改变调度；自动推进必须显式启用并至少配置一个
非零计时；评分始终表达用户的真实回忆结果。LinguaCafe 的更严格边界是不允许
计时器自动选择任何评分。

## Decision

1. `GET /reviews/senses` 在既有 `cards`、`summary` 之外 additive 返回
   `experience`，内容直接来自当前用户/语言绑定的 M13 Preset：
   `show_timer`、`question_timer_seconds`、`answer_timer_seconds`、
   `auto_advance_enabled`。既有字段、顺序和错误语义不变。
2. Web 自动推进按会话显式启动。问题计时到期只显示答案；答案计时到期只暂停
   自动推进并把焦点/读屏提示交给评分区。它不评分、不跳过卡、不写 ReviewLog，
   用户选择评分后才进入下一卡。
3. 卡片计时与会话计时仅累计页面可见且未暂停的时间。页面隐藏、显式暂停、对话框
   操作和答案计时结束都会暂停体验时钟；正式评分继续使用既有
   `ReviewDurationTracker` 与后端 10 分钟边界，本切片不改变调度输入含义。
4. 上一张卡信息来自最近一次成功评分响应的快照，显示评分、计时、下一到期及 FSRS
   摘要；它是只读 UI，不重新查询或改写历史。
5. 当前卡快速操作复用既有受控入口：Marker picker、WordSense Tag assignment、
   `bury_next_day` preview/apply、source-context dialog。不得新增平行写入入口。
6. Web 本地体验偏好为字体 16–32px、高对比度、减少动画。它们保存在版本化的
   browser-local key 中，严格校验后读取，不进入服务端学习数据或 Preset schema。
7. 焦点顺序固定为体验工具条 → 问题 → 显示答案 → 答案 → 评分。自动显示答案后
   聚焦第一个评分按钮；下一卡加载后聚焦显示答案。状态变化使用简短 `aria-live`
   提示，逐秒计时本身不持续播报。

## Scope

Allowed:

- `SenseReviewController` additive response、M17 Web UI/纯 JS helper/测试；
- `SenseReview.vue`、`SenseStudyCard.vue`、`SenseReviewRatingControls.vue`；
- 复用组件的小型、向后兼容 props/events；
- M17 ADR、计划、验收与状态文档。

Forbidden:

- migration、新调度或评分入口、自动评分、FSRS/ReviewLog 语义变化；
- 原生触觉、系统通知、设备端验收；
- M18 media/audio 实现或旧 `Review.vue` 的开放重构。

## Verification

- additive response contract、用户/语言 Preset 隔离与无写入测试；
- 纯计时状态机测试：暂停/恢复、页面隐藏、问题到答案、答案等待人工评分；
- UI guard、frontend build、SenseReview/FSRS protected tests；
- testing-bound 真实浏览器完成自动显示、等待评分、暂停恢复、计时、上一卡信息、
  Marker/Tag/搁置/原文、键盘焦点、390px 触摸布局和减少动画验收并清理测试数据。
