@echo off
REM ============================================
REM Grape - Local Hosting Startup Script (Windows)
REM Inicia MariaDB, PHP (8080), WebSocket (8081) e Caddy (443).
REM Saida minima: apenas erros/avisos e o resumo final.
REM ============================================

setlocal enabledelayedexpansion

cd /d "%~dp0.."
set "PROJECT_DIR=%CD%"
set "PATH=%PROJECT_DIR%\portable\php;%PROJECT_DIR%\portable\caddy;%PATH%"

REM ---- PHP ----
set PHP_EXE=
if exist "%PROJECT_DIR%\portable\php\php.exe" set PHP_EXE=%PROJECT_DIR%\portable\php\php.exe
if not defined PHP_EXE for /f "tokens=*" %%i in ('where php 2^>nul') do if not defined PHP_EXE set PHP_EXE=%%i
if not defined PHP_EXE if exist "C:\php\php.exe" set PHP_EXE=C:\php\php.exe
if not defined PHP_EXE (
    echo [ERRO] PHP nao encontrado. Use o PHP portavel em portable\php\php.exe ou instale PHP 8.2+.
    pause
    exit /b 1
)

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

REM ---- Dependencias ----
if not exist "%PROJECT_DIR%\vendor\autoload.php" (
    if exist "%PROJECT_DIR%\portable\composer\composer.phar" (
        echo Instalando dependencias do Composer...
        set COMPOSER_ALLOW_SUPERUSER=1
        "%PHP_EXE%" "%PROJECT_DIR%\portable\composer\composer.phar" install --no-interaction --working-dir="%PROJECT_DIR%"
    )
)
if not exist "%PROJECT_DIR%\vendor\autoload.php" (
    echo [ERRO] Dependencias do Composer ausentes e nao foi possivel instala-las.
    pause
    exit /b 1
)

if not exist "%PROJECT_DIR%\logs" mkdir "%PROJECT_DIR%\logs"

REM ---- MariaDB (loopback, local_infile off) ----
set MYSQL_ALREADY_RUNNING=0
netstat -ano -p tcp | findstr /C:":3306 " | findstr /C:"LISTENING" >nul
if not errorlevel 1 set MYSQL_ALREADY_RUNNING=1

set MYSQLD_EXE=
if exist "%PROJECT_DIR%\portable\mariadb\bin\mysqld.exe" set MYSQLD_EXE=%PROJECT_DIR%\portable\mariadb\bin\mysqld.exe
if not defined MYSQLD_EXE for /f "tokens=*" %%i in ('where mysqld 2^>nul') do if not defined MYSQLD_EXE set MYSQLD_EXE=%%i
set MYSQLD_PORTABLE=0
if defined MYSQLD_EXE if "%MYSQLD_EXE%" == "%PROJECT_DIR%\portable\mariadb\bin\mysqld.exe" set MYSQLD_PORTABLE=1

if %MYSQL_ALREADY_RUNNING% EQU 1 (
    echo MariaDB: reutilizando o servidor ja ativo na porta 3306.
) else (
    if not defined MYSQLD_EXE (
        echo [ERRO] mysqld nao encontrado ^(portable\mariadb ou PATH^). Restaure o MariaDB portavel.
        pause
        exit /b 1
    )
    if %MYSQLD_PORTABLE% EQU 1 if not exist "%PROJECT_DIR%\portable\mariadb\data\mysql" (
        echo Preparando banco de dados ^(primeira execucao^)...
        "%PROJECT_DIR%\portable\mariadb\bin\mariadb-install-db.exe" --datadir="%PROJECT_DIR%\portable\mariadb\data" --default-user >nul 2>&1
        set GRAPE_DB_FRESH=1
    )
    start "Grape MariaDB (3306)" /D "%PROJECT_DIR%" cmd /c ""%MYSQLD_EXE%" --datadir="%PROJECT_DIR%\portable\mariadb\data" --bind-address=127.0.0.1 --local-infile=0 --console > "%PROJECT_DIR%\logs\mysql.log" 2>&1"
    set MYSQL_STARTED=0
    for /l %%n in (1,1,15) do (
        if !MYSQL_STARTED! EQU 0 (
            netstat -ano -p tcp | findstr /C:":3306 " | findstr /C:"LISTENING" >nul
            if not errorlevel 1 (
                set MYSQL_STARTED=1
            ) else (
                ping -n 2 -w 1000 127.0.0.1 >nul 2>&1
            )
        )
    )
    if !MYSQL_STARTED! EQU 0 (
        echo [ERRO] MariaDB nao respondeu na porta 3306. Veja logs\mysql.log
        pause
        exit /b 1
    )
)

REM ---- Banco: usuario da aplicacao + schema (idempotente) ----
if exist "%PROJECT_DIR%\database\bootstrap.php" (
    "%PHP_EXE%" "%PROJECT_DIR%\database\bootstrap.php" >nul 2>&1
    if errorlevel 1 (
        "%PHP_EXE%" "%PROJECT_DIR%\database\bootstrap.php"
        echo [ERRO] Falha ao preparar o banco. Confira a mensagem acima.
        pause
        exit /b 1
    )
    "%PHP_EXE%" "%PROJECT_DIR%\database\migrate.php" >nul 2>&1
)

