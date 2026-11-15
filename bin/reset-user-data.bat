@echo off
setlocal enabledelayedexpansion

cd /d "%~dp0.."
set "PROJECT_DIR=%CD%"

set "MYSQL=%PROJECT_DIR%portable\mariadb\bin\mariadb.exe"
set "LOCAL_CFG=%PROJECT_DIR%config\local.php"

if not exist "%MYSQL%" (
    echo [ERRO] MariaDB portatil nao encontrado.
    pause
    exit /b 1
)
if not exist "%LOCAL_CFG%" (
    echo [ERRO] config\local.php nao encontrado.
    pause
    exit /b 1
)

for /f "usebackq delims=" %%P in (`powershell -NoProfile -Command "$c=Get-Content -Raw '%LOCAL_CFG%'; if($c -match '''database_admin''\s*=>\s*\[[^]]*''password''\s*=>\s*''([^'']+)'''){$Matches[1]}"`) do set "DB_PASS=%%P"

if not defined DB_PASS (
    echo [ERRO] Nao foi possivel ler a senha do banco.
    pause
    exit /b 1
)

echo Apagando dados salvos no servidor...

"%MYSQL%" -h 127.0.0.1 -u root -p"%DB_PASS%" -e "DELETE FROM grape_calls.user_preferences; DELETE FROM grape_calls.room_participants; DELETE FROM grape_calls.rooms;" 2>nul
if errorlevel 1 (
    echo [ERRO] Falha ao limpar o banco. O MySQL esta rodando?
    pause
    exit /b 1
)

if exist "%PROJECT_DIR%data\sessions" del /q "%PROJECT_DIR%data\sessions\sess_*" >nul 2>&1

echo.
echo ================================================================
echo  BAIXO APAGADO COM SUCESSO NO SERVIDOR:
echo    - Preferencias de usuario
echo    - Salas criadas
echo    - Sessoes PHP
echo.
echo  NAO APAGADO AQUI:
echo    - Foto do avatar (esta no navegador)
echo    - Nome de exibicao e preferencias locais
echo.
echo Para limpar TUDO, abra a home do site e clique em:
echo   Configuracoes > Apagar meus dados
echo.
echo ================================================================
echo.
pause