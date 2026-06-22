@echo off
setlocal
call "%~dp0gpt-workflow-config.bat"
cd /d "%PROJECT_DIR%"
echo [LinguaCafe] Running GPT workflow doctor...
"%PHP_EXE%" artisan senses:gpt-workflow doctor --user_id=%USER_ID% --language=%LANGUAGE%
echo.
echo [LinguaCafe] Checking ECDICT dictionary...
"%PHP_EXE%" artisan dictionary:import-ecdict --status
echo.
pause
endlocal
