@echo off
REM ============================================
REM Grape - Modelo do instalador de certificado para CONVIDADOS.
REM Nao rode este arquivo: make-guest-installer.bat gera
REM deploy\ca\dist\install-ca.bat a partir dele, trocando @CERT_BASE64@
REM pelo certificado do host. O convidado recebe apenas o .bat gerado.
REM ============================================

setlocal
set "TMP_PEM=%TEMP%\grape-ca-%RANDOM%%RANDOM%.pem"
set "TMP_CRT=%TEMP%\grape-ca-%RANDOM%%RANDOM%.crt"

REM Certificado do host embutido (base64 do DER) - preenchido pelo gerador
set "GRAPE_CA_B64=@CERT_BASE64@"

REM Se o certificado ainda tem "@", o arquivo nao passou pelo gerador
set "CHK=@"
if not "%GRAPE_CA_B64:@=%"=="%GRAPE_CA_B64%" (
    echo ERRO: este arquivo nao foi gerado corretamente.
    echo Rode deploy\ca\make-guest-installer.bat no PC do host.
    pause
    exit /b 1
)

echo Instalando o certificado HTTPS do Grape neste PC ^(sem administrador^)...

REM Remonta o certificado a partir do base64 embutido
> "%TMP_PEM%" echo -----BEGIN CERTIFICATE-----
>> "%TMP_PEM%" echo %GRAPE_CA_B64%
>> "%TMP_PEM%" echo -----END CERTIFICATE-----

certutil -decode "%TMP_PEM%" "%TMP_CRT%" >nul 2>&1
if errorlevel 1 goto :erro

certutil -user -addstore -f ROOT "%TMP_CRT%" >nul 2>&1
if errorlevel 1 goto :erro

del "%TMP_PEM%" "%TMP_CRT%" >nul 2>&1
echo.
echo Pronto! Este PC agora confia no certificado do Grape.
echo Feche e reabra o navegador se ele estiver aberto.
echo Agora abra o link da sala que o host enviou ^(comeca com https://^).
echo.
echo Para remover depois:
echo   deploy\ca\remove-ca.bat
pause
exit /b 0

:erro
del "%TMP_PEM%" "%TMP_CRT%" >nul 2>&1
echo.
echo ERRO: nao foi possivel instalar o certificado.
echo Se persistir, abra certlm.msc ^> Autoridades de Certificacao Raiz Confiaveis
echo e importe manualmente o certificado do host ^(caddy-root.crt^).
pause
exit /b 1
