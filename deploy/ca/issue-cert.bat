@echo off
REM Grape Root CA - Windows wrapper. Runs issue-cert.sh through Git Bash.
REM Usage: issue-cert.bat <name> <san> [san ...]
REM   e.g.: issue-cert.bat radmin-host ip:SEU-IP-RADMIN dns:localhost
setlocal
set "SCRIPT_DIR=%~dp0"

where bash >nul 2>nul
if %errorlevel%==0 (
    bash "%SCRIPT_DIR%issue-cert.sh" %*
    exit /b %errorlevel%
)

if exist "%ProgramFiles%\Git\bin\bash.exe" (
    "%ProgramFiles%\Git\bin\bash.exe" "%SCRIPT_DIR%issue-cert.sh" %*
    exit /b %errorlevel%
)

echo ERROR: bash not found. Install Git for Windows (https://git-scm.com/download/win)
echo        and re-run this script, or run issue-cert.sh from Git Bash/WSL directly.
exit /b 1
