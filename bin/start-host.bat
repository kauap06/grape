@echo off
setlocal enabledelayedexpansion

cd /d "%~dp0.."
set "PROJECT_DIR=%CD%"
set "PATH=%PROJECT_DIR%\portable\php;%PROJECT_DIR%\portable\caddy;%PATH%"

REM ---- Launcher integration (Grape.exe) ----
REM GRAPE_HEADLESS=1 : every child process shares this hidden console (/b) and
REM                    error pauses are skipped, so no window can ever appear.
REM GRAPE_LAUNCHER=1 : Grape.exe owns the first-run CA prompt; skip the silent
REM                    install here so the user is asked before the store changes.
set "HIDDEN="
set "PAUSE_CMD=pause"
if defined GRAPE_HEADLESS set "HIDDEN=/b"
if defined GRAPE_HEADLESS set "PAUSE_CMD=rem"

REM ---- PHP ----
set PHP_EXE=
if exist "%PROJECT_DIR%\portable\php\php.exe" set PHP_EXE=%PROJECT_DIR%\portable\php\php.exe
if not defined PHP_EXE for /f "tokens=*" %%i in ('where php 2^>nul') do if not defined PHP_EXE set PHP_EXE=%%i
if not defined PHP_EXE (
    echo [ERRO] PHP nao encontrado.
    %PAUSE_CMD%
    exit /b 1
)

REM ---- OPcache ----
REM No Windows o opcache pode falhar por ASLR ao mapear a memoria compartilhada.
REM Um cache em disco absoluto permite o fallback e evita encerrar o PHP.
set "OPCACHE_DIR=%PROJECT_DIR%\portable\php\opcache"
if not exist "%OPCACHE_DIR%" mkdir "%OPCACHE_DIR%" >nul 2>&1
set "PHP_INI_SCAN_DIR=%PROJECT_DIR%\portable\php\conf.d"
if not exist "%PHP_INI_SCAN_DIR%" mkdir "%PHP_INI_SCAN_DIR%" >nul 2>&1
> "%PHP_INI_SCAN_DIR%\zz-opcache.ini" echo opcache.file_cache="%OPCACHE_DIR%"
>> "%PHP_INI_SCAN_DIR%\zz-opcache.ini" echo opcache.file_cache_fallback=1

"%PHP_EXE%" -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;" > "%TEMP%\grape_phpver.txt" 2>nul
set PHP_VERSION=unknown
if exist "%TEMP%\grape_phpver.txt" set /p PHP_VERSION= < "%TEMP%\grape_phpver.txt" & del "%TEMP%\grape_phpver.txt" 2>nul
if "%PHP_VERSION%"=="unknown" (
    echo [ERRO] Falha ao executar o PHP.
    %PAUSE_CMD%
    exit /b 1
)
for /f "tokens=1,2 delims=." %%a in ("%PHP_VERSION%") do set MAJOR=%%a&set MINOR=%%b
if %MAJOR% LSS 8 (
    echo [ERRO] PHP 8.2+ necessario, encontrado %PHP_VERSION%.
    %PAUSE_CMD%
    exit /b 1
)
if %MAJOR% EQU 8 if %MINOR% LSS 2 (
    echo [ERRO] PHP 8.2+ necessario, encontrado %PHP_VERSION%.
    %PAUSE_CMD%
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
    echo [ERRO] Dependencias do Composer ausentes.
    %PAUSE_CMD%
    exit /b 1
)

if not exist "%PROJECT_DIR%\logs" mkdir "%PROJECT_DIR%\logs"

REM ---- Limpeza de portas ----
REM Só paga o custo do PowerShell quando alguma porta do Grape já está ocupada
REM (execução anterior travada). Em uma abertura normal, nenhum processo extra
REM é consultado e o serviço sobe mais rápido.
set "NEED_CLEAN=0"
for %%P in (8181 8182 8443 33070) do (
    netstat -ano -p tcp | findstr /r /c:":%%P .*LISTENING" >nul 2>&1
    if not errorlevel 1 set "NEED_CLEAN=1"
)
if "!NEED_CLEAN!"=="1" (
    echo Limpando processos anteriores do Grape...
    set "GRAPE_ROOT=%PROJECT_DIR%"
    powershell -NoProfile -ExecutionPolicy Bypass -Command "$raiz = $env:GRAPE_ROOT; $alvo = Get-CimInstance Win32_Process -Filter \"Name='php.exe' or Name='caddy.exe' or Name='mysqld.exe'\" | Where-Object { ($_.ExecutablePath -and $_.ExecutablePath.StartsWith($raiz, [System.StringComparison]::OrdinalIgnoreCase)) -or $_.CommandLine -like '*websocket-server.php*' -or $_.CommandLine -like '*deploy*Caddyfile*' }; foreach ($proc in $alvo) { try { Stop-Process -Id $proc.ProcessId -Force -ErrorAction Stop; Write-Host ('  [limpo] processo ' + $proc.ProcessId + ' encerrado') } catch {} }"
    ping -n 3 -w 1000 127.0.0.1 >nul 2>&1
)

