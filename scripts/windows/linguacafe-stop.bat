@echo off
setlocal
call "%~dp0gpt-workflow-config.bat"

echo [LinguaCafe] Stopping Laravel server on port 8000 for this project...
echo [LinguaCafe] This only stops php.exe processes listening on 127.0.0.1:8000 and pointing at this project.
powershell -NoProfile -ExecutionPolicy Bypass -Command "$project=(Resolve-Path '%PROJECT_DIR%').Path; $total=0; for ($i=0; $i -lt 5; $i++) { $connections=Get-NetTCPConnection -LocalPort 8000 -State Listen -ErrorAction SilentlyContinue; if (-not $connections) { break }; $stoppedThisPass=0; foreach ($connection in $connections) { $process=Get-CimInstance Win32_Process -Filter ('ProcessId=' + $connection.OwningProcess) -ErrorAction SilentlyContinue; if ($process -and $process.Name -eq 'php.exe' -and $process.CommandLine -like '*127.0.0.1:8000*' -and $process.CommandLine -like ('*' + $project + '*')) { Stop-Process -Id $connection.OwningProcess -Force; Write-Host ('[LinguaCafe] Stopped process ' + $connection.OwningProcess); $stoppedThisPass++; $total++ } }; if ($stoppedThisPass -eq 0) { break }; Start-Sleep -Milliseconds 300 }; $remaining=Get-NetTCPConnection -LocalPort 8000 -State Listen -ErrorAction SilentlyContinue; if ($total -eq 0 -and -not $remaining) { Write-Host '[LinguaCafe] Nothing is listening on port 8000.'; exit 0 }; if ($remaining) { Write-Host '[LinguaCafe] Port 8000 is still in use. It may not be this project server.'; exit 1 }; exit 0"

if errorlevel 1 (
    pause
    exit /b 1
)

pause
endlocal
