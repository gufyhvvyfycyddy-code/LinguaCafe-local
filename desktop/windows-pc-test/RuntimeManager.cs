using System.Diagnostics;
using System.IO;
using System.IO.Compression;
using System.Net;
using System.Net.Http;
using System.Security.Cryptography;
using System.Text.Json;
using System.Text.RegularExpressions;

namespace LinguaCafe.PcTest;

public sealed class RuntimeManager
{
    public const string BaseUrl = "http://127.0.0.1:9397";

    private string runtimeImageTag = "dev";
    internal static readonly string DataRoot = Path.Combine(@"D:\AI-Tools", "LinguaCafe", "PC-Test-V5");
    private readonly string appDataRoot;
    private readonly string legacyStatePath;

    public RuntimeManager() : this(DataRoot, LegacyStatePath) { }

    // Inject only generated test paths. The normal desktop constructor keeps
    // the original private D-drive and C-drive legacy identity boundaries.
    internal RuntimeManager(string appDataRoot, string legacyStatePath)
    {
        this.appDataRoot = appDataRoot;
        this.legacyStatePath = legacyStatePath;
    }
    private static readonly string LegacyStatePath = Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
        "LinguaCafePCTestStudyV5", "state.json");

    public string StatePath => Path.Combine(appDataRoot, "state.json");

    public PcTestState LoadOrCreateState()
    {
        Directory.CreateDirectory(appDataRoot);
        if (File.Exists(StatePath))
        {
            return PcTestStatePersistence.Load(StatePath);
        }

        if (File.Exists(legacyStatePath))
        {
            throw new InvalidOperationException(
                "Existing PC test identity is still on C drive. Verify and copy its state.json to the D-drive PC Test folder before starting.");
        }

        var state = new PcTestState
        {
            AdminPassword = $"PcTest-{Convert.ToHexString(RandomNumberGenerator.GetBytes(10))}",
        };
        SaveState(state);
        return state;
    }

    public void SaveState(PcTestState state)
    {
        Directory.CreateDirectory(appDataRoot);
        PcTestStatePersistence.Save(StatePath, state);
    }

    public async Task<string> PrepareAsync(Action<string> report, CancellationToken cancellationToken)
    {
        report("正在检查 Docker Desktop…");
        await EnsureDockerAsync(cancellationToken);

        // Validate the packaged identity before it can be used as a directory name.
        runtimeImageTag = ResolveRuntimeImageTag();
        var runtimeRoot = ResolveRuntimeRoot();
        var composeFile = Path.Combine(runtimeRoot, "desktop", "windows-pc-test", "docker-compose.pc-test.yml");
        if (!File.Exists(composeFile))
        {
            throw new FileNotFoundException("PC test compose file is missing.", composeFile);
        }

        var common = new[]
        {
            "compose", "-p", "linguacafe-pc-test-study-v5", "-f", composeFile,
        };

        var webImage = $"linguacafe-pc-test-web:{runtimeImageTag}";
        var pythonImage = $"linguacafe-pc-test-python:{runtimeImageTag}";
        if (runtimeImageTag != "dev")
        {
            var manifest = Path.Combine(AppContext.BaseDirectory, "runtime-images.json");
            if (!File.Exists(manifest))
            {
                throw new InvalidOperationException("已打包的 PC 测试版缺少镜像验收记录。请重新暂存并验收该版本，不要删除学习数据。");
            }
            var (expectedWeb, expectedPython) = ParsePackagedImageIds(File.ReadAllText(manifest));
            if (!await DockerImageMatchesRevisionAsync(webImage, runtimeImageTag, expectedWeb, cancellationToken)
                || !await DockerImageMatchesRevisionAsync(pythonImage, runtimeImageTag, expectedPython, cancellationToken))
            {
                throw new InvalidOperationException("PC 测试版镜像已丢失或与验收记录不符。请重新暂存并验收该版本，不要删除学习数据。");
            }
            report($"正在复用已核镜像 {runtimeImageTag[..12]}…");
        }
        else
        {
            report("开发模式：正在构建 LinguaCafe PC 测试运行时…");
            await RunDockerAsync([.. common, "build", "web", "python"], runtimeRoot, cancellationToken);
        }

        report("正在启动独立 MySQL / Redis / tokenizer…");
        await RunDockerAsync([.. common, "up", "-d", "--wait", "--wait-timeout", "600", "mysql", "redis", "python"], runtimeRoot, cancellationToken);

        report("正在初始化 PC 测试数据库…");
        await RunDockerAsync([.. common, "run", "--rm", "web", "php", "artisan", "migrate", "--force"], runtimeRoot, cancellationToken);
        await RunDockerAsync([.. common, "run", "--rm", "web", "php", "artisan", "db:seed", "--force"], runtimeRoot, cancellationToken);

        report("正在检查 ECDICT 英汉词典…");
        var dictionaryStatus = await RunDockerAsync(
            [.. common, "run", "--rm", "web", "php", "artisan", "dictionary:import-ecdict", "--status"],
            runtimeRoot,
            cancellationToken,
            throwOnFailure: false);
        if (dictionaryStatus.ExitCode != 0)
        {
            var ecdict = ResolveEcdictCsv();
            if (ecdict is not null)
            {
                report("首次运行正在导入 ECDICT 英汉词典…");
                await RunDockerAsync(
                    [.. common, "run", "--rm", "web", "php", "artisan", "dictionary:import-ecdict", "--csv=/pc-test/ecdict.csv", "--batch=5000"],
                    runtimeRoot,
                    cancellationToken);
            }
        }

        report("正在启动 LinguaCafe PC 测试服务…");
        await RunDockerAsync([.. common, "up", "-d", "web"], runtimeRoot, cancellationToken);
        await WaitForHttpAsync($"{BaseUrl}/login", cancellationToken);
        return runtimeRoot;
    }

    private string ResolveRuntimeImageTag()
    {
        var versionFile = Path.Combine(AppContext.BaseDirectory, "runtime-version.txt");
        if (!File.Exists(versionFile))
        {
            if (File.Exists(Path.Combine(AppContext.BaseDirectory, "runtime-source.zip")))
            {
                throw new InvalidOperationException("PC test packaged source is missing its commit marker.");
            }
            return "dev";
        }

        if (!File.Exists(Path.Combine(AppContext.BaseDirectory, "runtime-source.zip")))
        {
            throw new InvalidOperationException("PC test commit marker has no packaged source archive.");
        }
        var version = File.ReadAllText(versionFile).Trim();
        if (version.Length != 40 || !version.All(Uri.IsHexDigit))
        {
            throw new InvalidOperationException("PC test runtime version marker is invalid.");
        }

        return version.ToLowerInvariant();
    }

    internal static (string Web, string Python) ParsePackagedImageIds(string json)
    {
        using var document = JsonDocument.Parse(json);
        static string ReadId(JsonElement root, string name)
        {
            if (root.ValueKind != JsonValueKind.Object || !root.TryGetProperty(name, out var value) ||
                value.ValueKind != JsonValueKind.String ||
                value.GetString() is not { } id || !Regex.IsMatch(id, @"\Asha256:[0-9a-f]{64}\z"))
            {
                throw new InvalidOperationException("PC test packaged image manifest is invalid.");
            }
            return id;
        }
        return (ReadId(document.RootElement, "web"), ReadId(document.RootElement, "python"));
    }

    private static async Task<bool> DockerImageMatchesRevisionAsync(string image, string revision, string expectedId, CancellationToken cancellationToken)
    {
        var id = await RunProcessAsync("docker", ["image", "inspect", "--format", "{{.Id}}", image],
            null, cancellationToken, throwOnFailure: false);
        if (id.ExitCode != 0 || id.StandardOutput.Trim() != expectedId)
        {
            return false;
        }
        var result = await RunProcessAsync(
            "docker",
            ["image", "inspect", "--format", "{{ index .Config.Labels \"org.opencontainers.image.revision\" }}", expectedId],
            null,
            cancellationToken,
            throwOnFailure: false);
        return result.ExitCode == 0 && result.StandardOutput.Trim() == revision;
    }

    private string ResolveRuntimeRoot()
    {
        var packagedZip = Path.Combine(AppContext.BaseDirectory, "runtime-source.zip");
        if (File.Exists(packagedZip))
        {
            var version = runtimeImageTag;
            var target = Path.Combine(appDataRoot, "runtime", version);
            var marker = Path.Combine(target, ".pc-test-runtime-ready");
            if (!File.Exists(marker))
            {
                if (Directory.Exists(target))
                {
                    throw new InvalidOperationException(
                        "PC test runtime directory exists without its ready marker. Verify the folder manually; refusing to delete unknown data.");
                }
                Directory.CreateDirectory(target);
                ZipFile.ExtractToDirectory(packagedZip, target);
                File.WriteAllText(marker, version);
            }
            return target;
        }

        foreach (var start in new[] { AppContext.BaseDirectory, Environment.CurrentDirectory })
        {
            var current = new DirectoryInfo(start);
            while (current is not null)
            {
                if (File.Exists(Path.Combine(current.FullName, "artisan"))
                    && File.Exists(Path.Combine(current.FullName, "desktop", "windows-pc-test", "docker-compose.pc-test.yml")))
                {
                    return current.FullName;
                }
                current = current.Parent;
            }
        }

        throw new InvalidOperationException("Cannot locate LinguaCafe PC test runtime source.");
    }

    private static string? ResolveEcdictCsv()
    {
        var path = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.DesktopDirectory),
            "linguacafe",
            "linguacafe_ecdict_en_zh_pipe.csv");
        return File.Exists(path) ? path : null;
    }

    private static async Task EnsureDockerAsync(CancellationToken cancellationToken)
    {
        var initial = await RunProcessAsync("docker", ["version", "--format", "{{.Server.Version}}"], null, cancellationToken, false);
        if (initial.ExitCode == 0)
        {
            return;
        }

        var dockerDesktop = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles), "Docker", "Docker", "Docker Desktop.exe");
        if (!File.Exists(dockerDesktop))
        {
            throw new InvalidOperationException("Docker Desktop is required for the PC test build.");
        }

        Process.Start(new ProcessStartInfo(dockerDesktop) { UseShellExecute = true });
        var deadline = DateTime.UtcNow.AddMinutes(2);
        while (DateTime.UtcNow < deadline)
        {
            cancellationToken.ThrowIfCancellationRequested();
            await Task.Delay(TimeSpan.FromSeconds(2), cancellationToken);
            var probe = await RunProcessAsync("docker", ["version", "--format", "{{.Server.Version}}"], null, cancellationToken, false);
            if (probe.ExitCode == 0)
            {
                return;
            }
        }

        throw new TimeoutException("Docker Desktop did not become ready within two minutes.");
    }

    private async Task<ProcessResult> RunDockerAsync(
        IReadOnlyList<string> args,
        string workingDirectory,
        CancellationToken cancellationToken,
        bool throwOnFailure = true)
    {
        var env = new Dictionary<string, string?>
        {
            ["PC_TEST_RUNTIME_VERSION"] = runtimeImageTag,
            ["COMPOSE_DISABLE_ENV_FILE"] = "1",
        };
        var ecdict = ResolveEcdictCsv();
        if (ecdict is not null)
        {
            env["PC_TEST_ECDICT_CSV"] = ecdict.Replace('\\', '/');
        }

        return await RunProcessAsync("docker", args, workingDirectory, cancellationToken, throwOnFailure, env);
    }

    private static async Task<ProcessResult> RunProcessAsync(
        string fileName,
        IReadOnlyList<string> args,
        string? workingDirectory,
        CancellationToken cancellationToken,
        bool throwOnFailure,
        IReadOnlyDictionary<string, string?>? environment = null)
    {
        var start = new ProcessStartInfo(fileName)
        {
            UseShellExecute = false,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            CreateNoWindow = true,
            WorkingDirectory = workingDirectory ?? Environment.CurrentDirectory,
        };
        foreach (var arg in args)
        {
            start.ArgumentList.Add(arg);
        }
        if (environment is not null)
        {
            foreach (var (key, value) in environment)
            {
                if (value is not null)
                {
                    start.Environment[key] = value;
                }
            }
        }

        using var process = Process.Start(start)
            ?? throw new InvalidOperationException($"Could not start {fileName}.");
        var stdoutTask = process.StandardOutput.ReadToEndAsync(cancellationToken);
        var stderrTask = process.StandardError.ReadToEndAsync(cancellationToken);
        await process.WaitForExitAsync(cancellationToken);
        var result = new ProcessResult(process.ExitCode, await stdoutTask, await stderrTask);
        if (throwOnFailure && result.ExitCode != 0)
        {
            var detail = string.IsNullOrWhiteSpace(result.StandardError) ? result.StandardOutput : result.StandardError;
            throw new InvalidOperationException($"{fileName} failed ({result.ExitCode}): {detail.Trim()}");
        }
        return result;
    }

    internal static bool IsLoginPageReady(HttpResponseMessage response) =>
        response.StatusCode == HttpStatusCode.OK &&
        string.Equals(response.Content.Headers.ContentType?.MediaType, "text/html", StringComparison.OrdinalIgnoreCase);

    private static async Task WaitForHttpAsync(string url, CancellationToken cancellationToken)
    {
        using var http = new HttpClient(new HttpClientHandler { AllowAutoRedirect = false })
        {
            Timeout = TimeSpan.FromSeconds(4),
        };
        var deadline = DateTime.UtcNow.AddMinutes(2);
        HttpStatusCode? lastStatus = null;
        while (DateTime.UtcNow < deadline)
        {
            cancellationToken.ThrowIfCancellationRequested();
            try
            {
                using var response = await http.GetAsync(url, cancellationToken);
                lastStatus = response.StatusCode;
                if (IsLoginPageReady(response))
                {
                    return;
                }
            }
            catch (HttpRequestException)
            {
            }
            catch (TaskCanceledException) when (!cancellationToken.IsCancellationRequested)
            {
            }

            await Task.Delay(TimeSpan.FromSeconds(2), cancellationToken);
        }

        var status = lastStatus is { } code ? $"HTTP {(int)code}" : "未收到 HTTP 响应";
        throw new TimeoutException($"LinguaCafe 登录页两分钟内未返回 HTTP 200 HTML（最后状态：{status}）。请保留本地数据并检查服务。 ");
    }

    private sealed record ProcessResult(int ExitCode, string StandardOutput, string StandardError);
}
