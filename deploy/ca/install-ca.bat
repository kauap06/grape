@echo off
REM ============================================
REM Grape - Faz o Windows/navegador confiar no certificado HTTPS do Grape,
REM para abrir https://<IP do Radmin> sem aviso (libera camera, microfone e
REM compartilhamento de tela).
REM
REM NAO precisa de administrador: o certificado e instalado na loja do
REM usuario atual (certutil -user). Se por algum motivo isso falhar, o script
REM tenta a loja do computador e, nesse caso, pede elevacao automaticamente.
REM
REM Rodar UMA vez em CADA PC (host e convidados).
REM
REM Uso:
REM   install-ca.bat                  -> instala a CA encontrada neste PC
REM   install-ca.bat caddy-root.crt   -> instala o arquivo informado
REM                                      (arrastar o .crt sobre o .bat tambem vale)
REM   install-ca.bat /silent          -> sem mensagens nem "pause" (usado pelo
REM                                      start-host.bat e por scripts)
REM
REM O Caddyfile usa "tls internal", entao a CA que vale e a do proprio Caddy:
REM   %APPDATA%\Caddy\pki\authorities\local\root.crt
REM No PC do host ela existe; para os convidados, este script copia uma via
REM para deploy\ca\dist\caddy-root.crt - envie esse arquivo e rode o .bat la.
REM ============================================

setlocal enabledelayedexpansion
set "SCRIPT_DIR=%~dp0"
set "DIST=%SCRIPT_DIR%dist"
set "CADDY_ROOT=%APPDATA%\Caddy\pki\authorities\local\root.crt"
set "CERT="

REM Argumento: "--elevated" e usado internamente quando o script se reabre como
REM administrador; nesse caso o caminho do certificado vem em %2.
set "CERT_ARG=%~1"
if /i "%~1"=="--elevated" set "CERT_ARG=%~2"
if /i "%~1"=="/silent" set "CERT_ARG=%~2"

REM /silent: sem pause e sem janelas (instalacao automatica)
set "SILENT=0"
for %%A in (%*) do if /i "%%~A"=="/silent" set "SILENT=1"

REM 1) Arquivo informado por argumento / arrastado sobre o .bat
if not "%CERT_ARG%"=="" if exist "%CERT_ARG%" set "CERT=%CERT_ARG%"

REM 2) CA do Caddy deste PC (caso do host): instala e deixa uma copia em dist
if not defined CERT if exist "%CADDY_ROOT%" (
    set "CERT=%CADDY_ROOT%"
    if not exist "%DIST%" mkdir "%DIST%" >nul 2>&1
    copy /Y "%CADDY_ROOT%" "%DIST%\caddy-root.crt" >nul 2>&1
    if "%SILENT%"=="0" (
        echo CA do Caddy encontrada neste PC.
        echo Copia para os convidados: %DIST%\caddy-root.crt
        echo.
    )
)

REM 2b) Copia trazida do host, na mesma pasta deste .bat (convidado)
if not defined CERT if exist "%SCRIPT_DIR%caddy-root.crt" (
    set "CERT=%SCRIPT_DIR%caddy-root.crt"
    if "%SILENT%"=="0" echo Usando a CA do host: %SCRIPT_DIR%caddy-root.crt
)

REM 3) Copia trazida do host pelo convidado
if not defined CERT if exist "%DIST%\caddy-root.crt" (
    set "CERT=%DIST%\caddy-root.crt"
    if "%SILENT%"=="0" echo Usando a CA do host: %DIST%\caddy-root.crt
)

REM 4) CA propria (deploy\ca\generate-root-ca.bat)
if not defined CERT if exist "%DIST%\grape-root.crt" set "CERT=%DIST%\grape-root.crt"

if not defined CERT (
    if "%SILENT%"=="1" (
        echo AVISO: nenhuma CA encontrada para instalar ^(rode bin\start-host.bat uma vez^).
        exit /b 1
    )
    echo ERRO: nenhuma CA encontrada.
    echo.
    echo No PC do host ^(que roda o Caddy^): rode bin\start-host.bat uma vez e
    echo   execute este script de novo - a CA do Caddy e criada na primeira
    echo   execucao e fica em:
    echo   %CADDY_ROOT%
    echo.
    echo Nos convidados: peca ao host o arquivo caddy-root.crt e rode:
    echo   install-ca.bat caddy-root.crt
    pause
    exit /b 1
)

