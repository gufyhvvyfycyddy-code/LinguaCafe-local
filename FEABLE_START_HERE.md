# Feable 接管入口

本仓库是 LinguaCafe 当前源码仓。Feable 接管前请先完整阅读：

- `docs/handovers/FEABLE_HANDOFF_2026-09-08.md`

GitHub：
- Source: https://github.com/gufyhvvyfycyddy-code/LinguaCafe-local
- Architecture review: https://github.com/gufyhvvyfycyddy-code/LinguaCafe-architecture-review
- Product / launch: https://github.com/gufyhvvyfycyddy-code/LinguaCafe-product-launch

接管原则：
1. 以 GitHub 最新 master、当前源码、当前测试和真实运行证据为准，不把交接里的数字当永久事实。
2. 可以继续主线开发、修 bug、清理代码和做架构优化。
3. 架构优化必须服务真实问题：重复 owner、多套真相、难测试、接口边界混乱、真实性能/可靠性问题。不要因为文件大或“看起来旧”就大重构。
4. 不改变冻结的产品语义：WordSense 是正式学习内容；sense-level ReviewCard + FSRS 是正式调度对象；ReviewLog 只记录真实评分/已冻结审计动作；AI 不默认自动写正式学习数据。
5. 页面流程必须真实浏览器验收；Android/iOS 要区分 build 与真实设备/商店验收。
6. 不读取或提交 .env / secrets；不要把本地测试账号、密码、签名材料、上传密钥写入仓库。
7. 任何外部 Gate（Apple / Google Play / 服务器 / 真实用户 / 隐私政策）都要明确标记，不得伪造通过。
