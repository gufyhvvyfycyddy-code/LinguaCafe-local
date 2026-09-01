using System.IO;
using System.Text.Json;
using System.Windows;
using Microsoft.Web.WebView2.Core;

namespace LinguaCafe.PcTest;

public partial class MainWindow : Window
{
    private readonly RuntimeManager runtime = new();
    private readonly string startupLogPath = Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
        "LinguaCafePCTest",
        "startup.log");
    private PcTestState state = null!;

    public MainWindow()
    {
        InitializeComponent();
        Loaded += async (_, _) => await StartAsync();
    }

    private async Task StartAsync()
    {
        try
        {
            state = runtime.LoadOrCreateState();
            File.WriteAllText(startupLogPath, $"{DateTimeOffset.Now:O} START{Environment.NewLine}");
            await runtime.PrepareAsync(UpdateStatus, CancellationToken.None);

            UpdateStatus("正在初始化桌面窗口…");
            var profile = Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
                "LinguaCafePCTest",
                "WebView2");
            var webViewEnvironment = await CoreWebView2Environment.CreateAsync(userDataFolder: profile);
            await Browser.EnsureCoreWebView2Async(webViewEnvironment);
            Browser.CoreWebView2.Settings.AreDevToolsEnabled = true;
            Browser.CoreWebView2.Settings.AreDefaultContextMenusEnabled = true;

            if (!state.AdminProvisioned)
            {
                await ProvisionAdminAsync();
            }

            UpdateStatus("正在进入管理员工作区…");
            await NavigateAsync($"{RuntimeManager.BaseUrl}/");
            if (CurrentPath() is "/login" or "/setup")
            {
                await AutoLoginAsync();
            }

            if (CurrentPath() == "/login")
            {
                throw new InvalidOperationException("PC 测试管理员自动登录失败。请删除本地 PC 测试数据后重试。" );
            }

            UpdateStatus($"PC 测试版已就绪：{CurrentPath()}");
            Browser.Visibility = Visibility.Visible;
            StartupOverlay.Visibility = Visibility.Collapsed;
        }
        catch (WebView2RuntimeNotFoundException)
        {
            ShowError("当前 Windows 缺少 Microsoft Edge WebView2 Runtime。请安装 Evergreen WebView2 Runtime 后重新启动 LinguaCafe PC Test。" );
        }
        catch (Exception exception)
        {
            File.AppendAllText(startupLogPath, $"{DateTimeOffset.Now:O} STACK {exception}{Environment.NewLine}");
            ShowError(exception.Message);
        }
    }

    private async Task ProvisionAdminAsync()
    {
        UpdateStatus("首次运行：正在创建专用 PC 测试管理员…");
        await NavigateAsync($"{RuntimeManager.BaseUrl}/setup");

        var email = JsonSerializer.Serialize(state.AdminEmail);
        var password = JsonSerializer.Serialize(state.AdminPassword);
        var script = $$"""
            (() => {
              const email = document.querySelector('input[autocomplete="email"]');
              const passwords = Array.from(document.querySelectorAll('input[type="password"]'));
              const button = Array.from(document.querySelectorAll('button')).find(b => (b.textContent || '').includes('创建账号'));
              const loginLink = document.querySelector('a[href="/login"]');
              if (!email && loginLink) {
                loginLink.click();
                return 'submitted';
              }
              if (!email || passwords.length < 2 || !button) return 'not-ready';
              const setValue = (element, value) => {
                const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set;
                setter.call(element, value);
                element.dispatchEvent(new Event('input', { bubbles: true }));
                element.dispatchEvent(new Event('change', { bubbles: true }));
              };
              if (email.value !== {{email}} || passwords[0].value !== {{password}} || passwords[1].value !== {{password}}) {
                setValue(email, {{email}});
                setValue(passwords[0], {{password}});
                setValue(passwords[1], {{password}});
                return 'filled';
              }
              if (button.disabled) return 'filled';
              button.click();
              return 'submitted';
            })();
            """;

        await WaitForScriptResultAsync(script, "submitted", TimeSpan.FromSeconds(30));
        await WaitForPathAsync("/login", TimeSpan.FromSeconds(30));
        state.AdminProvisioned = true;
        runtime.SaveState(state);
    }

    private async Task AutoLoginAsync()
    {
        if (CurrentPath() != "/login")
        {
            await NavigateAsync($"{RuntimeManager.BaseUrl}/login");
        }

        var email = JsonSerializer.Serialize(state.AdminEmail);
        var password = JsonSerializer.Serialize(state.AdminPassword);
        var script = $$"""
            (() => {
              const email = document.querySelector('input[name="linguacafe-email"]');
              const password = document.querySelector('input[name="linguacafe-password"]');
              const button = Array.from(document.querySelectorAll('button')).find(b => (b.textContent || '').trim() === '登录');
              if (!email || !password || !button) return 'not-ready';
              const setValue = (element, value) => {
                const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set;
                setter.call(element, value);
                element.dispatchEvent(new Event('input', { bubbles: true }));
                element.dispatchEvent(new Event('change', { bubbles: true }));
              };
              if (email.value !== {{email}} || password.value !== {{password}}) {
                setValue(email, {{email}});
                setValue(password, {{password}});
                return 'filled';
              }
              if (button.disabled) return 'filled';
              button.click();
              return 'submitted';
            })();
            """;

        await WaitForScriptResultAsync(script, "submitted", TimeSpan.FromSeconds(30));
        await WaitForPathAsync("/", TimeSpan.FromSeconds(30));
    }

    private async Task NavigateAsync(string url)
    {
        var completion = new TaskCompletionSource<bool>(TaskCreationOptions.RunContinuationsAsynchronously);
        void Handler(object? sender, CoreWebView2NavigationCompletedEventArgs args)
        {
            Browser.NavigationCompleted -= Handler;
            if (args.IsSuccess)
            {
                completion.TrySetResult(true);
            }
            else
            {
                completion.TrySetException(new InvalidOperationException($"页面加载失败：{args.WebErrorStatus}"));
            }
        }

        Browser.NavigationCompleted += Handler;
        Browser.Source = new Uri(url);
        await completion.Task.WaitAsync(TimeSpan.FromSeconds(30));
    }

    private async Task WaitForPathAsync(string path, TimeSpan timeout)
    {
        var deadline = DateTime.UtcNow + timeout;
        while (DateTime.UtcNow < deadline)
        {
            if (CurrentPath() == path)
            {
                return;
            }
            await Task.Delay(250);
        }
        throw new TimeoutException($"等待页面 {path} 超时。" );
    }

    private async Task WaitForScriptResultAsync(string script, string expected, TimeSpan timeout)
    {
        var deadline = DateTime.UtcNow + timeout;
        while (DateTime.UtcNow < deadline)
        {
            var raw = await Browser.ExecuteScriptAsync(script);
            var result = JsonSerializer.Deserialize<string>(raw);
            if (result == expected)
            {
                return;
            }
            await Task.Delay(250);
        }
        throw new TimeoutException("桌面自动登录页面尚未准备完成。" );
    }

    private string CurrentPath()
    {
        return Browser.Source is null ? string.Empty : Browser.Source.AbsolutePath;
    }

    private void UpdateStatus(string text)
    {
        File.AppendAllText(startupLogPath, $"{DateTimeOffset.Now:O} {text}{Environment.NewLine}");
        Dispatcher.Invoke(() => StatusText.Text = text);
    }

    private void ShowError(string message)
    {
        File.AppendAllText(startupLogPath, $"{DateTimeOffset.Now:O} ERROR {message}{Environment.NewLine}");
        Dispatcher.Invoke(() =>
        {
            StatusText.Text = "LinguaCafe PC 测试版启动失败";
            ErrorText.Text = message;
            ErrorText.Visibility = Visibility.Visible;
            Browser.Visibility = Visibility.Hidden;
            StartupOverlay.Visibility = Visibility.Visible;
        });
    }
}