REM ---- Portas 8080/8081 livres ----
netstat -ano -p tcp | findstr /C:":8080 " | findstr /C:"LISTENING" >nul
if not errorlevel 1 (
    echo [ERRO] Porta 8080 ocupada. Feche as janelas "Grape HTTP/WebSocket" ou rode: taskkill /F /IM php.exe
    pause
    exit /b 1
)
netstat -ano -p tcp | findstr /C:":8081 " | findstr /C:"LISTENING" >nul
if not errorlevel 1 (
    echo [ERRO] Porta 8081 ocupada. Feche as janelas "Grape HTTP/WebSocket" ou rode: taskkill /F /IM php.exe
    pause
    exit /b 1
)

REM ---- IP do Radmin VPN (26.x.x.x) para o link de convite ----
set RADMIN_IP=
for /f "usebackq delims=" %%i in (`powershell -NoProfile -Command "(Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.IPAddress -like '26.*' } | Select-Object -First 1).IPAddress"`) do set RADMIN_IP=%%i
if not defined RADMIN_IP (
    echo AVISO: IP do Radmin VPN nao encontrado - convidados nao conseguira conectar.
    set RADMIN_IP=127.0.0.1
)

REM ---- Servicos (janelas separadas) ----
REM PHP e WebSocket ficam em 127.0.0.1; so o Caddy (443) fica exposto.
start "Grape HTTP (8080)" /D "%PROJECT_DIR%" cmd /c ""%PHP_EXE%" -S 127.0.0.1:8080 -t public > "%PROJECT_DIR%\logs\http.log" 2>&1"
start "Grape WebSocket (8081)" /D "%PROJECT_DIR%" cmd /c ""%PHP_EXE%" server/websocket-server.php > "%PROJECT_DIR%\logs\websocket.log" 2>&1"

tasklist /FI "IMAGENAME eq httpd.exe" 2>nul | find /I "httpd.exe" >nul
if not errorlevel 1 (
    echo AVISO: Apache do XAMPP esta rodando e pode ocupar a porta 443/80 e impedir o Caddy. Pare-o no XAMPP Control Panel.
)

set "PATH=%USERPROFILE%\.local\bin;%PATH%"
set CADDY_EXE=
if exist "%PROJECT_DIR%\portable\caddy\caddy.exe" set CADDY_EXE=%PROJECT_DIR%\portable\caddy\caddy.exe
taskkill /F /IM caddy.exe >nul 2>&1
if defined CADDY_EXE (
    start "Grape HTTPS (Caddy)" /D "%PROJECT_DIR%" cmd /c ""%CADDY_EXE%" run --config deploy\Caddyfile > logs\caddy.log 2>&1"
) else (
    where caddy >nul 2>&1
    if errorlevel 1 (
        echo AVISO: Caddy nao encontrado - sem HTTPS, camera/microfone/tela nao funcionam para convidados.
    ) else (
        start "Grape HTTPS (Caddy)" /D "%PROJECT_DIR%" cmd /c "caddy run --config deploy\Caddyfile > logs\caddy.log 2>&1"
    )
)

ping -n 4 -w 1000 127.0.0.1 >nul 2>&1

REM ---- CA: confia no certificado local e gera o instalador dos convidados ----
if exist "%PROJECT_DIR%\deploy\ca\install-ca.bat" call "%PROJECT_DIR%\deploy\ca\install-ca.bat" /silent >nul 2>&1
if exist "%PROJECT_DIR%\deploy\ca\make-guest-installer.bat" call "%PROJECT_DIR%\deploy\ca\make-guest-installer.bat" >nul 2>&1

REM Aquece o opcache para o primeiro acesso nao demorar
if exist "%SystemRoot%\System32\curl.exe" (
    curl.exe -s -k -o NUL --max-time 8 https://localhost/ >nul 2>&1
    curl.exe -s -k -o NUL --max-time 8 https://localhost/ >nul 2>&1
)

REM ---- Verificacao final ----
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

echo.
if %HTTP_STARTED% EQU 1 (
    echo Grape iniciado.
) else (
    echo Grape iniciado com problemas - veja logs\http.log
)
if %WS_STARTED% EQU 0 echo [AVISO] WebSocket pode nao ter iniciado - veja logs\websocket.log
echo   Neste PC:   https://localhost
echo   Convidados: https://%RADMIN_IP%   ^(envie este link; camera/microfone/tela exigem HTTPS^)
echo   Certificado para convidados: deploy\ca\dist\install-ca.bat
echo   Logs: %PROJECT_DIR%\logs
echo.
echo Para parar: feche as janelas "Grape ..." ou rode  taskkill /F /IM php.exe ^& taskkill /F /IM caddy.exe ^& taskkill /F /IM mysqld.exe
echo.
pause
