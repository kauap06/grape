@echo off
REM ============================================
REM Grape - Local Hosting Startup Script (Windows)
REM Starts PHP HTTP server (port 8080) and WebSocket server (port 8081)
REM Designed for Radmin VPN local hosting
REM ============================================

REM Detect project root automatically (this script lives in bin/)
cd /d "%~dp0.."
set PROJECT_DIR=%CD%
echo Project root: %PROJECT_DIR%

REM ============================================
REM 1. Detect PHP executable (prefer XAMPP, then PATH)
REM ============================================
set PHP_EXE=

if exist "C:\xampp\php\php.exe" set PHP_EXE=C:\xampp\php\php.exe

if not defined PHP_EXE (
    for /f "tokens=*" %%i in ('where php 2^>nul') do if not defined PHP_EXE set PHP_EXE=%%i
)

echo Using PHP: %PHP_EXE%

REM Check PHP version >= 8.2
"%PHP_EXE%" -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;" > "%TEMP%\grape_phpver.txt" 2>nul
set PHP_VERSION=unknown
if exist "%TEMP%\grape_phpver.txt" (
    set /p PHP_VERSION= < "%TEMP%\grape_phpver.txt"
    del "%TEMP%\grape_phpver.txt" 2>nul
)
echo PHP Version: %PHP_VERSION%

for /f "tokens=1,2 delims=." %%a in ("%PHP_VERSION%") do (
    set MAJOR=%%a
    set MINOR=%%b
)

if %MAJOR% LSS 8 (
    echo ERROR: PHP 8.2+ required, found %PHP_VERSION%
    exit /b 1
)
if %MAJOR% EQU 8 if %MINOR% LSS 2 (
    echo ERROR: PHP 8.2+ required, found %PHP_VERSION%
    exit /b 1
)

REM ============================================
REM 2. Check Composer dependencies installed
REM ============================================
if not exist "%PROJECT_DIR%\vendor\autoload.php" (
    echo ERROR: Composer dependencies not found. Run: composer install
    exit /b 1
)

REM ============================================
REM 3. Check ports 8080 and 8081 are free
REM ============================================
echo Checking port 8080 (HTTP)...
>nul netstat -ano | findstr /C:":8080 "
if not errorlevel 1 (
    echo ERROR: Port 8080 is already in use. Free it and try again.
    exit /b 1
)

echo Checking port 8081 (WebSocket)...
>nul netstat -ano | findstr /C:":8081 "
if not errorlevel 1 (
    echo ERROR: Port 8081 is already in use. Free it and try again.
    exit /b 1
)

echo Ports 8080 and 8081 are free.

REM ============================================
REM 4. Prepare logs directory
REM ============================================
if not exist "%PROJECT_DIR%\logs" mkdir "%PROJECT_DIR%\logs"

REM ============================================
REM 5. Start services in separate terminal windows
REM ============================================
echo.
echo ============================================
echo Starting Grape services...
echo ============================================

REM Detecta o IP do Radmin VPN (26.x.x.x) antes de iniciar os servidores:
REM o PHP usa RADMIN_IP para gerar o link de convite (http://IP:8080)
set RADMIN_IP=
for /f "usebackq delims=" %%i in (`powershell -NoProfile -Command "(Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.IPAddress -like '26.*' } | Select-Object -First 1).IPAddress"`) do set RADMIN_IP=%%i
if not defined RADMIN_IP (
    echo AVISO: IP do Radmin VPN nao encontrado ^(26.x.x.x^). Abra e conecte o Radmin VPN.
    echo HTTPS sera iniciado apenas para https://localhost
    set RADMIN_IP=127.0.0.1
)

REM PHP HTTP server on 0.0.0.0:8080
start "Grape HTTP (8080)" /D "%PROJECT_DIR%" cmd /c ""%PHP_EXE%" -S 0.0.0.0:8080 -t public > "%PROJECT_DIR%\logs\http.log" 2>&1"

REM WebSocket server on 0.0.0.0:8081
start "Grape WebSocket (8081)" /D "%PROJECT_DIR%" cmd /c ""%PHP_EXE%" server/websocket-server.php > "%PROJECT_DIR%\logs\websocket.log" 2>&1"

REM HTTPS (Caddy) - necessario para camera, microfone e compartilhamento de tela via Radmin VPN
REM Caddy instalado via webi fica em %USERPROFILE%\.local\bin
set "PATH=%USERPROFILE%\.local\bin;%PATH%"
set CADDY_STARTED=0
where caddy >nul 2>&1
if errorlevel 1 (
    echo AVISO: Caddy nao encontrado. Sem HTTPS, camera/microfone/tela nao funcionam para convidados.
    echo Instale com: curl.exe https://webi.ms/caddy ^| powershell
) else (
    start "Grape HTTPS (Caddy)" /D "%PROJECT_DIR%" cmd /c "caddy run --config deploy\Caddyfile > logs\caddy.log 2>&1"
    set CADDY_STARTED=1
)

REM Give servers time to start
timeout /t 3 /nobreak >nul

REM ============================================
REM 6. Verify services started
REM ============================================
set WS_STARTED=0
set HTTP_STARTED=0

if exist "%PROJECT_DIR%\logs\websocket.log" (
    findstr /C:"Listening on" "%PROJECT_DIR%\logs\websocket.log" >nul 2>&1
    if not errorlevel 1 set WS_STARTED=1
)
if exist "%PROJECT_DIR%\logs\http.log" (
    findstr /C:"Development Server" "%PROJECT_DIR%\logs\http.log" >nul 2>&1
    if not errorlevel 1 set HTTP_STARTED=1
)

REM ============================================
REM 7. Display results and Radmin VPN instructions
REM ============================================
echo.
echo ============================================
echo Grape - Servers started
echo ============================================
echo.
echo Local access:
echo   Home page:     http://localhost:8080
echo   WebSocket:     ws://localhost:8081
echo.
echo Radmin VPN access (other users in the same VPN network):
echo   Link de convite: http://%RADMIN_IP%:8080/?code=CODIGO_DA_SALA
echo   Home page:     https://%RADMIN_IP%   ^(HTTPS: libera camera, microfone e tela^)
echo   ^(aceitar o aviso de certificado na primeira vez^)
echo.
echo How to find the host's Radmin VPN IPv4 address:
echo   1. Open Command Prompt
echo   2. Run: ipconfig
echo   3. Look for the adapter "Radmin Virtual Network Adapter" (or similar)
echo   4. Note the IPv4 Address (e.g. 26.x.x.x)
echo.
echo Logs:
echo   HTTP:       %PROJECT_DIR%\logs\http.log
echo   WebSocket:  %PROJECT_DIR%\logs\websocket.log
echo   HTTPS:      %PROJECT_DIR%\logs\caddy.log
echo.
echo Services run in separate windows titled:
echo   "Grape HTTP (8080)", "Grape WebSocket (8081)" and "Grape HTTPS (Caddy)"
echo.
echo To stop: close each service window (or press Ctrl+C inside them).
echo ============================================

if %WS_STARTED% EQU 0 (
    echo WARNING: WebSocket server may not have started. Check logs\websocket.log
)
if %HTTP_STARTED% EQU 0 (
    echo WARNING: HTTP server may not have started. Check logs\http.log
)

echo.
echo NOTE: If a service did not start correctly, check the log files above.
echo.
pause