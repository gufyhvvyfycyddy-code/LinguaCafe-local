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

echo [LinguaCafe] Checking MariaDB/MySQL: %DB_HOST%:%DB_PORT% / %DB_DATABASE%
call :check_port
if not errorlevel 1 goto create_database

echo [LinguaCafe] Database port is not reachable. Trying to start MariaDB/MySQL...

if not "%MARIADB_SERVICE_NAME%"=="" (
    sc query "%MARIADB_SERVICE_NAME%" >nul 2>&1
    if not errorlevel 1 (
        net start "%MARIADB_SERVICE_NAME%" >nul 2>&1
    )
)

call :check_port
if not errorlevel 1 goto create_database

if exist "%MYSQLD_EXE%" (
    if exist "%MYSQL_DEFAULTS_FILE%" (
        echo [LinguaCafe] No Windows service found. Starting local MariaDB: %MYSQLD_EXE%
        start "" /min "%MYSQLD_EXE%" --defaults-file="%MYSQL_DEFAULTS_FILE%"
    ) else (
        echo [LinguaCafe] MariaDB config file not found: %MYSQL_DEFAULTS_FILE%
    )
) else (
    echo [LinguaCafe] MariaDB/MySQL executable not found: %MYSQLD_EXE%
)

for /l %%I in (1,1,20) do (
    call :check_port
    if not errorlevel 1 goto create_database
    timeout /t 1 /nobreak >nul
)

echo.
echo [LinguaCafe] MariaDB/MySQL is not running, or .env has the wrong DB_HOST/DB_PORT.
echo [LinguaCafe] Current config: DB_HOST=%DB_HOST%, DB_PORT=%DB_PORT%, DB_DATABASE=%DB_DATABASE%, DB_USERNAME=%DB_USERNAME%
echo [LinguaCafe] Run scripts\windows\database-doctor.bat for details.
if /i not "%~1"=="--no-pause" pause
exit /b 1

:create_database
set "LC_DB_HOST=%DB_HOST%"
set "LC_DB_PORT=%DB_PORT%"
set "LC_DB_DATABASE=%DB_DATABASE%"
set "LC_DB_USERNAME=%DB_USERNAME%"
set "LC_DB_PASSWORD=%DB_PASSWORD%"
"%PHP_EXE%" -r "try { $host=getenv('LC_DB_HOST'); $port=getenv('LC_DB_PORT'); $db=getenv('LC_DB_DATABASE'); $user=getenv('LC_DB_USERNAME'); $pass=getenv('LC_DB_PASSWORD'); if (!preg_match('/^[A-Za-z0-9_]+$/', $db)) { throw new RuntimeException('Invalid DB_DATABASE'); } $pdo=new PDO('mysql:host='.$host.';port='.$port, $user, $pass, array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION)); $pdo->exec('CREATE DATABASE IF NOT EXISTS `'.$db.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); echo 'OK'; } catch (Throwable $e) { fwrite(STDERR, $e->getMessage()); exit(1); }"
if errorlevel 1 (
    echo.
    echo [LinguaCafe] The database port is reachable, but database access failed. Check DB_USERNAME and DB_PASSWORD in .env.
    if /i not "%~1"=="--no-pause" pause
    exit /b 1
)

echo.
echo [LinguaCafe] Database connection is ready.
exit /b 0

:check_port
powershell -NoProfile -ExecutionPolicy Bypass -Command "$client = New-Object Net.Sockets.TcpClient; try { $async = $client.BeginConnect('%DB_HOST%', [int]'%DB_PORT%', $null, $null); if (-not $async.AsyncWaitHandle.WaitOne(1500, $false)) { exit 1 }; $client.EndConnect($async); exit 0 } catch { exit 1 } finally { $client.Close() }" >nul 2>&1
exit /b %errorlevel%
