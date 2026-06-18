@echo off
setlocal
call "%~dp0gpt-workflow-config.bat"
cd /d "%PROJECT_DIR%"
echo [LinguaCafe] Running doctor checks...
"%PHP_EXE%" artisan senses:gpt-workflow doctor --user_id=%USER_ID% --language=%LANGUAGE%
echo.
pause
endlocal
