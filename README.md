# Grape

Plataforma de voz, vídeo e compartilhamento de tela em tempo real para gamers. Construída com PHP 8.3, WebRTC e sinalização via WebSocket.

Projetada para **hospedagem local pela Radmin VPN**: um computador hospeda a sala e os demais entram pela mesma rede Radmin VPN. Tudo o que é necessário para executar acompanha o projeto — **não é preciso instalar PHP, MariaDB, Caddy nem Composer**.

---

## Funcionalidades

- **Chamadas de voz e vídeo**: comunicação WebRTC de baixa latência para todos os participantes, não apenas para o criador da sala.
- **Compartilhamento de tela**: compartilhe a tela inteira, uma janela de aplicativo ou uma aba do navegador; visível para todos os participantes.
- **Multi Screen**: assista a até **4 compartilhamentos de tela ao mesmo tempo** em uma galeria 16:9, com layouts dedicados para 1, 2, 3 e 4 telas. Dê duplo clique em um bloco para abrir a visualização em foco; clique na identificação de um participante para abrir a aba individual de tela dele. Remover um bloco nunca encerra o compartilhamento real daquele participante.
- **Moderação do anfitrião**: o criador da sala pode silenciar/reativar o microfone de um participante, desligar a câmera à força e removê-lo da sala pelo menu individual, além do botão de silenciar todos na lista de participantes. Todo comando é validado no servidor.
- **Sons de notificação personalizados**: personalize os sons de **entrada, saída, silenciamento, reativação e início de compartilhamento de tela** na página inicial, pelo botão de música ao lado das configurações. É possível ouvir, enviar ou remover cada som (até 5 MB); remover restaura o padrão. O áudio personalizado fica **somente no navegador** (IndexedDB), nunca é enviado ao servidor, e a sala o aplica automaticamente. Todos os sons são reproduzidos em volume reduzido e respeitam o silenciamento de notificações da sala.
- **Modo compacto**: redesign minimalista opcional (superfícies planas, sem gradientes, vidro ou sombras) aplicado à página inicial e à sala, lembrado por navegador.
- **Câmera flutuante redimensionável**: ao assistir a um compartilhamento de tela, a câmera se torna uma janela PiP arrastável, redimensionável pelos quatro cantos mantendo 16:9, pode ser fechada e tem tamanho persistido.
- **Código da sala**: exibido ao lado do nome da sala no cabeçalho; um clique copia o código.
- **Abas de participantes**: abas no estilo Discord para alternar entre as transmissões dos participantes.
- **Chat compartilhado**: chat em tempo real distribuído pelo servidor de sinalização WebSocket para todos os participantes da mesma sala, com identificação do remetente e horários.
- **Notificação de nova mensagem**: indicador `!` na aba do chat quando uma mensagem chega com o chat fechado.
- **Avatares de usuário**: foto de perfil personalizada ou cor sólida com iniciais como alternativa, exibida ao lado do nome de cada participante; fotos de até 5 MB são reduzidas no navegador antes do armazenamento.
- **Indicador de participante ativo**: destaque para quem está falando no momento (Web Audio API).
- **Limite de participantes**: capacidade da sala configurável, padrão 10, aplicada no frontend e no backend com entrada transacional.
- **Composição de transmissões**: layouts no estilo Twitch (foco na tela, foco na câmera, lado a lado, tela cheia e personalizado).
- **Interface escura para games**: visual inspirado em Discord/Twitch, otimizado para 1366x768 e 1920x1080.
- **Sem autenticação**: perfil local; preferências (nome de exibição, áudio, interface, dispositivos) são persistidas primeiro no localStorage e depois no servidor por navegador, sobrevivendo à limpeza do cache.
- **HTTPS por padrão**: Caddy com CA local confiável automaticamente, para que câmera, microfone e tela funcionem em qualquer navegador moderno.

---

## Início rápido (host, Windows)

```bat
cd C:\xampp\htdocs\grape-new
bin\start-host.bat
```

Esse único comando:

