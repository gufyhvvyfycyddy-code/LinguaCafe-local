@echo off
call "%~dp0gpt-workflow-config.bat"
cd /d "%PROJECT_DIR%"
echo Running dry-run import for latest validated mapping...
"%PHP_EXE%" artisan senses:gpt-workflow import-latest --user_id=%USER_ID% --language=%LANGUAGE% --dry-run
if errorlevel 1 exit /b 1
echo Dry-run complete. Review the summary before formal import.
