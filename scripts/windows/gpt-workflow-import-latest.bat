@echo off
call "%~dp0gpt-workflow-config.bat"
cd /d "%PROJECT_DIR%"
echo Importing latest validated mapping...
echo Make sure you already ran the dry-run import and reviewed the summary.
pause
"%PHP_EXE%" artisan senses:gpt-workflow import-latest --user_id=%USER_ID% --language=%LANGUAGE%
if errorlevel 1 exit /b 1
call "%~dp0open-sense-review.bat"
