@echo off
REM Grape - para os servicos iniciados por start-host.bat
REM MariaDB (3306), PHP (8080), WebSocket (8081) e Caddy (443)
setlocal enabledelayedexpansion

set "PROJECT_DIR=%~dp0..\"
cd /d "%PROJECT_DIR%"

echo Parando os servicos do Grape...
echo.

REM Encerra apenas processos DESTE projeto: o executavel esta em portable\
REM ou a linha de comando referencia o websocket/Caddyfile do projeto.
REM Nao toca em outros php/mysqld/caddy da maquina.
powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "$pids = Get-CimInstance Win32_Process -Filter \"Name='php.exe' or Name='caddy.exe' or Name='mysqld.exe'\" | Where-Object { $_.ExecutablePath -like '*grape*portable*' -or $_.CommandLine -like '*websocket-server.php*' -or $_.CommandLine -like '*deploy*Caddyfile*' } | Select-Object -ExpandProperty ProcessId;" ^
  "if ($pids) { foreach ($id in $pids) { try { Stop-Process -Id $id -Force -ErrorAction Stop; Write-Host ('  [ok] processo ' + $id + ' encerrado') } catch {} } }" ^
  "else { Write-Host '  (nenhum processo do Grape em execucao)' }"

echo.
echo Verificando as portas...
set "LIVRES=1"
for %%P in (3306 8080 8081 443) do (
    netstat -ano -p tcp | findstr /r /c:":%%P .*LISTENING" >nul 2>&1
    if errorlevel 1 (
        echo   porta %%P: livre
    ) else (
        echo   porta %%P: AINDA OCUPADA
        set "LIVRES=0"
    )
)

echo.
if "!LIVRES!"=="1" (
    echo Tudo parado.
) else (
    echo Atencao: alguma porta continua ocupada.
    echo Se for a 443, o Apache do XAMPP pode estar no lugar do Caddy -
    echo pare-o no XAMPP Control Panel.
)

echo.
pause