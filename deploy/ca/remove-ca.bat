@echo off
REM ============================================
REM Grape - Remove a confianca nos certificados do Grape neste PC
REM (usuario atual). Use se precisar desfazer a instalacao.
REM Nao precisa de administrador.
REM ============================================

setlocal enabledelayedexpansion
echo Removendo a confianca no certificado do Grape deste usuario...

REM A lista vai para um arquivo temporario: usar pipe dentro do "for /f"
REM quebra a linha de comando do cmd. O certutil -delstore tambem precisa do
REM thumbprint (por nome ele diz "sucesso" mas nao remove da loja Root).
set "LIST=%TEMP%\grape_ca_list_%RANDOM%.txt"
powershell -NoProfile -Command "Get-ChildItem Cert:\CurrentUser\Root | Where-Object { $_.Subject -like '*CN=Caddy Local Authority*' -or $_.Subject -like '*CN=Grape Root CA*' } | ForEach-Object { $_.Thumbprint }" > "%LIST%" 2>nul

set "FOUND=0"
for /f "usebackq delims=" %%T in ("%LIST%") do (
    certutil -user -delstore ROOT %%T >nul 2>&1
    if not errorlevel 1 (
        echo   removido %%T
        set "FOUND=1"
    )
)
del "%LIST%" >nul 2>&1

if "%FOUND%"=="0" (
    echo   nenhum certificado do Grape encontrado neste usuario.
) else (
    echo.
    echo Pronto. Feche e reabra o navegador.
)

echo.
echo Obs.: certificados instalados para o COMPUTADOR inteiro ^(loja
echo LocalMachine^) nao sao removidos aqui - use certlm.msc se precisar.
pause
exit /b 0