if "%SILENT%"=="0" echo Instalando "%CERT%" para o usuario atual ^(sem precisar de administrador^)...

REM Converte o PEM para DER antes de instalar: o "certutil -addstore" falha
REM direto no PEM da CA do Caddy (DecodeFile: ERROR_INVALID_NAME).
set "DER=%TEMP%\grape-ca-%RANDOM%%RANDOM%.cer"
certutil -decode "%CERT%" "%DER%" >nul 2>&1
if errorlevel 1 (
    REM O arquivo informado pode ja ser DER: usa como veio
    copy /Y "%CERT%" "%DER%" >nul 2>&1
)

certutil -user -addstore -f ROOT "%DER%" >nul 2>&1
if not errorlevel 1 (
    del "%DER%" >nul 2>&1
    goto :ok
)
del "%DER%" >nul 2>&1

echo.
REM Em modo silencioso NAO tenta elevacao: abriria um UAC invisivel e
REM travaria o start-host.bat esperando resposta.
if "%SILENT%"=="1" (
    echo AVISO: nao foi possivel confiar na CA do Caddy para este usuario.
    exit /b 1
)

echo Nao foi possivel instalar para o usuario atual. Tentando para o computador
echo inteiro ^(precisa de administrador^)...

REM Estou elevado? "fltmc" nao depende do servico LanmanServer, ao contrario
REM de "net session" (que falha com o servico parado mesmo sendo admin).
fltmc >nul 2>&1
if errorlevel 1 (
    if /i "%~1"=="--elevated" goto :fail
    echo Solicitando permissao de administrador ^(aceite a janela do UAC^)...
    if "%CERT_ARG%"=="" (
        powershell -NoProfile -ExecutionPolicy Bypass -Command "Start-Process -FilePath '%~f0' -ArgumentList '--elevated' -Verb RunAs" >nul 2>&1
    ) else (
        for %%F in ("%CERT%") do set "ABS=%%~fF"
        powershell -NoProfile -ExecutionPolicy Bypass -Command "Start-Process -FilePath '%~f0' -ArgumentList '--elevated','!ABS!' -Verb RunAs" >nul 2>&1
    )
    if errorlevel 1 goto :fail
    echo Se a janela do UAC foi aceita, a instalacao continua na janela elevada.
    ping -n 6 -w 1000 127.0.0.1 >nul 2>&1
    exit /b 0
)

REM Recria o DER (foi apagado depois da tentativa na loja do usuario)
certutil -decode "%CERT%" "%DER%" >nul 2>&1
if errorlevel 1 copy /Y "%CERT%" "%DER%" >nul 2>&1
certutil -addstore -f ROOT "%DER%"
set "MADD=%errorlevel%"
del "%DER%" >nul 2>&1
if not "%MADD%"=="0" goto :fail
if errorlevel 1 goto :fail

:ok
if "%SILENT%"=="1" (
    echo Certificado HTTPS confiado neste PC ^(CA do Caddy^).
    exit /b 0
)
echo.
echo Pronto. Este PC agora confia no certificado do Grape.
echo Feche e reabra o navegador se ele estiver aberto.
echo Teste em: https://localhost  ^(deve abrir sem aviso de certificado^)
echo.
echo Para remover depois:
echo   deploy\ca\remove-ca.bat
pause
exit /b 0

:fail
if "%SILENT%"=="1" (
    echo AVISO: nao foi possivel confiar na CA do Caddy automaticamente.
    exit /b 1
)
echo.
echo ERRO: nao foi possivel instalar o certificado.
echo Se a janela do UAC foi cancelada, rode de novo e aceite a elevacao.
echo Alternativa manual: abra "certlm.msc" ^> Autoridades de Certificacao
echo Raiz Confiaveis ^> Importar ^> "%CERT%"
pause
exit /b 1
