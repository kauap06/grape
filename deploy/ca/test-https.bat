@echo off
REM ============================================
REM Grape - Diagnostico do certificado HTTPS (para CONVIDADOS e host)
REM
REM Uso:
REM   test-https.bat            -> pergunta o IP do host
REM   test-https.bat 26.12.34.56
REM
REM Diz se a CA do host esta instalada e se o Windows aceita o certificado
REM de https://<IP>, alem de avisar se o navegador padrao e o Firefox
REM (que usa loja de certificados propria).
REM ============================================

setlocal
set "DIR=%~dp0"
if not exist "%DIR%test-https.ps1" (
    echo ERRO: o arquivo test-https.ps1 precisa estar na mesma pasta deste .bat.
    pause
    exit /b 1
)
powershell -NoProfile -ExecutionPolicy Bypass -File "%DIR%test-https.ps1" %1
echo.
pause