for %%P in (8181 8182 8443) do (
    netstat -ano -p tcp | findstr /r /c:":%%P .*LISTENING" >nul 2>&1
    if not errorlevel 1 (
        for /f "tokens=5" %%A in ('netstat -ano -p tcp ^| findstr /r /c:":%%P .*LISTENING"') do (
            set "NOME_PROC=desconhecido"
            for /f "skip=2 tokens=1" %%N in ('tasklist /fi "PID eq %%A"') do set "NOME_PROC=%%N"
            echo [ERRO] Porta %%P ocupada pelo processo !NOME_PROC! com PID %%A. Encerre-o e rode o start-host de novo.
            %PAUSE_CMD%
            exit /b 1
        )
    )
)

REM ---- MariaDB ----
set MYSQL_ALREADY_RUNNING=0
netstat -ano -p tcp | findstr /C:":33070 " | findstr /C:"LISTENING" >nul
if not errorlevel 1 set MYSQL_ALREADY_RUNNING=1

set MYSQLD_EXE=
if exist "%PROJECT_DIR%\portable\mariadb\bin\mysqld.exe" set MYSQLD_EXE=%PROJECT_DIR%\portable\mariadb\bin\mysqld.exe
if not defined MYSQLD_EXE for /f "tokens=*" %%i in ('where mysqld 2^>nul') do if not defined MYSQLD_EXE set MYSQLD_EXE=%%i
set MYSQLD_PORTABLE=0
if defined MYSQLD_EXE if "%MYSQLD_EXE%" == "%PROJECT_DIR%\portable\mariadb\bin\mysqld.exe" set MYSQLD_PORTABLE=1

if %MYSQL_ALREADY_RUNNING% EQU 1 (
    echo MariaDB: ja esta rodando na porta 33070.
) else (
    if not defined MYSQLD_EXE (
        echo [ERRO] mysqld nao encontrado.
        %PAUSE_CMD%
        exit /b 1
    )
    if %MYSQLD_PORTABLE% EQU 1 if not exist "%PROJECT_DIR%\portable\mariadb\data\mysql" (
        echo Preparando banco de dados...
        "%PROJECT_DIR%\portable\mariadb\bin\mariadb-install-db.exe" --datadir="%PROJECT_DIR%\portable\mariadb\data" --default-user >nul 2>&1
    )
    start "Grape MariaDB (33070)" %HIDDEN% /D "%PROJECT_DIR%" cmd /c ""%MYSQLD_EXE%" --datadir="%PROJECT_DIR%\portable\mariadb\data" --port=33070 --bind-address=127.0.0.1 --local-infile=0 --skip-name-resolve --performance-schema=OFF --console > "%PROJECT_DIR%\logs\mysql.log" 2>&1"
    set MYSQL_STARTED=0
    for /l %%n in (1,1,15) do (
        if !MYSQL_STARTED! EQU 0 (
            netstat -ano -p tcp | findstr /C:":33070 " | findstr /C:"LISTENING" >nul
            if not errorlevel 1 (
                set MYSQL_STARTED=1
            ) else (
                ping -n 2 -w 1000 127.0.0.1 >nul 2>&1
            )
        )
    )
    if !MYSQL_STARTED! EQU 0 (
        echo [ERRO] MariaDB nao respondeu na porta 33070. Veja logs\mysql.log
        %PAUSE_CMD%
        exit /b 1
    )
)

