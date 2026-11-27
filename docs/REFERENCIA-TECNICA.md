# Referência técnica

Este documento reúne detalhes de operação, configuração, banco de dados, arquitetura e histórico que não fazem parte do [README principal](../README.md). Ele é destinado a quem administra, testa ou desenvolve o Grape.

Para compilar e distribuir o aplicativo, consulte [README-BUILD.md](../README-BUILD.md). Para os procedimentos de uso, comece pelo README principal. Os caminhos abaixo são relativos à raiz do projeto.

## Portas e acesso

| Porta | Serviço | Finalidade |
|---|---|---|
| 33070 | MariaDB | salas e participantes |
| 8181 | Servidor HTTP PHP | páginas e API REST |
| 8182 | WebSocket (Ratchet) | sinalização WebRTC e chat |
| 8443 | Caddy | HTTPS + proxy reverso `/ws` |

- Host: `https://localhost:8443` (ou `http://localhost:8181` sem HTTPS).
- Convidados: **sempre o link `https://<IP_DA_VPN_DO_HOST>:8443`** — o link HTTP na `:8181` não é uma origem segura e bloqueia câmera, microfone e tela.
- Firewall do Windows no host: permita TCP **8443**. As portas 8181 e 8182 escutam somente em `127.0.0.1` e não precisam ser liberadas.

Encontre o IPv4 da Radmin VPN do host com `ipconfig` (procure o adaptador virtual da Radmin VPN). Consulte [LOCAL_HOSTING.md](LOCAL_HOSTING.md) para as regras de firewall.

---

## Configuração

As configurações de execução ficam em `config/config.php` e podem ser sobrescritas por máquina em `config/local.php` (não versionado). No primeiro start, o `database/bootstrap.php` cria `config/local.php` e o banco com uma senha aleatória. Padrões:

- banco `grape_calls` em `127.0.0.1:33070`, usuário `grape` — nenhuma senha fixa é distribuída; o bootstrap gera uma aleatória. Para definir manualmente, use `config/local.php` (copie `config/local.php.example`);
- servidor HTTP PHP `127.0.0.1:8181`, WebSocket `127.0.0.1:8182` — acessíveis somente pelo proxy Caddy na 8443;
- capacidade máxima da sala 10 (o formulário começa com 8), limite de 500 caracteres por mensagem no chat, ping do WebSocket de 25 s.

Credenciais nunca devem ficar em `config/config.php`, que é versionado. Use `config/local.php` ou as variáveis de ambiente `DATABASE_USERNAME` / `DATABASE_PASSWORD`.

Servidores STUN/TURN do WebRTC (`config/config.php`):

```php
'webrtc' => [
    'stun_servers' => [
        ['urls' => 'stun:stun.l.google.com:19302'],
        ['urls' => 'stun:stun1.l.google.com:19302'],
        ['urls' => 'stun:stun2.l.google.com:19302'],
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
deploy\ca\make-guest-installer.bat   REM regenera deploy\ca\dist\install-ca.bat
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
grape-app/
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

## Histórico de mudanças

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
- O banco incluído é inicializado no primeiro start, e o `database/migrate.php` roda na inicialização.

**HTTPS / certificados**
- O host confia na CA do Caddy pelo diálogo do Grape no primeiro uso (armazenamento do usuário atual, sem administrador).
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

## Documentação relacionada

- [README principal](../README.md)
- [Build e distribuição](../README-BUILD.md)
- [Certificados e HTTPS](CERTIFICADOS.md)
- [Hospedagem local e Radmin VPN](LOCAL_HOSTING.md)
- [Guia de inicialização](GUIA-INICIALIZACAO.md)
