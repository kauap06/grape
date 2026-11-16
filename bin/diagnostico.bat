@echo off
setlocal enabledelayedexpansion

echo ============================================================
echo  Grape - DIAGNOSTICO
echo ============================================================
echo.

cd /d "%~dp0.."
set "PROJECT_DIR=%CD%"
echo 1 - Diretorio: %PROJECT_DIR%
pause

echo 2 - Verificando PHP...
if exist "%PROJECT_DIR%\portable\php\php.exe" (
    echo PHP encontrado
    "%PROJECT_DIR%\portable\php\php.exe" -v
) else (
    echo PHP NAO ENCONTRADO
)
pause

echo 3 - Verificando porta 8181...
netstat -ano -p tcp | findstr ":8181"
pause

echo 4 - Verificando porta 8182...
netstat -ano -p tcp | findstr ":8182"
pause

echo 5 - Verificando porta 8443...
netstat -ano -p tcp | findstr ":8443"
pause

echo 6 - Teste PowerShell...
powershell -NoProfile -Command "Write-Host 'PowerShell OK'"
pause

echo 7 - FIM DO DIAGNOSTICO
pause