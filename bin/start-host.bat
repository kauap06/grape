@echo off
REM ============================================
REM Grape - Local Hosting Startup Script (Windows)
REM Starts MySQL, the PHP HTTP server (port 8080) and the WebSocket server
REM (port 8081). Does NOT use XAMPP or Apache - uses a standalone PHP and a
REM standalone MySQL/MariaDB found on PATH (or already running as a service).
REM Designed for Radmin VPN local hosting
REM ============================================

setlocal enabledelayedexpansion

REM Detect project root automatically (this script lives in bin/)
cd /d "%~dp0.."
set PROJECT_DIR=%CD%
echo Project root: %PROJECT_DIR%

REM ============================================
REM 1. Detect PHP executable (standalone install on PATH - not XAMPP)
REM ============================================
set PHP_EXE=

for /f "tokens=*" %%i in ('where php 2^>nul') do if not defined PHP_EXE set PHP_EXE=%%i

if not defined PHP_EXE if exist "C:\php\php.exe" set PHP_EXE=C:\php\php.exe

if not defined PHP_EXE (
    echo ERROR: PHP not found. Install standalone PHP 8.2+ ^(https://windows.php.net/download/^)
    echo        and add its folder to PATH - this project does not use XAMPP's PHP.
    exit /b 1
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
REM 3. Prepare logs directory
REM ============================================
if not exist "%PROJECT_DIR%\logs" mkdir "%PROJECT_DIR%\logs"

REM ============================================
REM 4. Detect and start MySQL/MariaDB (standalone, not XAMPP's).
REM    If it's already running (e.g. installed as a Windows service by the
REM    MySQL/MariaDB installer), it's reused as-is and nothing is started here.
REM ============================================
echo.
echo Checking MySQL (port 3306)...
set MYSQL_ALREADY_RUNNING=0
>nul netstat -ano | findstr /C:":3306 "
if not errorlevel 1 set MYSQL_ALREADY_RUNNING=1

if %MYSQL_ALREADY_RUNNING% EQU 1 (
    echo MySQL already running on port 3306 - reusing it.
) else (
    set MYSQLD_EXE=
    for /f "tokens=*" %%i in ('where mysqld 2^>nul') do if not defined MYSQLD_EXE set MYSQLD_EXE=%%i

    if not defined MYSQLD_EXE (
        echo AVISO: mysqld nao encontrado no PATH.
        echo         Instale o MySQL ou o MariaDB ^(standalone, sem XAMPP^) e adicione a pasta
        echo         "bin" dele ao PATH, ou instale-o como servico do Windows e inicie-o antes
        echo         de rodar este script. Download:
        echo           MySQL:   https://dev.mysql.com/downloads/mysql/
        echo           MariaDB: https://mariadb.org/download/
    ) else (
        echo Starting MySQL: %MYSQLD_EXE%
        start "Grape MySQL (3306)" /D "%PROJECT_DIR%" cmd /c ""%MYSQLD_EXE%" > "%PROJECT_DIR%\logs\mysql.log" 2>&1"

        REM Wait for MySQL to accept connections on 3306 (up to ~15s)
        set MYSQL_STARTED=0
        for /l %%n in (1,1,15) do (
            if !MYSQL_STARTED! EQU 0 (
                >nul netstat -ano | findstr /C:":3306 "
                if not errorlevel 1 (
                    set MYSQL_STARTED=1
                ) else (
                    timeout /t 1 /nobreak >nul
                )
            )
        )
        if !MYSQL_STARTED! EQU 0 (
            echo AVISO: MySQL nao respondeu na porta 3306 apos 15s. Veja logs\mysql.log
        ) else (
            echo MySQL is up on port 3306.
        )
    )
)

REM ============================================
REM 5. Check ports 8080 and 8081 are free
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
REM 6. Start services in separate terminal windows
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

REM Avisa (sem matar sozinho - pode ser algo seu, fora do Grape) se o Apache
REM do XAMPP ainda estiver rodando e grudado na porta 443/80: ele responde no
REM lugar do Caddy e o WebSocket nunca conecta, mesmo com tudo aqui certo.
tasklist /FI "IMAGENAME eq httpd.exe" 2>nul | find /I "httpd.exe" >nul
if not errorlevel 1 (
    echo.
    echo AVISO: o Apache ^(httpd.exe^) do XAMPP esta rodando e pode estar ocupando
    echo        a porta 443/80, impedindo o Caddy de responder de verdade. O Grape
    echo        nao usa o Apache - pare-o ^(XAMPP Control Panel, ou "taskkill /F /IM httpd.exe"^)
    echo        e, se ele volta sozinho, desative o servico dele em services.msc.
    echo.
)

REM HTTPS (Caddy) - necessario para camera, microfone e compartilhamento de tela via Radmin VPN
REM Caddy instalado via webi fica em %USERPROFILE%\.local\bin
set "PATH=%USERPROFILE%\.local\bin;%PATH%"

REM Mata qualquer caddy.exe que tenha sobrado de uma rodada anterior (janela
REM fechada sem encerrar o processo, Ctrl+C que nao propagou, etc.). Sem isso,
REM dois caddy.exe podem ficar "escutando" a porta 443 ao mesmo tempo e so um
REM deles responde de verdade - o outro so acumula log vazio e confunde.
taskkill /F /IM caddy.exe >nul 2>&1

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
REM 7. Verify services started
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
REM 8. Display results and Radmin VPN instructions
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
echo   MySQL:      %PROJECT_DIR%\logs\mysql.log
echo   HTTP:       %PROJECT_DIR%\logs\http.log
echo   WebSocket:  %PROJECT_DIR%\logs\websocket.log
echo   HTTPS:      %PROJECT_DIR%\logs\caddy.log
echo.
echo Services run in separate windows titled:
echo   "Grape MySQL (3306)", "Grape HTTP (8080)", "Grape WebSocket (8081)" and "Grape HTTPS (Caddy)"
echo   (MySQL window only appears if this script started it; it's skipped if MySQL was already running)
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