# M18 Media、发音与离线音频完整性 V1 — 执行计划

- Status: Accepted / Implementation, Web and Android Slices Closed; iOS follows M9
- Authority: ADR-0050
- Goal: 完成 M18 的服务端媒体完整性、Web 发音、移动离线音频和可选便携包闭环。

## Frozen boundaries

- 唯一数据责任：WordSense 的词发音与例句音频；不建设通用附件/视频/TTS provider。
- 唯一二进制写入：`MediaAssetService`；Controller 不直接写磁盘或媒体表。
- 唯一删除语义：移除引用并保留孤立 Asset 30 天；本切片不物理清除。
- 兼容：所有既有 API/包默认行为不变，media 字段和 include-media 都是 additive/opt-in。
- 允许文件：ADR-0050 Scope 所列模块及其精确 route/test/doc integration files。
- 禁止文件：FSRS scheduler、正式评分入口、AI provider、M6 restore engine、真实数据。

## Slices

1. 服务端地基：migration/model/config/storage/service、Web/mobile manifest/download、
   upload/replace/remove、Check Media。
2. 消费端：SenseReview 播放与 TTS fallback、上传管理；mobile package media projection、
   IndexedDB SHA-256/LRU cache 与离线播放。
3. 可移植数据：`.lcpkg` 显式 include-media、预览校验和恢复。
4. 验证与收口：targeted/protected tests、build、testing-bound 真实浏览器、文档审计。

## Success / failure

- Success: 所有切片验证通过，用户/语言隔离和无评分写入证据成立，浏览器流程可复验。
- Failure: 任一越权下载、未验证哈希的缓存/导入、隐式包含媒体、直接物理删除或 FSRS/
  ReviewLog 副作用均阻止 M18 关闭。
