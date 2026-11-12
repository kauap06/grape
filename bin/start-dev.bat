@echo off
REM Gaming Calls - Windows Development Startup Script
REM Usage: start-dev.bat

setlocal enabledelayedexpansion

set PROJECT_DIR=%~dp0..\
cd /d "%PROJECT_DIR%"

echo ==========================================
echo Gaming Calls - Development Server (Windows)
echo ==========================================

REM Check PHP version
for /f "tokens=2" %%i in ('php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;"') do set PHP_VERSION=%%i
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

REM Check Composer
composer --version >nul 2>&1
if errorlevel 1 (
    echo ERROR: Composer not found in PATH
    pause
    exit /b 1
)
echo Composer OK

REM Install dependencies if needed
if not exist vendor (
    echo Installing Composer dependencies...
    composer install --no-interaction
)

REM Create logs directory
if not exist logs mkdir logs

echo.
echo Starting WebSocket server on port 8080...
start "Gaming Calls WebSocket" /B php server/websocket-server.php > logs/websocket.log 2>&1

REM Give WebSocket server time to start
timeout /t 2 /nobreak >nul

echo Starting HTTP server on port 8000...
start "Gaming Calls HTTP" /B php -S 0.0.0.0:8000 -t public > logs/http.log 2>&1

timeout /t 1 /nobreak >nul

echo.
echo ==========================================
echo Servers running!
echo ==========================================
echo Home page:     http://localhost:8000
echo WebSocket:     ws://localhost:8080
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