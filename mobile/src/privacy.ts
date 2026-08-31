export function mobilePrivacyPolicyHtml(): string {
  return `
    <details class="privacy-policy">
      <summary>隐私权政策与数据说明</summary>
      <div class="privacy-policy-copy">
        <h2>LinguaCafe 隐私权政策</h2>
        <p><strong>开发者/产品：</strong>LinguaCafe。隐私咨询请联系你所连接的 LinguaCafe 服务器运营者；正式应用商店商品页还会提供固定、公开的 HTTPS 隐私政策地址和对应咨询方式。</p>
        <p>LinguaCafe 移动端连接你选择的 LinguaCafe 服务器。应用不包含广告 SDK，也不用于跨应用或跨网站跟踪。</p>
        <p><strong>服务器处理的数据：</strong>账号名称和邮箱、随机设备标识和应用版本、你保存或导入的英语学习资料与词义、复习评分与学习进度、离线待同步操作、你主动使用的媒体，以及服务器正常运行所需的安全和诊断日志。这些数据用于登录、同步、阅读、复习、恢复和安全保护。</p>
        <p><strong>本机数据：</strong>服务器地址、随机设备标识、账号/语言范围、已下载的短期文章与复习包、待同步操作和缓存媒体。设备令牌由 Android Keystore 或 Apple Keychain 保护；密码不会保存在设备上。本地复习提醒是可选功能。</p>
        <p><strong>数据传输与分享：</strong>移动端把完成学习功能所需的数据发送到你选择的 LinguaCafe 服务器。移动端自身不出售用户数据，也不向广告网络分享数据。服务器运营者若启用了外部 AI、翻译、词典或其他服务，应在其公开隐私政策中说明相应的数据处理方和用途。</p>
        <p><strong>保留与删除：</strong>“撤销此设备并退出”会清除本机令牌、离线学习数据和缓存，并在服务器可访问时撤销该设备。永久删除服务器账号需在所连接服务器的 Web 账户设置中完成；历史备份中的数据按该服务器公开的备份保留政策处理。</p>
      </div>
    </details>`;
}
