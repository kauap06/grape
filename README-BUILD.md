# Grape para Windows — build, empacotamento e distribuição

Este documento descreve a versão de desktop do Grape: uma janela nativa C# WinForms com
WebView2 que inicia o servidor PHP/MariaDB/Caddy incluído e abre a aplicação real em
`https://localhost:8443/`. O aplicativo continua sendo PHP; o shell não reimplementa
autenticação, salas, WebRTC, WebSocket ou banco de dados.

## 1. Tecnologia escolhida

**WebView2 dentro de um shell WinForms C# 5, com runtime WebView2 fixo incluído.**

Motivos:

- preserva a aplicação PHP e o WebRTC sem reescrever a interface;
- fornece janela nativa, ícone, barra de título própria, permissões de câmera/microfone,
  encerramento limpo e execução em segundo plano sem console;
- o runtime WebView2 fixo evita depender do Evergreen instalado na máquina;
- não exige Electron, PHP Desktop, Node.js nem um frontend separado.

O shell usa apenas recursos compatíveis com C# 5 e o compilador `csc.exe` que acompanha o
.NET Framework 4.x do Windows. Não use sintaxe C# 6+ em `build\launcher`.

## 2. Requisitos de build

- Windows 10/11 x64.
- .NET Framework 4.x (o `csc.exe` de `%WINDIR%\Microsoft.NET\Framework64\v4.0.30319`).
- PowerShell 5.1 ou PowerShell 7.
- SDK `Microsoft.Web.WebView2` em `build\vendor\webview2` (já incluído).
- Runtime WebView2 fixo em `runtime\webview2` ou em
  `build\vendor\webview2-runtime\Microsoft.Web.WebView2.FixedVersionRuntime.156.0.4314.58.x64`.
- `portable\php\php.exe`, `portable\php\php.ini` (versionado, com as extensões necessárias) e
  `portable\composer\composer.phar` para o pacote de produção.

O runtime fixo é grande (aprox. 674 MB, 257 arquivos) e domina o tamanho da distribuição.

## 3. Compilar o shell

```powershell
cd C:\Users\kaua\Documents\grape-app
powershell -NoProfile -ExecutionPolicy Bypass -File build\build.ps1
```

Saída: `Grape.exe`, as três DLLs do WebView2 e `build\build-info.txt` com os hashes.

Opções úteis:

```powershell
build\build.ps1 -OutputDir .\build\out-test
build\build.ps1 -SkipIcon
```

## 4. Gerar o pacote portátil

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File build\package.ps1
```

Saída: `dist\Grape`. O script recria a pasta, instala as dependências de produção do
Composer, copia o runtime WebView2 como diretório real (nunca uma junction) e valida
`Grape.exe` + `runtime\webview2\msedgewebview2.exe`. Se `Grape.exe`/DLLs ainda não
existirem, ele chama `build\build.ps1`; se `runtime\webview2` não existir, usa o runtime
fixo de `build\vendor\webview2-runtime`.

O pacote tem aproximadamente 1.623 arquivos / 1,1 GB. Ele inclui `BUILD-INFO.txt` e
`LEIA-ME.txt` em português.

Estrutura principal:

```text
dist\Grape\
  Grape.exe
  Microsoft.Web.WebView2.Core.dll
  Microsoft.Web.WebView2.WinForms.dll
  WebView2Loader.dll
  runtime\webview2\
  portable\php\
  portable\mariadb\
  portable\caddy\
  bin\start-host.bat, bin\stop-host.bat
  public\, server\, src\, database\, config\, vendor\
  deploy\Caddyfile, deploy\ca\
  data\sessions\, logs\
```

Ficam de fora, de propósito: `config\local.php`, `portable\mariadb\data`, `data\webview2`,
sessões, logs e arquivos de desenvolvimento/teste. Eles são criados na primeira execução;
por isso a pasta precisa ser gravável. O conteúdo de `deploy\ca\dist` (certificado e
instalador) é regenerado a partir do CA local desta máquina na primeira execução.

## 5. Executável único (self-extracting)

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File build\pack-single.ps1 -Version 1.0.1
```

Saída: `dist\Grape.exe` (aprox. 436 MB) e `dist\Grape.txt` com versão, tamanho e SHA-256.

Como funciona:

1. O ZIP do pacote é anexado **depois** do executável PE do stub.
2. Um rodapé de 32 bytes guarda versão ASCII, tamanho do ZIP e o marcador `GRAPESF1`.
3. Na primeira execução (ou quando a versão muda), o stub extrai para
   `%LOCALAPPDATA%\Grape\app` e inicia o `Grape.exe` interno.
4. Nas próximas execuções, o marcador `.payload-version` faz o stub abrir direto.
5. Um mutex evita duas extrações simultâneas.

Arquivos preservados durante uma atualização:

- `config\local.php`
- `data\*`
- `logs\*`
- `portable\mariadb\data\*`
- `deploy\ca\dist\*`

Assim, atualizar o `.exe` não apaga perfil, salas, banco, logs nem o certificado local.
Arquivos do aplicativo (PHP, JS, CSS, binários) são substituídos pela nova versão.

### Atualizações

Sempre incremente `-Version` ao gerar um `.exe` com mudanças. Com a mesma versão, o stub
considera a extração atual válida e não substitui arquivos. A versão do shell também deve
ser atualizada em `build\launcher\Launcher.cs` (`AssemblyVersion` e `AppInfo.Version`).

## 6. Comportamento em execução

