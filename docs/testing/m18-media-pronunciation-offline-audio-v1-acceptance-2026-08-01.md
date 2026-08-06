# M18 Media、发音与离线音频完整性 V1 验收

> Status: Accepted / Implementation, Web and Android Slices Closed
> iOS shared implementation: accepted under M9; device evidence follows M9
> Date: 2026-08-01
> Architecture: ADR-0050

## Accepted scope

M18 现在提供用户/语言隔离的 MP3/M4A 内容寻址存储、WordSense 词发音与当前例句
引用、Web 播放与浏览器语音 fallback、移动 manifest 与 50 MiB SHA-256/LRU 离线缓存、
只读 Check Media，以及 `.lcpkg` 默认不包含、显式 opt-in 的媒体导出/恢复。上传、播放、
移除和完整性检查都不写 ReviewCard、ReviewLog 或 FSRS；同一用户的媒体写入由原子锁
串行化，配额检查不会被并发上传绕过。

设计参考了 Anki 官方的 [Media](https://docs.ankiweb.net/media.html)、
[Exporting](https://docs.ankiweb.net/exporting.html) 与
[Syncing](https://docs.ankiweb.net/syncing.html) 行为：Check Media 可见报告完整性问题、
导出媒体必须显式选择、真实文件按内容持久化。LinguaCafe 仍保持 WordSense、云端
manifest 和有限离线缓存边界，不复制通用 Note/模板或完整本地 collection 合并协议。

## Automated and build evidence

- `M18MediaIntegrity + M16PortableData`：24 passed，153 assertions。
- M18 media slot JS test passed；mobile media cache：2 passed。
- M18/M16/M17/SenseReview/ReviewFsrs/FsrsScheduling/ReviewCardManage/WordSense
  protected matrix：1,089 passed，2 skipped，4,610 assertions。
- 该矩阵首次运行发现旧序列化预算仍限定 4 次查询；M18 的批量媒体查询使固定预算
  变为 5。实现改为单条 `media_references JOIN media_assets`，测试明确锁定媒体查询最多
  1 次和总预算最多 5 次；1/10/100 卡均通过，无 N+1。
- PHP syntax、root `npm run development`、mobile TypeScript/Vite build 均 passed。

## Server-bound official-browser evidence

写入前，`127.0.0.1:8793` 的实际 testing 进程返回：

```json
{"environment":"testing","database_is_testing":true,"sentinel_present":true}
```

官方 OpenAI in-app Browser 复用一个测试页并通过正常 setup/login 表单建立任务专属
testing 身份。真实 DOM、文件选择器和用户事件证明：

1. 无附件时点击“词发音”，页面可见提示当前浏览器无英语语音并建议上传 MP3/M4A；
   ReviewLog 保持 0。
2. “管理音频 → 上传词发音”通过真实 file chooser 选择 ffmpeg 验证为 MP3 的 0.35 秒
   临时音频；保存后按钮变为“播放词发音附件”，页面显示“音频附件已保存”。
3. 点击附件后页面显示“正在播放词发音”，服务器观察到授权媒体 GET；数据库确认
   MIME 为 `audio/mpeg`、`last_accessed_at` 已写入且 ReviewLog 仍为 0。
4. 管理菜单可见“替换词发音”；replace/dedup 语义由 M18 自动化覆盖。真实页面点击
   “移除词发音”后恢复为 TTS fallback，引用为 0，Asset soft-delete 且
   `retained_until` 为 30 天后。
5. ReviewCardManage 的 Portable Data 面板可见默认未选中的 `.lcpkg` include-media，
   真实勾选后状态由 false 变为 true；“检查媒体”真实请求显示缺失 0、孤立文件 1、
   重复组 0、不兼容 0、未登记文件 0。
6. 390×844 下 document client/scroll width 均为 382px，无横向溢出；词发音与例句
   按钮高度均为 44px。viewport 已 reset。
7. 最终页面 Console 的 warn/error 集合为空。测试页由官方 browser lifecycle
   `finalize({keep: []})` 关闭，未留下既有用户页或自动化页。

## Android offline-audio evidence

The booted Android 12 emulator used the installed APK and a server-bound testing
fixture containing a valid 0.5-second 880 Hz MP3. The rendered `词发音` button:

1. downloaded and played the attachment online through official Capacitor HTTP;
2. left one entry in the 50 MiB `linguacafe-media-v1` IndexedDB cache;
3. after server shutdown, played the same attachment from the cached review
   package with one Android audio-focus acquire/release and **zero** native media
   HTTP requests;
4. remained available across the offline review workflow and app restarts.

This closes the Android cache-hit/playback check. Eviction remains covered by the
deterministic media-cache unit tests; the single small fixture did not pretend to
exercise a 50 MiB real-device pressure event.

## Cleanup

任务用户以及 user id 31 下的 goals、goal achievements、settings、WordSense、ReviewCard、
ReviewLog、media references/assets 均精确清零；testing sentinel、临时 MP3、存储二进制和
server job 均已移除。复核任务用户、子数据与 sentinel 都为 0。

Android task user id 544、media reference/asset、存储文件、ReviewCard/ReviewLog、
device/token、sentinel 与临时 MP3 均精确清零；APK 已卸载，MuMu 已关闭。M9 文档
记录了 iOS 官方 Capacitor 工程与同一媒体缓存实现；本报告未独立复验 Xcode/iOS
设备的缓存、Audio Session 与零网络播放，这些证据仍只属于 M9 capability cluster，
不影响本报告的 Android slice 关闭。