REM ---- Banco: usuario e schema ----
if exist "%PROJECT_DIR%\database\bootstrap.php" (
    "%PHP_EXE%" "%PROJECT_DIR%\database\bootstrap.php" >nul 2>&1
    if errorlevel 1 (
        "%PHP_EXE%" "%PROJECT_DIR%\database\bootstrap.php"
        echo [ERRO] Falha ao preparar o banco.
        %PAUSE_CMD%
        exit /b 1
    )
    "%PHP_EXE%" "%PROJECT_DIR%\database\migrate.php" >nul 2>&1
)

REM ---- IP do Radmin VPN ----
set RADMIN_IP=
for /f "usebackq delims=" %%i in (`powershell -NoProfile -Command "(Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.IPAddress -like '26.*' } | Select-Object -First 1).IPAddress"`) do set RADMIN_IP=%%i
if not defined RADMIN_IP (
    echo AVISO: IP do Radmin VPN nao encontrado.
    set RADMIN_IP=127.0.0.1
)

REM ---- Servicos ----
start "Grape HTTP (8181)" %HIDDEN% /D "%PROJECT_DIR%" cmd /c ""%PHP_EXE%" -S 127.0.0.1:8181 -t public > "%PROJECT_DIR%\logs\http.log" 2>&1"
start "Grape WebSocket (8182)" %HIDDEN% /D "%PROJECT_DIR%" cmd /c ""%PHP_EXE%" server/websocket-server.php > "%PROJECT_DIR%\logs\websocket.log" 2>&1"

set "PATH=%USERPROFILE%\.local\bin;%PATH%"
set CADDY_EXE=
if exist "%PROJECT_DIR%\portable\caddy\caddy.exe" set CADDY_EXE=%PROJECT_DIR%\portable\caddy\caddy.exe
if defined CADDY_EXE (
    start "Grape HTTPS (Caddy)" %HIDDEN% /D "%PROJECT_DIR%" cmd /c ""%CADDY_EXE%" run --config deploy\Caddyfile > logs\caddy.log 2>&1"
) else (
    where caddy >nul 2>&1
    if errorlevel 1 (
        echo AVISO: Caddy nao encontrado.
    ) else (
        start "Grape HTTPS (Caddy)" %HIDDEN% /D "%PROJECT_DIR%" cmd /c "caddy run --config deploy\Caddyfile > logs\caddy.log 2>&1"
    )
)

ping -n 4 -w 1000 127.0.0.1 >nul 2>&1

REM ---- CA ----
REM Grape.exe (GRAPE_LAUNCHER=1) explains the certificate, asks the user and
REM then runs deploy\ca\dist\install-ca.bat /silent itself. Running
REM start-host.bat by hand keeps the original automatic behavior.
if not defined GRAPE_LAUNCHER if exist "%PROJECT_DIR%\deploy\ca\install-ca.bat" call "%PROJECT_DIR%\deploy\ca\install-ca.bat" /silent >nul 2>&1

REM The guest installer must embed the CA generated on THIS PC. Replace any
REM caddy-root.crt copied from another machine with the live Caddy root first.
if exist "%APPDATA%\Caddy\pki\authorities\local\root.crt" (
    if not exist "%PROJECT_DIR%\deploy\ca\dist" mkdir "%PROJECT_DIR%\deploy\ca\dist" >nul 2>&1
    copy /Y "%APPDATA%\Caddy\pki\authorities\local\root.crt" "%PROJECT_DIR%\deploy\ca\dist\caddy-root.crt" >nul 2>&1
)
if exist "%PROJECT_DIR%\deploy\ca\make-guest-installer.bat" call "%PROJECT_DIR%\deploy\ca\make-guest-installer.bat" >nul 2>&1

REM Aquece o opcache
if exist "%SystemRoot%\System32\curl.exe" (
    curl.exe -s -k -o NUL --max-time 8 https://localhost:8443/ >nul 2>&1
    curl.exe -s -k -o NUL --max-time 8 https://localhost:8443/ >nul 2>&1
)

echo.
echo Grape iniciado.
echo   Neste PC:   https://localhost:8443
echo   Convidados: https://%RADMIN_IP%:8443
echo   Certificado: deploy\ca\dist\install-ca.bat
echo   Logs: %PROJECT_DIR%\logs
echo.
echo Para parar: rodar bin\stop-host.bat
echo.
if defined GRAPE_HEADLESS (
    REM Keep this batch process (and its hidden console) alive for the app's
    REM lifetime. Grape.exe terminates it with the job object on exit.
    ping -t 127.0.0.1 >nul
) else (
    pause
)