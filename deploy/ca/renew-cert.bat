@echo off
REM Grape Root CA - Windows wrapper. Runs renew-cert.sh through Git Bash.
REM Usage: renew-cert.bat <name>
setlocal
set "SCRIPT_DIR=%~dp0"

where bash >nul 2>nul
if %errorlevel%==0 (
    bash "%SCRIPT_DIR%renew-cert.sh" %*
    exit /b %errorlevel%
)

if exist "%ProgramFiles%\Git\bin\bash.exe" (
    "%ProgramFiles%\Git\bin\bash.exe" "%SCRIPT_DIR%renew-cert.sh" %*
    exit /b %errorlevel%
)

echo ERROR: bash not found. Install Git for Windows (https://git-scm.com/download/win)
echo        and re-run this script, or run renew-cert.sh from Git Bash/WSL directly.
exit /b 1
