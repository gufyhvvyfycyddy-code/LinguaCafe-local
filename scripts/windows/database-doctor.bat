@echo off
setlocal EnableExtensions
call "%~dp0gpt-workflow-config.bat"

cd /d "%PROJECT_DIR%"

if exist ".env" (
    for /f "usebackq tokens=1,* delims==" %%A in (".env") do (
        if /i "%%A"=="DB_HOST" set "DB_HOST=%%B"
        if /i "%%A"=="DB_PORT" set "DB_PORT=%%B"
        if /i "%%A"=="DB_DATABASE" set "DB_DATABASE=%%B"
        if /i "%%A"=="DB_USERNAME" set "DB_USERNAME=%%B"
        if /i "%%A"=="DB_PASSWORD" set "DB_PASSWORD=%%B"
    )
)

echo LinguaCafe database doctor
echo ==========================
echo PROJECT_DIR=%PROJECT_DIR%
echo DB_HOST=%DB_HOST%
echo DB_PORT=%DB_PORT%
echo DB_DATABASE=%DB_DATABASE%
echo DB_USERNAME=%DB_USERNAME%
if "%DB_PASSWORD%"=="" (
    echo DB_PASSWORD=empty
) else (
    echo DB_PASSWORD=set
)
echo.

echo [Ports]
netstat -ano | findstr ":3306 :3309"
if errorlevel 1 echo No listener found on 3306 or 3309.
echo.

echo [Windows services]
sc query state= all | findstr /I "mysql maria"
if errorlevel 1 echo No registered MySQL/MariaDB Windows service found.
echo.

echo [Local MariaDB]
if exist "%MYSQLD_EXE%" (
    echo OK: %MYSQLD_EXE%
) else (
    echo FAIL: %MYSQLD_EXE% not found.
)
if exist "%MYSQL_DEFAULTS_FILE%" (
    echo OK: %MYSQL_DEFAULTS_FILE%
) else (
    echo WARN: %MYSQL_DEFAULTS_FILE% not found.
)
echo.

powershell -NoProfile -ExecutionPolicy Bypass -Command "$client = New-Object Net.Sockets.TcpClient; try { $async = $client.BeginConnect('%DB_HOST%', [int]'%DB_PORT%', $null, $null); if (-not $async.AsyncWaitHandle.WaitOne(1500, $false)) { Write-Host 'FAIL: current .env database port is not reachable.'; exit 1 }; $client.EndConnect($async); Write-Host 'OK: current .env database port is reachable.'; exit 0 } catch { Write-Host 'FAIL: current .env database port is not reachable.'; exit 1 } finally { $client.Close() }"
echo.

echo [Suggestions]
echo - Run scripts\windows\database-start.bat if the port is not reachable.
echo - If MariaDB listens on 3306, set DB_PORT=3306 in .env.
echo - If you use a Windows service, set MARIADB_SERVICE_NAME in scripts\windows\gpt-workflow-config.bat.
echo - If you use local MariaDB, check MYSQLD_EXE and MYSQL_DEFAULTS_FILE in scripts\windows\gpt-workflow-config.bat.

if /i not "%~1"=="--no-pause" pause
