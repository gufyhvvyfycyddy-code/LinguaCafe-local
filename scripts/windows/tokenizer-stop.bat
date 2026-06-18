@echo off
setlocal
call "%~dp0gpt-workflow-config.bat"

echo [LinguaCafe] Stopping Python tokenizer on port %TOKENIZER_PORT%...
powershell -NoProfile -ExecutionPolicy Bypass -Command "$conn = Get-NetTCPConnection -LocalPort %TOKENIZER_PORT% -State Listen -ErrorAction SilentlyContinue | Select-Object -First 1; if (!$conn) { Write-Host 'No tokenizer listener found on this port.'; exit 0 }; $p = Get-Process -Id $conn.OwningProcess -ErrorAction SilentlyContinue; if ($p -and ($p.ProcessName -match 'python')) { Stop-Process -Id $p.Id -Force; Write-Host 'Tokenizer stopped.'; exit 0 }; Write-Host 'The port is used by a non-Python process. Not stopped.'; exit 1"
pause
endlocal
