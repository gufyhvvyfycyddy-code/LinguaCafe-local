param(
    [Parameter(Mandatory = $true)]
    [string] $ProjectDir,

    [Parameter(Mandatory = $true)]
    [string] $TokenizerScript,

    [Parameter(Mandatory = $true)]
    [string] $PythonExe,

    [Parameter(Mandatory = $true)]
    [string] $TokenizerUrl,

    [Parameter(Mandatory = $true)]
    [string] $RuntimeDir
)

$ErrorActionPreference = 'Stop'

function Test-TokenizerHealth {
    try {
        $response = Invoke-WebRequest -Uri ($TokenizerUrl.TrimEnd('/') + '/models/list') -UseBasicParsing -TimeoutSec 2
        return $response.StatusCode -ge 200 -and $response.StatusCode -lt 300
    }
    catch {
        return $false
    }
}

$projectPath = (Resolve-Path -LiteralPath $ProjectDir).Path
$scriptPath = (Resolve-Path -LiteralPath $TokenizerScript).Path
$pythonPath = (Resolve-Path -LiteralPath $PythonExe).Path
$runtimePath = [System.IO.Path]::GetFullPath($RuntimeDir)
$uri = [Uri] $TokenizerUrl
$port = $uri.Port

New-Item -ItemType Directory -Path $runtimePath -Force | Out-Null
$stdoutPath = Join-Path $runtimePath 'tokenizer.stdout.log'
$stderrPath = Join-Path $runtimePath 'tokenizer.stderr.log'
$pidPath = Join-Path $runtimePath 'tokenizer.pid'
$runnerLogPath = Join-Path $runtimePath 'tokenizer.runner.log'

if (Test-TokenizerHealth) {
    Add-Content -LiteralPath $runnerLogPath -Value ('{0:o} Tokenizer already healthy; runner exiting.' -f (Get-Date))
    exit 0
}

$existingListener = Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue | Select-Object -First 1
if ($existingListener) {
    Add-Content -LiteralPath $runnerLogPath -Value ('{0:o} Port {1} occupied by PID {2}; runner refusing to start.' -f (Get-Date), $port, $existingListener.OwningProcess)
    exit 2
}

Remove-Item -LiteralPath $pidPath -Force -ErrorAction SilentlyContinue
$quotedScript = '"' + $scriptPath + '"'
$process = Start-Process `
    -FilePath $pythonPath `
    -ArgumentList $quotedScript `
    -WorkingDirectory $projectPath `
    -WindowStyle Hidden `
    -RedirectStandardOutput $stdoutPath `
    -RedirectStandardError $stderrPath `
    -PassThru

Set-Content -LiteralPath $pidPath -Value $process.Id -Encoding Ascii
Add-Content -LiteralPath $runnerLogPath -Value ('{0:o} Tokenizer started. PID={1}' -f (Get-Date), $process.Id)

$process.WaitForExit()
$exitCode = $process.ExitCode
Remove-Item -LiteralPath $pidPath -Force -ErrorAction SilentlyContinue
Add-Content -LiteralPath $runnerLogPath -Value ('{0:o} Tokenizer exited. PID={1} ExitCode={2}' -f (Get-Date), $process.Id, $exitCode)
exit $exitCode
