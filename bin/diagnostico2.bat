@echo off
setlocal enabledelayedexpansion

cd /d "%~dp0.."
set "PROJECT_DIR=%CD%"
echo Projeto: %PROJECT_DIR%

echo === Teste 1: PowerShell limpeza ===
powershell -NoProfile -ExecutionPolicy Bypass -Command "$alvo = Get-CimInstance Win32_Process -Filter \"Name='php.exe' or Name='caddy.exe' or Name='mysqld.exe'\" | Where-Object { $_.ExecutablePath -like '*grape*portable*' -or $_.CommandLine -like '*websocket-server.php*' -or $_.CommandLine -like '*deploy*Caddyfile*' }; foreach ($proc in $alvo) { try { Stop-Process -Id $proc.ProcessId -Force -ErrorAction Stop; Write-Host ('  [limpo] processo ' + $proc.ProcessId + ' encerrado') } catch {} }"
echo PowerShell terminou com codigo: %ERRORLEVEL%
pause

echo === Teste 2: Verificacao porta 8181 ===
netstat -ano -p tcp | findstr /r /c:":8181 .*LISTENING" >nul 2>&1
echo findstr porta 8181 codigo: %ERRORLEVEL%
pause

echo === Teste 3: Verificacao porta 8182 ===
netstat -ano -p tcp | findstr /r /c:":8182 .*LISTENING" >nul 2>&1
echo findstr porta 8182 codigo: %ERRORLEVEL%
pause

echo === Teste 4: Verificacao porta 8443 ===
netstat -ano -p tcp | findstr /r /c:":8443 .*LISTENING" >nul 2>&1
echo findstr porta 8443 codigo: %ERRORLEVEL%
pause

echo === Teste 5: Loop de portas ===
for %%P in (8181 8182 8443) do (
    echo Verificando %%P...
    netstat -ano -p tcp | findstr /r /c:":%%P .*LISTENING" >nul 2>&1
    if not errorlevel 1 (
        echo   Ocupada
    ) else (
        echo   Livre
    )
)
echo Loop terminou.
pause

echo === FIM ===
pause