@echo off
setlocal
call "%~dp0gpt-workflow-config.bat"

echo [LinguaCafe] Starting LinguaCafe...
echo Project: %PROJECT_DIR%
echo URL: %APP_URL%
echo.

cd /d "%PROJECT_DIR%"

"%PHP_EXE%" --version >nul 2>&1
if errorlevel 1 (
    echo [LinguaCafe] PHP is not available. Edit scripts\windows\gpt-workflow-config.bat and set PHP_EXE.
    pause
    exit /b 1
)

if not exist ".env" (
    echo [LinguaCafe] Missing .env. Copy .env.example to .env and configure the database first.
    pause
    exit /b 1
)

if not exist "vendor\autoload.php" (
    echo [LinguaCafe] Missing vendor dependencies. Run composer install first.
    pause
    exit /b 1
)

call "%~dp0database-start.bat" --no-pause
if errorlevel 1 (
    pause
    exit /b 1
)

powershell -NoProfile -ExecutionPolicy Bypass -Command "try { $r = Invoke-WebRequest -Uri '%APP_URL%' -UseBasicParsing -TimeoutSec 2; exit 0 } catch { exit 1 }" >nul 2>&1
if not errorlevel 1 (
    echo [LinguaCafe] Server already appears to be running.
    call "%~dp0linguacafe-open-home.bat"
    pause
    exit /b 0
)

echo [LinguaCafe] Clearing config...
"%PHP_EXE%" artisan config:clear
if errorlevel 1 (
    echo [LinguaCafe] config:clear failed.
    pause
    exit /b 1
)

echo [LinguaCafe] Running database migrations...
"%PHP_EXE%" artisan migrate --force
if errorlevel 1 (
    echo [LinguaCafe] migrate failed. Check that MariaDB/MySQL is running and .env is correct.
    pause
    exit /b 1
)

echo [LinguaCafe] Opening browser shortly...
start "" powershell -NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -Command "Start-Sleep -Seconds 3; if ('%BROWSER_EXE%' -ne '') { Start-Process '%BROWSER_EXE%' '%APP_URL%' } else { Start-Process '%APP_URL%' }"

echo [LinguaCafe] Laravel server is starting. Keep this window open while using LinguaCafe.
"%PHP_EXE%" artisan serve --host=127.0.0.1 --port=8000

echo.
echo [LinguaCafe] Server stopped.
pause
endlocal
