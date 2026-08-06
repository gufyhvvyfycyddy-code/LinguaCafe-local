@echo off
setlocal
call "%~dp0gpt-workflow-config.bat"

echo [LinguaCafe] Stopping Python tokenizer on port %TOKENIZER_PORT%...
powershell -NoProfile -ExecutionPolicy Bypass -Command "$taskName='LinguaCafeTokenizerStarter'; $task=Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue; if ($task -and $task.State -eq 'Running') { Stop-ScheduledTask -TaskName $taskName; Write-Host ('[LinguaCafe] Scheduled task stopped: ' + $taskName) }; Start-Sleep -Milliseconds 500; $script=(Resolve-Path '%TOKENIZER_SCRIPT%' -ErrorAction SilentlyContinue).Path; $connections=@(Get-NetTCPConnection -LocalPort %TOKENIZER_PORT% -State Listen -ErrorAction SilentlyContinue); if ($connections.Count -eq 0) { Write-Host '[LinguaCafe] No tokenizer listener found.'; Remove-Item -LiteralPath '%TOKENIZER_RUNTIME_DIR%\tokenizer.pid' -Force -ErrorAction SilentlyContinue; exit 0 }; $stopped=0; foreach ($connection in $connections) { $process=Get-CimInstance Win32_Process -Filter ('ProcessId=' + $connection.OwningProcess) -ErrorAction SilentlyContinue; $matches=$process -and $process.Name -match '^python(w)?\.exe$' -and (($script -and $process.CommandLine -like ('*' + $script + '*')) -or $process.CommandLine -like '*tools\tokenizer.py*' -or $process.CommandLine -like '*tools/tokenizer.py*'); if ($matches) { Stop-Process -Id $connection.OwningProcess -Force -ErrorAction Stop; Write-Host ('[LinguaCafe] Tokenizer stopped. PID=' + $connection.OwningProcess); $stopped++ } else { Write-Host ('[LinguaCafe] Port %TOKENIZER_PORT% is used by a non-LinguaCafe process. PID=' + $connection.OwningProcess); exit 1 } }; Remove-Item -LiteralPath '%TOKENIZER_RUNTIME_DIR%\tokenizer.pid' -Force -ErrorAction SilentlyContinue; if ($stopped -gt 0) { exit 0 }; exit 1"

if errorlevel 1 (
    if /i not "%~1"=="--no-pause" pause
    exit /b 1
)

if /i not "%~1"=="--no-pause" pause
endlocal
exit /b 0
