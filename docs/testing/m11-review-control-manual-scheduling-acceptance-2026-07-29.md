# M11 Review Control 与手动调度验收（2026-07-29）

## 结论

M11A–M11D 已完成并通过实现、回归与真实浏览器验收，状态为
`Accepted / Closed`。M11 没有新增第二套评分或调度入口：正式评分仍只进入
`ReviewCardService` 与 `FsrsSchedulingService`；手动操作进入共享 operation
ledger，reset 保留旧 ReviewLog 并追加一条 reset 事实，其他手动操作不写
ReviewLog。

## 已验收范围

- `bury_next_day`、`suspend`、`resume`、`set_due`、`due_now` 与
  `reset_new(reset_counts)` 共用服务端 preview/apply；
- preview 返回复合状态与 fingerprint，apply 在锁内拒绝 stale state；
- operation/change 保存来源、动作 payload、前后复合快照与线性版本；
- Web 与 Mobile 复用同一 user/language/sense-only、幂等与 LIFO
  undo/redo 语义；
- Browser、Sense Reviewer 与 Card Info 使用统一手动操作入口；
- Card Info 显示 operation id、来源、前后状态，并提供撤销/重做；
- testing-only sentinel 端点仅在 `APP_ENV=testing` 注册，并同时证明
  testing 数据库与任务 sentinel。

## 自动验证

- M11 foundation：10 passed，98 assertions；
- M11 migration：1 passed，8 assertions；
- M11 UI guard：3 passed；
- M11 + Mobile operation/API + Card Info + lifecycle + Browser/Manage
  定向矩阵：419 passed、2 skipped、1,587 assertions；两个既有 over-limit
  慢测试保持 skip；
- `ReviewFsrsTest`、`FsrsSchedulingServiceTest` 与 WordSense 过滤矩阵通过；
- `npm run development` 通过，仅有既有 Sass deprecation；
- `git diff --check` 与相关 PHP 语法检查通过。

## 真实浏览器证据

验收前，同一 host/port 的 sentinel 返回：

```json
{"environment":"testing","database_is_testing":true,"sentinel_present":true}
```

官方 Browser 与官方 Chrome 均已先实际尝试；前者不能进入宿主机 localhost，
后者被扩展的 localhost 策略拦截。Chrome DevTools 通道创建了测试页，但其
selected-page 状态持续指向用户既有页面，因此为避免误触停止。最终按
ADR-0033 使用受控 Playwright，在一个任务专属页面与会话中完成：

1. 正常 UI 创建 testing 账号并登录；
2. 打开 `/review-cards/manage`；
3. 点击“立即到期”，观察服务器“当前 / 确认后”预览并确认；
4. 打开 Card Info → 历史，观察 Manual operation；
5. 通过页面按钮撤销，再通过页面按钮重做；
6. 观察最终状态恢复为 `applied`。

目标 Network 证据为 preview、apply、detail、undo、detail、redo、detail 共
7 个请求，全部 HTTP 200。数据库复核为：

```text
manual operations = 1
applied operations = 1
operation changes = 3
review logs = 0
```

Console 仅出现本地 Pusher `ws://127.0.0.1:6001` 连接失败；这是项目已有的
本地 fail-soft 边界，不影响 HTTP 操作。本地、gitignored 页面证据曾写入
`output/playwright/m11-manual-operation-history.png`；该路径只记录本地检查位置，
不属于仓库内可下载证据。

## 清理

- Playwright testing 页面与浏览器进程已关闭；
- task-only HTTP listener 已终止；
- testing sentinel、临时用户、WordSense、ReviewCard、operation/change
  与关联日志均复核为 0；
- 运行时随机密码只存在于浏览器脚本内存，未写入仓库、命令参数、日志、
  截图或密码库。