1. usa o conjunto de ferramentas incluído em `portable\` (sem instalação em nenhum lugar);
2. inicia o MariaDB na porta `33070`, o servidor HTTP PHP na `8181`, o servidor WebSocket na `8182` e o Caddy na `8443`;
3. inicializa o banco incluído na primeira execução e aplica as migrações (`database\migrate.php`);
4. confia no certificado HTTPS local neste PC (usuário atual, sem administrador);
5. gera `deploy\ca\dist\install-ca.bat` para os convidados;
6. exibe o link de convite e como encontrar o IPv4 da Radmin VPN.

Depois, abra **`https://localhost:8443`** no host.

Para parar tudo:

```bat
bin\stop-host.bat
```

Ele encerra apenas os processos deste projeto (identificados pelo caminho `portable\`), então outras instalações de PHP/MariaDB na máquina não são afetadas. Também é possível fechar as quatro janelas de serviço (`Grape MariaDB`, `Grape HTTP`, `Grape WebSocket` e `Grape HTTPS`).

---

## Entrando como convidado

1. Receba **um único arquivo** do host: `deploy\ca\dist\install-ca.bat`.
2. Dê duplo clique nele (não precisa de administrador) → ele confia no certificado do host.
3. **Feche todas as janelas do navegador** (Chrome/Edge/Brave mantêm o erro de certificado em cache até isso ser feito).
4. Abra o link de convite que começa com **`https://<IP_DA_VPN_DO_HOST>/?code=...`**.

Câmera, microfone e compartilhamento de tela só funcionam via HTTPS — por isso a etapa do certificado é necessária. Detalhes e solução de problemas: [`docs/CERTIFICADOS.md`](docs/CERTIFICADOS.md).

> O Firefox mantém um **armazenamento de certificados próprio**; use um navegador Chromium ou importe a CA manualmente (Firefox → Configurações → Privacidade e Segurança → Certificados → Gerenciar certificados → Autoridades → Importar).

Se o convidado ainda vir "não seguro", execute `deploy\ca\test-https.bat <IP_DA_VPN_DO_HOST>` no PC dele — o comando informa se a CA está instalada, se a cadeia de confiança do Windows é válida e se o navegador padrão é o Firefox.

---

## Requisitos

Incluído em `portable/` (nada para instalar):

| Componente | Versão | Caminho |
|---|---|---|
| PHP | 8.3.36 (TS x64) | `portable/php` — com `php.ini` (pdo_mysql, openssl, mbstring, curl, opcache) e `extras/ssl/cacert.pem` |
| MariaDB | 11.4.5 | `portable/mariadb` — diretório de dados em `portable/mariadb/data` |
| Caddy | 2.11.9 | `portable/caddy` |
| Composer | 2.11.2 | `portable/composer/composer.phar` |

O `bin\start-host.bat` resolve cada um deles primeiro em `portable\` e só recorre ao `PATH` se uma pasta estiver ausente. O servidor WebSocket é PHP puro (Ratchet), então roda com o mesmo PHP portátil.

Ainda externos, por natureza:

- **Radmin VPN** no host e em cada convidado;
- um **navegador moderno** com WebRTC (Chrome, Edge, Brave; o Firefox exige a etapa extra da CA);
- o **runtime do Visual C++ 2015-2022** (presente na maioria das instalações do Windows; um Windows limpo pode precisar dele — não é possível incluí-lo de forma confiável).

---

## Portas e acesso

| Porta | Serviço | Finalidade |
|---|---|---|
| 33070 | MariaDB | salas e participantes |
| 8181 | Servidor HTTP PHP | páginas e API REST |
| 8182 | WebSocket (Ratchet) | sinalização WebRTC e chat |
| 8443 | Caddy | HTTPS + proxy reverso `/ws` |

- Host: `https://localhost:8443` (ou `http://localhost:8181` sem HTTPS).
- Convidados: **sempre o link `https://<IP_DA_VPN_DO_HOST>:8443`** — o link HTTP na `:8181` não é uma origem segura e bloqueia câmera, microfone e tela.
- Firewall do Windows no host: permita TCP **8443** (e `8181`/`8182` se alguém usar o link HTTP).

Encontre o IPv4 da Radmin VPN do host com `ipconfig` (procure o adaptador virtual da Radmin VPN). Consulte [`docs/LOCAL_HOSTING.md`](docs/LOCAL_HOSTING.md) para as regras de firewall.

---

## Configuração

As configurações de execução ficam em `config/config.php` e podem ser sobrescritas por máquina em `config/local.php` (não versionado). Padrões:

- banco `grape_calls` em `127.0.0.1:33070`, usuário `grape` — nenhuma senha é distribuída; defina-a em `config/local.php` (copie `config/local.php.example`);
- servidor HTTP PHP `127.0.0.1:8181`, WebSocket `127.0.0.1:8182` — acessíveis somente pelo proxy Caddy na 8443;
- capacidade da sala 10, limite de 500 caracteres por mensagem no chat, ping do WebSocket de 25 s.

Credenciais nunca devem ficar em `config/config.php`, que é versionado. Use `config/local.php` ou as variáveis de ambiente `DATABASE_USERNAME` / `DATABASE_PASSWORD`.

Servidores STUN/TURN do WebRTC (`config/config.php`):

```php
'webrtc' => [
    'stun_servers' => [
        ['urls' => 'stun:stun.l.google.com:19302'],
        ['urls' => 'stun:stun1.l.google.com:19302'],
    ],
    'turn_servers' => [
        // ['urls' => 'turn:your-turn-server.com:3478', 'username' => 'user', 'credential' => 'pass'],
    ],
],
```

Na Radmin VPN os participantes conseguem se alcançar diretamente, então os candidatos ICE locais normalmente bastam. Remover as entradas STUN economiza cerca de 1 s de coleta de ICE por conexão; faça isso apenas se todos estiverem na mesma VPN.

O `.env.example` existe para configurações baseadas em ambiente, mas o fluxo portátil não exige um arquivo `.env`.

---

## Comandos

```bat
REM Inicia tudo (host)
bin\start-host.bat

REM Para tudo o que foi iniciado pelo start-host (somente processos deste projeto)
bin\stop-host.bat

REM Modo de desenvolvimento: PHP na 8000, WebSocket na 8182, sem Caddy/HTTPS
bin\start-dev.bat

REM Logs
type logs\http.log
type logs\websocket.log
type logs\websocket-error.log
type logs\caddy.log
type logs\mysql.log

REM Apaga os dados salvos do usuário (preferências, salas criadas, sessões) e volta ao estado inicial
bin\reset-user-data.bat

REM Console do banco (pede a senha do usuário da aplicação)
portable\mariadb\bin\mariadb.exe -h 127.0.0.1 -u grape -p grape_calls

REM Reaplica as migrações do schema (o start-host faz isso automaticamente)
portable\php\php.exe database\migrate.php

REM Certificados
deploy\ca\install-ca.bat             REM host: confia na CA e gera o instalador para convidados
deploy\ca\install-ca.bat /silent     REM o mesmo, sem prompts (usado pelo start-host)
deploy\ca\test-https.bat <IP>        REM diagnostica a confiança em qualquer PC
deploy\ca\remove-ca.bat              REM desfaz a confiança
deploy\ca\make-guest-installer.bat   REM regenera dist\install-ca.bat
```

---

## Banco de dados

O banco e o schema são criados pelo `bin\start-host.bat` na primeira execução, usando as credenciais de `config/local.php`. Para um banco novo ou outro servidor:

```sql
CREATE DATABASE grape_calls CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'grape'@'127.0.0.1' IDENTIFIED BY 'uma-senha-longa-e-aleatoria';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, REFERENCES, INDEX, ALTER
    ON grape_calls.* TO 'grape'@'127.0.0.1';
FLUSH PRIVILEGES;
```

```bat
REM pede a senha definida acima
portable\mariadb\bin\mariadb.exe -h 127.0.0.1 -u grape -p grape_calls < database\schema.sql
```

`database/schema.sql` é o schema canônico; `database/migrate.php` aplica pequenas atualizações idempotentes em bancos existentes (atualmente: `room_participants.avatar_data` ampliado para `MEDIUMTEXT` e `rooms.is_locked` adicionado). Faça backup parando o MariaDB e copiando `portable\mariadb\data`.

> O diretório `portable\mariadb\data` contém os arquivos do banco, incluindo os hashes de senha de `mysql.global_priv`. Ele é específico da máquina e nunca deve ser redistribuído — o `bin\start-host.bat` inicializa um novo quando ele não existe.

---

## Estrutura do projeto

```
grape-new/
├── public/                  # única pasta exposta na web (DocumentRoot)
│   ├── index.php            # página inicial
│   ├── room.php             # sala de chamada
│   ├── api/rooms/           # API REST (criar, entrar, sair, status)
│   └── assets/css|js/       # estilos e módulos ES
├── src/                     # código PHP (PSR-4: GamingCalls\)
│   ├── Config/  Database/  Models/  Server/  Session/  Support/
│   └── Support/Url.php      # URLs de assets/WebSocket derivadas da requisição
├── config/                  # config.php + local.php (sobrescritas, não versionado)
├── database/                # schema.sql + migrate.php
├── server/websocket-server.php
├── bin/                     # start-host.bat, start-dev.bat, reset-user-data.bat
├── data/sessions/           # arquivos de sessão PHP isolados (apagados pelo reset)
├── deploy/                  # Caddyfile, ca/ (certificados), exemplos nginx/systemd
│   └── ca/                  # install-ca, remove-ca, test-https, instalador para convidados
├── docs/                    # guia de inicialização, certificados, testes, hospedagem
├── portable/                # PHP, MariaDB, Caddy, Composer (sem instalação)
├── logs/                    # logs dos serviços (http, websocket, caddy, mysql)
├── vendor/                  # dependências do Composer
├── .env.example
├── composer.json
└── README.md
```

---

## Arquitetura

### Backend (PHP)
- **API REST**: criação, entrada, saída e status das salas.
- **Servidor WebSocket**: sinalização em tempo real para WebRTC (SDP, candidatos ICE), chat e comandos de moderação do anfitrião (silenciar, câmera, remover), autorizados pelo id de sessão do criador da sala.
- **Banco de dados**: persistência de salas e participantes (MariaDB/MySQL).
- **Sessões**: sessões PHP para identificação no servidor.
- **Caddy**: terminação TLS e proxy reverso `/ws` para o servidor WebSocket.

### Frontend (JavaScript puro)
- **WebRTC**: áudio, vídeo e tela ponto a ponto.
- **APIs de mídia**: câmera, microfone e captura de tela.
- **ES6 modular**: separação clara de responsabilidades, sem frameworks.

### Fluxo de sinalização
1. O usuário entra na sala pela API REST.
2. O navegador abre um WebSocket (mesma origem `wss://<host>/ws`; recorre a `ws://<host>:8182` em páginas HTTP).
3. O servidor notifica os outros participantes e retorna a lista de participantes.
4. As conexões ponto a ponto WebRTC são criadas (topologia em malha).
5. Ofertas/respostas SDP e candidatos ICE são trocados pelo WebSocket.
6. A mídia flui diretamente entre os participantes (malha); a sinalização nunca transporta mídia.

---

## Notas de desempenho

Medições na máquina de referência após os ajustes atuais:

| Item | Valor |
|---|---|
| 10 requisições à página inicial (via Caddy) | ~0,9 s |
| TTFB da página inicial | 20–50 ms |
| Carregamento completo da sala | ~350 ms |
| Reconexão do WebSocket após o servidor voltar | ~1 s |
| Coleta de ICE com os servidores STUN padrão | ~1,1 s |

- O `opcache` está habilitado para CLI em `portable/php/php.ini` (`opcache.enable_cli=1`) — é isso que mantém o servidor HTTP PHP rápido.
- O `bin\start-host.bat` aquece o servidor (duas requisições) para que o primeiro convidado não encontre o processo frio.
- O cliente reconecta com backoff de 300 ms → 5 s e tenta novamente imediatamente quando a aba volta ao foco ou a rede retorna.
- O `php -S` é **de thread única** (uma requisição por vez): adequado para cerca de 10 participantes, não substitui Nginx/Apache.

---

## Solução de problemas

| Sintoma | Causa | Correção |
|---|---|---|
| `start-host.bat` fecha na hora | uma porta já está em uso por uma execução anterior | o console agora pausa com o erro; feche as janelas antigas dos serviços ou execute `taskkill /F /IM php.exe` |
| HTTP/HTTPS funciona, mas a sala fica em "Reconectando..." | WebSocket inacessível ou certificado não confiável | instale a CA (`deploy\ca\dist\install-ca.bat`) e reinicie o navegador; execute `deploy\ca\test-https.bat <IP>` |
| Convidado: "site não é seguro", câmera/microfone bloqueados | CA não instalada, navegador não reiniciado, link HTTP ou Firefox | consulte [`docs/CERTIFICADOS.md`](docs/CERTIFICADOS.md) §7.1 |
| Entrada falha / sala nunca abre | avatar grande foi rejeitado pelo banco | corrigido: a coluna é `MEDIUMTEXT`, a migração roda na inicialização e o navegador reduz as fotos para 256 px (aceita até 5 MB) |
| Câmera falha com "Could not start video source" | o id da câmera salva não existe mais ou outro aplicativo está usando o dispositivo | tratado: o cliente valida o id salvo, recorre a qualquer câmera e mostra uma mensagem em PT-BR; feche OBS/Zoom/Discord se persistir |
| Tudo carrega devagar na primeira vez | processo PHP frio / sem opcache | já tratado (opcache + aquecimento); no pior caso, reinicie o `start-host.bat` |
| A mídia não conecta, embora o chat funcione | NAT/ICE, não o WebSocket | a mesma VPN deve bastar; caso contrário, configure TURN em `config/config.php` |
| O Apache do XAMPP ocupa a porta 8443 | o Apache responde no lugar do Caddy | pare o Apache (`taskkill /F /IM httpd.exe`) ou desative o serviço |

Guia completo de inicialização: [`docs/GUIA-INICIALIZACAO.md`](docs/GUIA-INICIALIZACAO.md).

---

## Documentação

| Documento | Conteúdo |
|---|---|
| [`docs/GUIA-INICIALIZACAO.md`](docs/GUIA-INICIALIZACAO.md) | guia de inicialização, checklists, desempenho e histórico de mudanças (em português) |
| [`docs/CERTIFICADOS.md`](docs/CERTIFICADOS.md) | HTTPS, CA local, instalador para convidados e diagnóstico (em português) |
| [`deploy/ca/README.md`](deploy/ca/README.md) | referência dos scripts de certificado (em português) |
| [`docs/LOCAL_HOSTING.md`](docs/LOCAL_HOSTING.md) | hospedagem com Radmin VPN e Firewall do Windows |
| [`docs/TESTING.md`](docs/TESTING.md) | roteiro de testes manuais |
| [`docs/HTTPS_RADMIN.md`](docs/HTTPS_RADMIN.md) | notas sobre HTTPS na Radmin VPN |

---

## Limitações e problemas conhecidos

1. **Topologia em malha**: WebRTC em malha completa. Acima de cerca de 6 participantes, considere um SFU (mediasoup/LiveKit).
2. **Sem gravação**: não há gravação integrada.
3. **Sem persistência do chat**: as mensagens do chat não são armazenadas.
4. **Uma sala por usuário**.
5. **Suporte a navegadores**: navegador moderno com WebRTC; o Firefox exige a importação manual da CA.
6. **Sem exposição pública**: os serviços escutam em `0.0.0.0`, mas são destinados apenas à Radmin VPN. Não exponha `8181`/`8182` à internet.
7. **Servidor PHP de thread única**: adequado para salas pequenas, não é um servidor web de produção.
8. **TURN para NATs restritivos**: o STUN cobre a maioria dos casos; caso contrário, configure TURN.

---

## Mudanças recentes

**Moderação e salas**
- Moderação do anfitrião: menu individual por participante (silenciar com sincronização do estado audível, desligar a câmera à força e remover sem reconexão automática) e ação de silenciar todos na lista de participantes; quem não é anfitrião recebe `NOT_HOST` ao forjar comandos.
- Código da sala ao lado do nome no cabeçalho — clique para copiar.
- Coluna `rooms.is_locked` adicionada (capacidade no servidor para uma futura interface de bloqueio da sala).

**Multi Screen**
- Até 4 compartilhamentos de tela simultâneos em uma galeria 16:9 adaptativa: 1 centralizado, 2 lado a lado, 3 como 2 + 1 e 4 em uma grade 2×2 estrita, sem rolagem nem estouro.
- Botão compacto de adicionar tela no canto inferior direito abre um seletor ao vivo dos participantes que estão compartilhando; limitado a 4 telas.
- Duplo clique em um bloco abre o modo de foco (cerca de 57% da largura da viewport, fundo escuro, `Esc` ou clique fora para fechar); a geometria da galeria é restaurada exatamente.
- A identificação do participante em cada bloco abre a aba individual de tela dele. Remover um bloco apenas o remove da galeria — o compartilhamento real continua.

**Sons**
- Sons personalizáveis na página inicial: `join.mp3`, `exit-call.mp3`, `mute.wav`, `unmute.wav` e `screen.mp3` (início do compartilhamento); `live-close.mp3` (fim do compartilhamento) e `message.mp3` (chat) mantêm os padrões.
- Cada som pode ser ouvido, substituído por um arquivo de áudio de até 5 MB ou removido para restaurar o padrão; os uploads são validados antes de serem salvos.
- O áudio personalizado fica somente no **IndexedDB** deste navegador (`grape_room_sounds`) e é compartilhado pela página inicial e pela sala na mesma origem; não é enviado ao servidor nem sincronizado com outros dispositivos.
- Todos os sons são reproduzidos a 35% do volume e respeitam o silenciamento de notificações da sala.

**Interface**
- Modo compacto: redesign minimalista completo (superfícies planas, paleta neutra, sem vidro/gradientes) aplicado à página inicial e à sala pelo tema compartilhado; alterne no perfil da página inicial e nas configurações da sala.
- Câmera flutuante sobre um compartilhamento de tela: redimensionável pelos quatro cantos mantendo 16:9 (150–640 px, persistido), alças visíveis apenas ao passar o mouse e botão de fechar de volta ao canto.
- A rolagem dos modais foi movida para o corpo, para que a barra de rolagem nunca corte os cantos arredondados.

**Dados e preferências**
- As preferências agora persistem no servidor por navegador (cookie `grape_pref` + tabela `user_preferences`), com localStorage primeiro; a paleta de cores do avatar foi removida (padrão sólido).
- "Apagar meus dados" (botão vermelho no modal de configurações) apaga preferências, salas criadas, sessões **e sons de notificação personalizados**, voltando ao estado inicial; o `bin\reset-user-data.bat` apaga os dados do lado do servidor.
- As sessões PHP foram movidas para `data/sessions`, para que o reset possa apagá-las sem tocar na pasta temporária do sistema.
- Limite de foto aumentado para 5 MB, com timeout de decodificação de 15 s; as fotos continuam sendo reduzidas para JPEG de 256 px antes do armazenamento.
- A abertura da câmera agora valida o id do dispositivo salvo e recorre a alternativas (720p → 480p → qualquer câmera), com mensagens de erro em PT-BR.

**Runtime portátil**
- O projeto agora inclui PHP, MariaDB, Caddy e Composer em `portable/`; o `start-host.bat` não precisa de instalação e só recorre ao `PATH` se uma pasta estiver ausente.
- O banco incluído já vem inicializado, e o `database/migrate.php` roda na inicialização.

**HTTPS / certificados**
- O host confia automaticamente na CA do Caddy (armazenamento do usuário atual, sem administrador).
- Os convidados recebem **um único** `install-ca.bat` com o certificado embutido — não é preciso enviar um `.crt` separado.
- Adicionados `test-https.bat` (diagnóstico de confiança) e `remove-ca.bat`.

**Confiabilidade**
- `avatar_data` ampliado para `MEDIUMTEXT`; a entrada não falha mais quando a foto de perfil é grande.
- As fotos de perfil são reduzidas para JPEG de 256 px no navegador (isso também contorna o limite de 1 MB por mensagem do Ratchet).
- O cliente WebSocket agora tenta o proxy `/ws` na mesma origem e o endpoint direto `:8182`, lembra qual funciona, continua tentando em vez de desistir e mostra "Reconectando... (n)".
- Reconexão mais rápida (backoff de 300 ms → 5 s) e nova tentativa imediata quando a aba volta ao foco.

**Correções anteriores (comunicação multiplayer)**
- Exatamente uma conexão ponto a ponto por par de participantes, para que todos possam publicar microfone/câmera.
- Substituição/remoção de faixas de compartilhamento de tela tratada corretamente.
- Broadcast de `chat-message` com identificação do remetente, horários e limite de 500 caracteres.
- Entrada transacional (`SELECT ... FOR UPDATE`) que aplica o limite de participantes.

---

## Licença

Licença MIT.
