@echo off
REM Grape Root CA - Windows wrapper. Runs generate-root-ca.sh through Git Bash,
REM which is already installed if you use Git for Windows (same as this repo's
REM other .bat scripts assume for bin\start-dev.sh).
setlocal
set "SCRIPT_DIR=%~dp0"

where bash >nul 2>nul
if %errorlevel%==0 (
    bash "%SCRIPT_DIR%generate-root-ca.sh" %*
    exit /b %errorlevel%
)

if exist "%ProgramFiles%\Git\bin\bash.exe" (
    "%ProgramFiles%\Git\bin\bash.exe" "%SCRIPT_DIR%generate-root-ca.sh" %*
    exit /b %errorlevel%
)

echo ERROR: bash not found. Install Git for Windows (https://git-scm.com/download/win)
echo        and re-run this script, or run generate-root-ca.sh from Git Bash/WSL directly.
exit /b 1
