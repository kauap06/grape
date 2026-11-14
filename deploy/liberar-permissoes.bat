@echo off
REM ============================================
REM Grape - Libera camera, microfone e compartilhamento de tela
REM para o endereco do host em Chrome e Edge (e outros Chromium que usam a politica do Chrome).
REM Rode UMA vez em cada PC (clique direito > Executar como administrador).
REM ============================================
net session >nul 2>&1
if errorlevel 1 (
    echo ERRO: execute como administrador ^(clique direito ^> Executar como administrador^).
    pause
    exit /b 1
)

set /p HOST_IP=Digite o IP do Radmin do host (ex: 26.12.34.56): 
if "%HOST_IP%"=="" (
    echo IP nao informado.
    pause
    exit /b 1
)

set URL=https://%HOST_IP%
set URL_HTTP=http://%HOST_IP%:8080

for %%B in ("Google\Chrome" "Microsoft\Edge") do (
    REM Camera e microfone sem perguntar
    reg add "HKLM\SOFTWARE\Policies\%%~B\VideoCaptureAllowedUrls" /v 1 /t REG_SZ /d "%URL%" /f >nul
    reg add "HKLM\SOFTWARE\Policies\%%~B\AudioCaptureAllowedUrls" /v 1 /t REG_SZ /d "%URL%" /f >nul
    REM Permite compartilhamento de tela para o endereco
    reg add "HKLM\SOFTWARE\Policies\%%~B\ScreenCaptureAllowedByOrigins" /v 1 /t REG_SZ /d "%URL%" /f >nul
    REM Trata o endereco HTTP como seguro (so use se nao for usar o HTTPS do Caddy)
    reg add "HKLM\SOFTWARE\Policies\%%~B\OverrideSecurityRestrictionsOnInsecureOrigin" /v 1 /t REG_SZ /d "%URL_HTTP%" /f >nul
)

echo.
echo Pronto. Feche TODAS as janelas do navegador e abra de novo.
echo Confira em chrome://policy ^(ou edge://policy^). O navegador mostrara "gerenciado pela sua organizacao": e normal.
echo Para remover depois: reg delete "HKLM\SOFTWARE\Policies\Google\Chrome" /f ^& reg delete "HKLM\SOFTWARE\Policies\Microsoft\Edge" /f
pause
