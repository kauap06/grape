@echo off
setlocal enabledelayedexpansion

cd /d "%~dp0.."
set "PROJECT_DIR=%CD%"
echo DIR: %PROJECT_DIR%

echo === Teste: primeiro-run marker ===
set "FIRST_RUN_MARKER=%PROJECT_DIR%\.first-run-configured"
echo Marker: %FIRST_RUN_MARKER%
if exist "%FIRST_RUN_MARKER%" (
    echo Marker EXISTE - nao e primeira execucao
) else (
    echo Marker NAO EXISTE - primeira execucao
)
pause

echo === Teste: install-ca.bat ===
if exist "%PROJECT_DIR%\deploy\ca\install-ca.bat" (
    echo Arquivo existe. Executando /silent...
    call "%PROJECT_DIR%\deploy\ca\install-ca.bat" /silent
    echo Codigo de saida: %ERRORLEVEL%
) else (
    echo NAO ENCONTRADO
)
pause

echo === Teste: make-guest-installer.bat ===
if exist "%PROJECT_DIR%\deploy\ca\make-guest-installer.bat" (
    echo Arquivo existe. Executando...
    call "%PROJECT_DIR%\deploy\ca\make-guest-installer.bat"
    echo Codigo de saida: %ERRORLEVEL%
) else (
    echo NAO ENCONTRADO
)
pause

echo === Teste: PowerShell firewall ===
powershell -NoProfile -ExecutionPolicy Bypass -Command "Write-Host 'PS test OK'"
echo Codigo: %ERRORLEVEL%
pause

echo === Teste: Criar marcador ===
echo. > "%FIRST_RUN_MARKER%"
echo Marcador criado.
if exist "%FIRST_RUN_MARKER%" (
    echo Confirmado: marcador existe agora.
) else (
    echo ERRO: marcador nao foi criado!
)
pause

echo === FIM ===
pause