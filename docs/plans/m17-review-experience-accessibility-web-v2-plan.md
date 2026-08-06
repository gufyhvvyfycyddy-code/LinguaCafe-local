# M17 Review Experience 与 Accessibility V2 — Web 实施计划

> Status: Accepted / Web Slice Closed
> Architecture: ADR-0049

## Frozen slice

目标是在 `/reviews/senses` 执行 M13 体验设置，并补齐 Web 的计时、自动显示、上一张
卡、快速操作和无障碍闭环。明确不做原生触觉/通知、自动评分、旧 reviewer 重构、
M18 media。

### Responsibility and data flow

1. `ReviewSettingsResolver` 解析当前用户/语言的 M13 `experience`，Controller 只做
   additive 返回。
2. 纯 JS timer state machine 持有 session/card/phase elapsed 与 pause reasons；
   `SenseReviewExperienceController.vue` 负责生命周期、可见性、overlay/busy 暂停与
   体验偏好，`SenseReview.vue` 只保留正式评分和页面级对话框协调。
3. 体验工具条只通过 props/events 渲染；正式评分仍由 `SenseReview.vue::rate` 调用
   原有 API。
4. Marker、Tag、bury、source 全部复用现有写入/读取入口；Previous Card Info 只读
   最近成功评分响应。

### Allowlist

- `app/Http/Controllers/SenseReviewController.php`
- `resources/js/components/Senses/SenseReview.vue`
- `resources/js/components/Senses/SenseStudyCard.vue`
- `resources/js/components/Senses/SenseReviewRatingControls.vue`
- M17 新增 `resources/js/components/Senses/*` 与 `resources/js/components/Review/*`
- `resources/js/components/ReviewCards/WordSenseTagBulkPicker.vue`（仅 additive label）
- M17 tests 与 M17/roadmap/index/handoff 文档

所有其他文件禁止修改；新增文件必须直接服务上述责任。

## Acceptance

- 自动推进必须由用户在会话中启动；问题到时显示答案，答案到时等待人工评分。
- 卡片/会话计时在隐藏和暂停期间不增长。
- 评分成功后下一卡出现且 Previous Card Info 可读；失败恢复仍保持原语义。
- Marker、Tag、bury、source 使用现有隔离和写入入口。
- Space、1–4、Ctrl/Cmd+Z 不回归；自动 reveal 和下一卡有可观察焦点。
- 390px 无横向溢出，主要触摸目标至少 44px；高对比度与减少动画可切换。
- 自动、受保护与真实浏览器证据全部通过后才将 Web 切片标为 Closed；原生 M17
  单独随设备路径验收。

## Closure

Web 切片已于 2026-08-01 关闭。自动化、构建、server-bound 真实页面、390px
触控测量和清理审计均通过；证据见
`docs/testing/m17-review-experience-accessibility-web-v2-acceptance-2026-08-01.md`。
Android 原生触觉/通知已随 M7 设备路径关闭，iOS 仍随 M9；两者不改变本计划
Web 切片的范围或完成状态。
