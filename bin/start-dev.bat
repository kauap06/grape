@echo off
REM Gaming Calls - Windows Development Startup Script
REM Usage: start-dev.bat

setlocal enabledelayedexpansion

set PROJECT_DIR=%~dp0..\
cd /d "%PROJECT_DIR%"

REM Portable toolchain (no installation required): portable\php + portable\composer
set "PHP_DIR=%PROJECT_DIR%\portable\php"
set "PHP_EXE=%PROJECT_DIR%\portable\php\php.exe"
if not exist "%PHP_EXE%" set PHP_EXE=php

echo ==========================================
echo Gaming Calls - Development Server (Windows)
echo ==========================================

REM Check PHP version
for /f "tokens=2" %%i in ('"%PHP_EXE%" -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;"') do set PHP_VERSION=%%i
echo PHP Version: %PHP_VERSION%

REM Check if version >= 8.2
for /f "tokens=1,2 delims=." %%a in ("%PHP_VERSION%") do (
    set MAJOR=%%a
    set MINOR=%%b
)

if %MAJOR% LSS 8 (
    echo ERROR: PHP 8.2+ required, found %PHP_VERSION%
    pause
    exit /b 1
)
if %MAJOR% EQU 8 if %MINOR% LSS 2 (
    echo ERROR: PHP 8.2+ required, found %PHP_VERSION%
    pause
    exit /b 1
)

echo PHP Version OK

REM Check Composer (portable composer.phar, run with the same PHP)
set "COMPOSER_PHAR=%PROJECT_DIR%\portable\composer\composer.phar"
if exist "%COMPOSER_PHAR%" (
    set "COMPOSER_CMD="%PHP_EXE%" "%COMPOSER_PHAR%""
) else (
    set "COMPOSER_CMD=composer"
)
%COMPOSER_CMD% --version >nul 2>&1
if errorlevel 1 (
    echo ERROR: Composer not found ^(portable\composer\composer.phar or PATH^)
    pause
    exit /b 1
)
echo Composer OK

REM Install dependencies if needed
if not exist vendor (
    echo Installing Composer dependencies...
    %COMPOSER_CMD% install --no-interaction
)

REM Create logs directory
if not exist logs mkdir logs

echo.
echo Starting WebSocket server on port 8081...
start "Gaming Calls WebSocket" /B "%PHP_EXE%" server/websocket-server.php > logs/websocket.log 2>&1

REM Give WebSocket server time to start
timeout /t 2 /nobreak >nul

echo Starting HTTP server on port 8000...
start "Gaming Calls HTTP" /B "%PHP_EXE%" -S 0.0.0.0:8000 -t public > logs/http.log 2>&1

timeout /t 1 /nobreak >nul

echo.
echo ==========================================
echo Servers running!
echo ==========================================
echo Home page:     http://localhost:8000
echo WebSocket:     ws://localhost:8081
echo.
echo Logs:
echo   WebSocket:  type logs/websocket.log
echo   HTTP:       type logs/http.log
echo.
echo Press Ctrl+C in the server windows to stop
echo ==========================================

echo.
echo WebSocket server running in window "Gaming Calls WebSocket"
echo HTTP server running in window "Gaming Calls HTTP"
echo.
pause