1. O shell cria um Job Object com `JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE`.
2. Executa `bin\start-host.bat` oculto, com `GRAPE_HEADLESS=1` e `GRAPE_LAUNCHER=1`.
3. O batch inicializa o MariaDB, roda `database\bootstrap.php`, inicia PHP (8181),
   WebSocket (8182) e Caddy (8443).
4. O shell aguarda `https://localhost:8443/`, confere o CA local e abre a janela.
5. Ao fechar, o MariaDB recebe shutdown gracioso, o batch para os serviços e o Job Object
   elimina processos remanescentes.

A janela tem barra de título própria, botões minimizar/maximizar/fechar, tema escuro e
botão de instalar certificado quando necessário. Não abre navegador externo nem console.

## 7. Certificado local (CA)

O Caddy usa um CA local em `%APPDATA%\Caddy\pki\authorities\local\root.crt`. O shell
verifica se o certificado está confiável no usuário ou na máquina e, na primeira execução,
mostra o diálogo **Confiar no certificado local do Grape?**.

- **Instalar certificado** executa `deploy\ca\dist\install-ca.bat /silent` (sem
  administrador) e instala no repositório do usuário atual.
- **Agora não** salva a recusa e deixa o botão **Instalar certificado** na barra de título.
- O Windows pode exibir sua própria confirmação de segurança; isso é esperado.
- Resultado em `HKCU\Software\Grape\Launcher`.

Para testar o primeiro uso novamente:

```powershell
reg delete "HKCU\Software\Grape\Launcher" /f
certutil -user -delstore ROOT <impressão-digital>
```

Se o certificado também estiver no repositório da máquina, removê-lo exige administrador.

## 8. Testes e verificação

```powershell
# Smoke test sem abrir janela nem alterar estado
dist\Grape\Grape.exe --smoke-test

# Abrir sem o diálogo de CA (útil em máquina já confiável)
dist\Grape\Grape.exe --no-prompt

# Conferir versão/hash do executável único
Get-Content dist\Grape.txt
Get-FileHash dist\Grape.exe -Algorithm SHA256
```

O smoke test grava `logs\launcher-smoke.txt` com `HTTPS ready`, estado do certificado e
`Smoke test: PASS` ou `FAIL`.

Verificações feitas nesta versão:

- compilação C# 5 x64 com o `csc.exe` do Windows;
- pacote limpo inicia sem `config\local.php` e sem banco, cria os dois e serve a página;
- primeiro uso do executável único extrai e inicia; segunda abertura usa o caminho rápido;
- lista de dispositivos com câmeras, microfones e alto-falantes, nomes reais, atualização
  ao conectar/desconectar e seleção de preferidos;
- câmera/microfone em sala, troca de dispositivo com fallback quando o aparelho falha;
- alto-falante preferido aplicado a áudio remoto via `setSinkId`;
- sala, chat, entrada/saída, perfil e compartilhamento de tela;
- encerramento limpo e liberação das portas 33070/8181/8182/8443;
- interface em PT-BR, inclusive diálogos e mensagens de erro do shell;
- HTTP/2, compressão e cache de assets no Caddy; WebSocket e WebRTC preservados.

## 9. Limitações e avisos

- O `.exe` não é assinado. O SmartScreen pode avisar; verifique a origem e use **Mais
  informações → Executar assim mesmo**.
- O Firewall do Windows pode pedir permissão para `caddy.exe`. Sem liberar a rede privada,
  convidados remotos não conectam; o uso local continua funcionando.
- O primeiro uso do `.exe` único é mais lento porque extrai ~1,1 GB. Os seguintes abrem
  direto.
- `.NET Framework 4.x` é necessário; ele já vem no Windows 10/11 suportado. Não é preciso
  instalar PHP, MariaDB, Caddy, Composer ou WebView2.
- Não mova o `Grape.exe` único para uma pasta somente leitura; ele grava em
  `%LOCALAPPDATA%\Grape`.

## 10. Git e organização

Comite o código-fonte, `bin\`, `deploy\`, scripts de build, documentação e os runtimes
portáteis necessários para o build. O `package.ps1` compila o shell automaticamente se
`Grape.exe` e as DLLs ainda não existirem e usa o runtime fixo de
`build\vendor\webview2-runtime` quando `runtime\webview2` não estiver presente.

Não comite:

```text
dist\
build\sfx-payload.zip
build\bootstrap\GrapeBootstrap.exe
build\out-test\
build\sfx-test\
build\vendor\*.cab
config\local.php
portable\mariadb\data\
portable\php\opcache\
portable\php\conf.d\
data\webview2\
logs\*.log
runtime\webview2\   (junction de desenvolvimento)
```

O `portable\php\php.ini` **é versionado**: ele contém as extensões necessárias e não tem
caminho absoluto. Os placeholders `logs\.gitkeep` e `data\sessions\.gitkeep` mantêm as
pastas graváveis no clone.

### Tamanho do repositório

O runtime fixo do WebView2 contém `msedge.dll` com cerca de 355 MB, acima do limite de
100 MB por arquivo do GitHub. Para publicar no GitHub, use Git LFS para esse arquivo ou
hospede o repositório em um servidor que aceite arquivos grandes. O `dist\` e o banco
local não entram no commit.

Os caminhos mais importantes:

```text
build\build.ps1              compila o shell
build\package.ps1            gera dist\Grape
build\pack-single.ps1        gera dist\Grape.exe
build\bootstrap\Bootstrap.cs stub de extração
build\launcher\MainForm.cs   janela, WebView2 e barra de título
build\launcher\Dialogs.cs    diálogos de certificado e progresso
build\launcher\Launcher.cs   serviços, CA, estado e smoke test
```
