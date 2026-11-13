@echo off
REM ============================================
REM Grape - Gera deploy\ca\dist\install-ca.bat: instalador de certificado
REM para CONVIDADOS com o certificado do host embutido.
REM
REM Resultado: o convidado recebe apenas esse .bat (sem .crt, sem admin) e
REM instala com um duplo clique. Rode este script no HOST para (re)gerar o
REM arquivo; o bin\start-host.bat ja faz isso sozinho a cada inicio.
REM ============================================

setlocal
set "DIR=%~dp0"
set "DIST=%DIR%dist"
set "CRT="
set "OUT=%DIST%\install-ca.bat"

REM Certificado do host: copia em dist (criada pelo install-ca.bat) ou a CA
REM do proprio Caddy nesta maquina
if exist "%DIST%\caddy-root.crt" set "CRT=%DIST%\caddy-root.crt"
if not defined CRT if exist "%APPDATA%\Caddy\pki\authorities\local\root.crt" set "CRT=%APPDATA%\Caddy\pki\authorities\local\root.crt"

if not defined CRT (
    echo ERRO: certificado do host nao encontrado.
    echo Rode primeiro: deploy\ca\install-ca.bat ^(ou inicie o Grape com bin\start-host.bat^).
    exit /b 1
)

if not exist "%DIST%" mkdir "%DIST%" >nul 2>&1

if not exist "%DIR%guest-installer-template.bat" (
    echo ERRO: modelo nao encontrado: %DIR%guest-installer-template.bat
    exit /b 1
)

powershell -NoProfile -ExecutionPolicy Bypass -File "%DIR%make-guest-installer.ps1" -CertPath "%CRT%" -TemplatePath "%DIR%guest-installer-template.bat" -OutputPath "%OUT%"
if errorlevel 1 (
    echo ERRO: falha ao gerar o instalador para convidados.
    exit /b 1
)

if not exist "%OUT%" (
    echo ERRO: nao foi possivel gerar %OUT%
    exit /b 1
)

echo Instalador para convidados gerado:
echo   %OUT%
echo.
echo Envie apenas esse arquivo para o convidado: ele so precisa dar duplo clique.
exit /b 0
