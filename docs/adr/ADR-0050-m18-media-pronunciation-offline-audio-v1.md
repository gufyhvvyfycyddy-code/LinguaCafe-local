# ADR-0050 — M18 Media、发音与离线音频完整性 V1

- Status: Accepted — implementation, Web and Android slices closed; iOS follows M9
- Date: 2026-08-01
- Milestone: M18

## Context

M18 需要给 WordSense 与例句提供跨 Web/移动端的发音和用户音频，同时保持云端
权威、用户隔离、有限离线和 M6 文件安全。当前系统只有浏览器 Web Speech TTS，
没有媒体数据模型；M16 `.lcpkg` 已是用户级可移植包，M6 系统备份则是数据库转储。

Anki 官方行为作为成熟参考：导入媒体时复制真实文件而不依赖符号链接；Check
Media 报告缺失引用与未使用文件；导出媒体必须显式选择；媒体变更跨设备合并，删除
应保守传播。LinguaCafe 不复制 Anki 的通用 Note/模板或文件夹协议，而把这些行为
映射到 WordSense、云端 manifest 和受控离线缓存。

## Decision

1. 新增 `media_assets` 与 `media_references`。Asset 是用户/语言范围内的不可变
   SHA-256 内容对象；Reference 只允许 `word_pronunciation`、`example_audio` 两个角色，
   且同一 WordSense/角色最多一个活动引用。
2. V1 只接受真实 MIME 为 MP3 或 M4A 的用户上传，每文件最多 10 MiB；默认每用户
   活动媒体配额 200 MiB。原文件名仅作显示元数据，磁盘文件名只由哈希和规范扩展名
   生成，存储为用户目录的直接子文件，不跟随符号链接。
3. Upload/replace 先校验用户、语言、格式、哈希与配额，再原子写文件和事务写元数据。
   相同内容在同一用户/语言内复用 Asset。替换或移除引用不会立即删除二进制；无活动
   引用的 Asset 进入 30 天保留期，可由同哈希重新上传恢复。
4. Web manifest 和移动 manifest 共享同一序列化语义：稳定 UUID、角色、SHA-256、
   MIME、字节数、来源/版权说明、下载 URL。下载必须经过当前用户/语言授权，返回
   private cache headers、ETag，并由框架处理 Range 请求。
5. Check Media 是只读完整性报告，至少列出缺失文件、孤立 Asset、重复内容组和不兼容
   格式；V1 不从该报告直接物理删除文件。
6. Web 复习卡优先播放用户音频；无附件时使用现有浏览器 Web Speech TTS，失败可见且
   不影响评分。上传/替换/移除只走 M18 Controller/Service，不写 ReviewCard、ReviewLog
   或 FSRS。
7. 移动短期复习包在 `display.media` additive 返回引用。客户端按需下载到 IndexedDB，
   下载后验证 SHA-256，以最近使用时间执行 50 MiB LRU 淘汰；离线时只播放已缓存对象。
8. `.lcpkg` 新增显式 `include_media`，默认 false。选中时包内包含 `media.json` 与
   `media/<sha>.<ext>`，导入预览先校验清单、路径、大小与哈希，再随事务恢复引用。
   M6 数据库备份自然覆盖媒体元数据，但不改变其数据库转储格式；媒体二进制的便携
   备份/恢复由 `.lcpkg` 负责。

## Scope

Allowed:

- M18 migration、MediaAsset/MediaReference model、媒体 Service/Controller/config；
- SenseReview 与 M16 Portable Data 的 additive media seam；
- 移动 review package、API client、IndexedDB cache 和 review playback；
- M18 targeted tests、ADR、plan、acceptance/status docs。

Forbidden:

- 视频、通用附件系统、AI/付费 TTS provider、外发或密钥；
- ReviewCard/ReviewLog/FSRS 写入或评分语义变化；
- M6 数据库备份格式重写、真实数据 migration/回填/物理删除；
- M7/M9 物理设备验收的伪完成。

## Verification

- migration/model/service/API 正常与拒绝路径：MIME、配额、遍历/符号链接、隔离、哈希、
  replace/remove retention、Check Media、download headers；
- SenseReview 与 mobile package additive contract、无 ReviewLog/FSRS 写入；
- `.lcpkg` include/exclude、篡改/超限/路径拒绝与恢复；
- mobile cache hash/LRU/offline tests、frontend/mobile build；
- testing-bound 真实浏览器完成 TTS fallback、上传/播放/移除、可见 replace action、
  Check Media 与 include-media opt-in；replace 的数据语义由自动化验证，并清理测试数据和文件。
