@echo off
REM Grape - modo desenvolvimento (sem Caddy/HTTPS): HTTP 8000, WebSocket 8081
setlocal enabledelayedexpansion

set "PROJECT_DIR=%~dp0..\"
cd /d "%PROJECT_DIR%"
set "PHP_EXE=%PROJECT_DIR%portable\php\php.exe"
if not exist "%PHP_EXE%" set PHP_EXE=php

"%PHP_EXE%" -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;" > "%TEMP%\grape_phpver.txt" 2>nul
set PHP_VERSION=unknown
if exist "%TEMP%\grape_phpver.txt" (
    set /p PHP_VERSION= < "%TEMP%\grape_phpver.txt"
    del "%TEMP%\grape_phpver.txt" 2>nul
)
if "%PHP_VERSION%"=="unknown" (
    echo [ERRO] Falha ao executar o PHP.
    pause
    exit /b 1
)
for /f "tokens=1,2 delims=." %%a in ("%PHP_VERSION%") do (
    set MAJOR=%%a
    set MINOR=%%b
)
if %MAJOR% LSS 8 (
    echo [ERRO] PHP 8.2+ necessario, encontrado %PHP_VERSION%.
    pause
    exit /b 1
)
if %MAJOR% EQU 8 if %MINOR% LSS 2 (
    echo [ERRO] PHP 8.2+ necessario, encontrado %PHP_VERSION%.
    pause
    exit /b 1
)

set "COMPOSER_PHAR=%PROJECT_DIR%portable\composer\composer.phar"
if exist "%COMPOSER_PHAR%" (
    set "COMPOSER_CMD="%PHP_EXE%" "%COMPOSER_PHAR%""
) else (
    set "COMPOSER_CMD=composer"
)
if not exist vendor (
    echo Instalando dependencias do Composer...
    %COMPOSER_CMD% install --no-interaction
)
if not exist "%PROJECT_DIR%vendor\autoload.php" (
    echo [ERRO] Dependencias do Composer ausentes.
    pause
    exit /b 1
)

if not exist logs mkdir logs

start "Grape Dev WebSocket (8081)" /B "%PHP_EXE%" server/websocket-server.php > logs/websocket.log 2>&1
timeout /t 2 /nobreak >nul
start "Grape Dev HTTP (8000)" /B "%PHP_EXE%" -S 127.0.0.1:8000 -t public > logs/http.log 2>&1

echo.
echo Grape em modo desenvolvimento.
echo   Site:      http://localhost:8000
echo   WebSocket: ws://localhost:8081
echo   Logs:      logs\http.log e logs\websocket.log
echo Para parar: feche as janelas ou rode  taskkill /F /IM php.exe
echo.
pause
