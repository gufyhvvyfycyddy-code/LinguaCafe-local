# M17 Review Experience 与 Accessibility V2（Web）验收

> Status: Accepted / Web Slice Closed
> Date: 2026-08-01
> Architecture: ADR-0049

## Accepted scope

`/reviews/senses` 现在执行 M13 的体验设置，并提供卡片/会话计时、显式自动推进、
Previous Card Info、Marker/Tag/暂停/搁置/原文快速操作，以及字体、高对比度、减少
动画、键盘、触摸、焦点和读屏支持。问题计时到期只显示答案；答案计时到期只暂停
并等待人工评分。计时器没有评分能力，也不改变 FSRS 或 ReviewLog 语义。

iOS 相关共享插件、safe-area CSS 与隐私清单由 M9 负责记录和验收；Xcode 编译、
签名和 iOS 设备回调/通知证据仍属于 M9 capability cluster，不得由 Android 证据
代替。Android 原生触觉/通知由 M7 设备路径负责；本报告只关闭 M17 Web slice，
不会借跨里程碑设备证据扩展 ADR-0049 的范围。

## Automated and build evidence

- M17 timer state-machine test passed。
- M17 UI guard passed，锁定无自动评分、44px 触控目标、受控快速操作、
  `aria-live`、高对比度与减少动画。
- M17/SenseReview/ReviewFsrs/FsrsScheduling/ReviewCardManage/WordSense protected
  matrix: 1,070 passed, 2 skipped, 4,469 assertions。
- PHP syntax、`npm run development` 与 `git diff --check` passed。

## Server-bound real-browser evidence

写入前，`127.0.0.1:8792` 与其监听进程返回：

```json
{"environment":"testing","database_is_testing":true,"sentinel_present":true}
```

官方 OpenAI in-app Browser 与 Chrome 均先被真实调用并成功渲染 setup 页面；首个
验收服务器继承了 testing 的 `SESSION_DRIVER=array`，因此两个官方通道和独立
Playwright 都一致在正常表单 POST 上得到 CSRF mismatch。诊断确认这是服务器会话
配置而非 M17 业务行为。该批页面按生命周期关闭后，验收服务器改用
`SESSION_DRIVER=file` 并在新端口重新取得同源 sentinel；随后授权的 Playwright
fallback 使用系统 Chrome、一个 context 和一个 page 完成全部操作并关闭三者。

真实页面与普通 DOM/用户事件证明：

1. 任务专属 testing 账号通过正常 setup/login 表单创建并登录；
2. 问题 2 秒到期只显示答案，答案 2 秒到期显示“等待人工评分”提示；两个超时后
   ReviewLog 仍为 0，人工点击“记得”后才产生唯一 POST、唯一 ReviewLog 与下一卡；
3. 自动 reveal 后焦点进入第一个评分按钮，下一卡焦点进入“显示答案”；Space 与
   数字 3 快捷键均通过真实键盘事件工作；
4. Previous Card Info 显示评分、答题用时、下一到期、状态、稳定度/难度与次数；
5. 暂停/继续可观察；实测发现并修复答案超时暂停原因不能一次恢复的问题，修复后
   单击“继续计时”即恢复为“暂停”按钮且无评分写入；
6. Marker 改为红色；Tag 添加和移除各产生一个 200；原文按钮产生 200 并显示安全
   fallback；搁置通过既有 preview/apply 各一个 200，卡片进入 `buried` 并离开队列；
7. 字号由 20px 改为 22px，高对比度与减少动画切换后刷新仍保持；
8. 390×844 下 viewport/document/body 宽度均为 390px，无横向溢出。首次实测发现
   slot 与 icon 按钮小于 44px；修复后工具条全部按钮最小宽高均为 44px。

本地、gitignored 窄屏截图曾写入
`output/m17-acceptance/m17-mobile.png`；该路径只记录本地检查位置，不属于仓库内
可下载证据。Console 仅有项目已知的本地
Pusher/WebSocket 降级；HTTP 异常仅有 setup/login 阶段的两次未认证 font 401，
最终 rating、Tag、source、manual-operation preview/apply 均为 200。

## Cleanup

两个任务账号及其 goals、settings/preset bindings、WordSense、ReviewCard、
ReviewLog、Tag assignments、state events 和 operation 已精确清理；任务 sentinel、
临时日志和服务器均已移除。复核为任务账号 0、任务 WordSense 0、sentinel 0、
端口 8792 无 listener；自动化拥有的 page/context/browser 已关闭。本地验收截图
保留在仓库外的 gitignored 输出目录。

## Cross-milestone Android evidence（由 M7 负责）

以下事实只说明 M7 设备路径已验证共享体验能力，不属于 ADR-0049 Web slice 的验收
范围，也不改变本报告的 Web-only 关闭状态。

- 安装包使用官方 `@capacitor/haptics@8.0.1` 与
  `@capacitor/local-notifications@8.0.1`；Capacitor sync 和 Gradle debug build
  均通过。
- 真实评分按钮把 action 先持久化到本地队列，再调用一次 Haptics
  `impact(MEDIUM)`；Android logcat 记录了该插件 callback。MuMu 没有 vibrator
  service，因此不声称物理震感，但插件调用失败不会阻断或重复评分。
- Settings 的真实 hour select 与“保存提醒”按钮建立了 18:00 本地提醒；Android
  `dumpsys alarm` 记录 `TimedNotificationPublisher` 的 `RTC_WAKEUP`，通知记录存在。
- Android task 用户、评分/操作、通知所属 app data、APK、端口和模拟器均已精确清理。
