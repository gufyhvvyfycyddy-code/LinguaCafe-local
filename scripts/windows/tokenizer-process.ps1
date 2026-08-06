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
    [string] $RuntimeDir,

    [int] $StartupTimeoutSeconds = 30
)

$ErrorActionPreference = 'Stop'
$TaskName = 'LinguaCafeTokenizerStarter'

function Test-TokenizerHealth {
    param([string] $Url)

    try {
        $response = Invoke-WebRequest -Uri ($Url.TrimEnd('/') + '/models/list') -UseBasicParsing -TimeoutSec 2
        return $response.StatusCode -ge 200 -and $response.StatusCode -lt 300
    }
    catch {
        return $false
    }
}

function Quote-TaskArgument {
    param([string] $Value)
    return '"' + ($Value -replace '"', '""') + '"'
}

function Write-LogTail {
    param([string] $Path)

    if (-not (Test-Path -LiteralPath $Path)) {
        return
    }

    Get-Content -LiteralPath $Path -Tail 20 -ErrorAction SilentlyContinue | ForEach-Object {
        Write-Host ('  ' + $_)
    }
}

$projectPath = (Resolve-Path -LiteralPath $ProjectDir).Path
$scriptPath = (Resolve-Path -LiteralPath $TokenizerScript).Path
$pythonPath = (Resolve-Path -LiteralPath $PythonExe).Path
$runtimePath = [System.IO.Path]::GetFullPath($RuntimeDir)
$runnerPath = Join-Path $projectPath 'scripts\windows\tokenizer-task-runner.ps1'
$runnerPath = (Resolve-Path -LiteralPath $runnerPath).Path
$uri = [Uri] $TokenizerUrl
$port = $uri.Port

if (Test-TokenizerHealth -Url $TokenizerUrl) {
    Write-Host '[LinguaCafe] Tokenizer is already healthy.'
    exit 0
}

$existingListener = Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue | Select-Object -First 1
if ($existingListener) {
    $existingProcess = Get-CimInstance Win32_Process -Filter ('ProcessId=' + $existingListener.OwningProcess) -ErrorAction SilentlyContinue
    $description = if ($existingProcess) {
        '{0} PID={1}' -f $existingProcess.Name, $existingListener.OwningProcess
    }
    else {
        'PID=' + $existingListener.OwningProcess
    }
    throw "Tokenizer health check failed, but port $port is already occupied by $description."
}

New-Item -ItemType Directory -Path $runtimePath -Force | Out-Null
$stdoutPath = Join-Path $runtimePath 'tokenizer.stdout.log'
$stderrPath = Join-Path $runtimePath 'tokenizer.stderr.log'
$runnerLogPath = Join-Path $runtimePath 'tokenizer.runner.log'

$taskArguments = @(
    '-NoProfile',
    '-ExecutionPolicy', 'Bypass',
    '-WindowStyle', 'Hidden',
    '-File', (Quote-TaskArgument $runnerPath),
    '-ProjectDir', (Quote-TaskArgument $projectPath),
    '-TokenizerScript', (Quote-TaskArgument $scriptPath),
    '-PythonExe', (Quote-TaskArgument $pythonPath),
    '-TokenizerUrl', (Quote-TaskArgument $TokenizerUrl),
    '-RuntimeDir', (Quote-TaskArgument $runtimePath)
) -join ' '

$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument $taskArguments
$trigger = New-ScheduledTaskTrigger -AtLogOn -User $env:USERNAME
$principal = New-ScheduledTaskPrincipal -UserId ([System.Security.Principal.WindowsIdentity]::GetCurrent().Name) -LogonType Interactive -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet `
    -StartWhenAvailable `
    -MultipleInstances IgnoreNew `
    -RestartCount 3 `
    -RestartInterval (New-TimeSpan -Minutes 1) `
    -ExecutionTimeLimit ([TimeSpan]::Zero)

try {
    Register-ScheduledTask `
        -TaskName $TaskName `
        -Action $action `
        -Trigger $trigger `
        -Principal $principal `
        -Settings $settings `
        -Description 'Starts the LinguaCafe Python tokenizer and keeps its lifecycle outside the caller session.' `
        -Force | Out-Null
}
catch {
    Write-Host ('[LinguaCafe] Failed to register scheduled task {0}: {1}' -f $TaskName, $_.Exception.Message)
    exit 1
}

Start-ScheduledTask -TaskName $TaskName

$deadline = (Get-Date).AddSeconds($StartupTimeoutSeconds)
while ((Get-Date) -lt $deadline) {
    Start-Sleep -Milliseconds 500

    if (Test-TokenizerHealth -Url $TokenizerUrl) {
        $listener = Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue | Select-Object -First 1
        $listenerPid = if ($listener) { $listener.OwningProcess } else { $null }
        Write-Host ('[LinguaCafe] Tokenizer is healthy. PID={0}' -f $listenerPid)
        Write-Host ('[LinguaCafe] Scheduled task: {0}' -f $TaskName)
        Write-Host ('[LinguaCafe] Logs: {0}' -f $runtimePath)
        exit 0
    }

    $task = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
    $taskInfo = Get-ScheduledTaskInfo -TaskName $TaskName -ErrorAction SilentlyContinue
    if ($task -and $task.State -eq 'Ready' -and $taskInfo.LastTaskResult -ne 267009) {
        Write-Host ('[LinguaCafe] Tokenizer task stopped before health was ready. LastTaskResult={0}' -f $taskInfo.LastTaskResult)
        Write-Host '[LinguaCafe] stdout tail:'
        Write-LogTail -Path $stdoutPath
        Write-Host '[LinguaCafe] stderr tail:'
        Write-LogTail -Path $stderrPath
        Write-Host '[LinguaCafe] runner log tail:'
        Write-LogTail -Path $runnerLogPath
        exit 1
    }
}

Write-Host ('[LinguaCafe] Tokenizer did not become healthy within {0} seconds.' -f $StartupTimeoutSeconds)
Write-Host '[LinguaCafe] stdout tail:'
Write-LogTail -Path $stdoutPath
Write-Host '[LinguaCafe] stderr tail:'
Write-LogTail -Path $stderrPath
Write-Host '[LinguaCafe] runner log tail:'
Write-LogTail -Path $runnerLogPath
exit 1
