using System.Diagnostics;
using System.IO;
using System.IO.Compression;
using System.Net.Http;
using System.Security.Cryptography;
using System.Text.Json;

namespace LinguaCafe.PcTest;

public sealed class RuntimeManager
{
    public const string BaseUrl = "http://127.0.0.1:9391";

    private static readonly JsonSerializerOptions JsonOptions = new() { WriteIndented = true };
    private string runtimeImageTag = "dev";
    private readonly string appDataRoot = Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
        "LinguaCafePCTest");

    public string StatePath => Path.Combine(appDataRoot, "state.json");

    public PcTestState LoadOrCreateState()
    {
        Directory.CreateDirectory(appDataRoot);
        if (File.Exists(StatePath))
        {
            return JsonSerializer.Deserialize<PcTestState>(File.ReadAllText(StatePath), JsonOptions)
                ?? throw new InvalidOperationException("PC test state is invalid.");
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
        File.WriteAllText(StatePath, JsonSerializer.Serialize(state, JsonOptions));
    }

    public async Task<string> PrepareAsync(Action<string> report, CancellationToken cancellationToken)
    {
        report("正在检查 Docker Desktop…");
        await EnsureDockerAsync(cancellationToken);

        var runtimeRoot = ResolveRuntimeRoot();
        runtimeImageTag = ResolveRuntimeImageTag();
        var composeFile = Path.Combine(runtimeRoot, "desktop", "windows-pc-test", "docker-compose.pc-test.yml");
        if (!File.Exists(composeFile))
        {
            throw new FileNotFoundException("PC test compose file is missing.", composeFile);
        }

        var common = new[]
        {
            "compose", "-p", "linguacafe-pc-test", "-f", composeFile,
        };

        var canReusePackagedImages = runtimeImageTag != "dev"
            && await DockerImageExistsAsync($"linguacafe-pc-test-web:{runtimeImageTag}", cancellationToken)
            && await DockerImageExistsAsync($"linguacafe-pc-test-python:{runtimeImageTag}", cancellationToken);
        if (canReusePackagedImages)
        {
            report($"正在复用 PC 测试运行时 {runtimeImageTag[..12]}…");
        }
        else
        {
            report("首次运行或版本更新：正在构建 LinguaCafe PC 测试运行时…");
            await RunDockerAsync([.. common, "build", "web", "python"], runtimeRoot, cancellationToken);
        }

        report("正在启动独立 MySQL / Redis / tokenizer…");
        await RunDockerAsync([.. common, "up", "-d", "--wait", "--wait-timeout", "120", "mysql", "redis", "python"], runtimeRoot, cancellationToken);

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
            return "dev";
        }

        var version = File.ReadAllText(versionFile).Trim();
        if (version.Length < 7 || version.Length > 64 || !version.All(Uri.IsHexDigit))
        {
            throw new InvalidOperationException("PC test runtime version marker is invalid.");
        }

        return version.ToLowerInvariant();
    }

    private static async Task<bool> DockerImageExistsAsync(string image, CancellationToken cancellationToken)
    {
        var result = await RunProcessAsync(
            "docker",
            ["image", "inspect", image],
            null,
            cancellationToken,
            throwOnFailure: false);
        return result.ExitCode == 0;
    }

    private string ResolveRuntimeRoot()
    {
        var packagedZip = Path.Combine(AppContext.BaseDirectory, "runtime-source.zip");
        if (File.Exists(packagedZip))
        {
            var versionFile = Path.Combine(AppContext.BaseDirectory, "runtime-version.txt");
            var version = File.Exists(versionFile)
                ? File.ReadAllText(versionFile).Trim()
                : File.GetLastWriteTimeUtc(packagedZip).Ticks.ToString();
            var target = Path.Combine(appDataRoot, "runtime", version);
            var marker = Path.Combine(target, ".pc-test-runtime-ready");
            if (!File.Exists(marker))
            {
                if (Directory.Exists(target))
                {
                    Directory.Delete(target, recursive: true);
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

    private static async Task WaitForHttpAsync(string url, CancellationToken cancellationToken)
    {
        using var http = new HttpClient { Timeout = TimeSpan.FromSeconds(4) };
        var deadline = DateTime.UtcNow.AddMinutes(2);
        while (DateTime.UtcNow < deadline)
        {
            cancellationToken.ThrowIfCancellationRequested();
            try
            {
                using var response = await http.GetAsync(url, cancellationToken);
                if ((int)response.StatusCode < 500)
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

        throw new TimeoutException("LinguaCafe PC test server did not become ready within two minutes.");
    }

    private sealed record ProcessResult(int ExitCode, string StandardOutput, string StandardError);
}
