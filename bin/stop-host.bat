@echo off
setlocal enabledelayedexpansion

cd /d "%~dp0.."
set "PROJECT_DIR=%CD%"
set "GRAPE_ROOT=%PROJECT_DIR%"

echo Parando servicos do Grape...
echo.

powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "$raiz = $env:GRAPE_ROOT; $pids = Get-CimInstance Win32_Process -Filter \"Name='php.exe' or Name='caddy.exe' or Name='mysqld.exe'\" | Where-Object { ($_.ExecutablePath -and $_.ExecutablePath.StartsWith($raiz, [System.StringComparison]::OrdinalIgnoreCase)) -or $_.CommandLine -like '*websocket-server.php*' -or $_.CommandLine -like '*deploy*Caddyfile*' }; foreach ($p in $pids) { try { Stop-Process -Id $p.ProcessId -Force -ErrorAction Stop; Write-Host ('  [ok] processo ' + $p.ProcessId + ' encerrado') } catch {} }"

echo.
echo Verificando portas...
set "LIVRES=1"
for %%P in (33070 8181 8182 8443) do (
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
    echo Se for a 8443, verifique o Apache do XAMPP Control Panel.
)
echo.
if not defined GRAPE_HEADLESS pause