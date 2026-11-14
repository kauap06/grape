# Grape — Guia de inicialização e histórico de ajustes

Documento único com: como subir o Grape **sem instalar nada**, o que fazer no
host e nos convidados, e o resumo de tudo que foi corrigido nesta rodada de
ajustes (portabilidade, certificado HTTPS, reconexão e banco).

- Público-alvo: quem vai hospedar (host) e quem entra na sala (convidado).
- Plataforma: Windows.
- Rede: Radmin VPN.

---

## 1. Início rápido (host)

```bat
cd C:\xampp\htdocs\grape-new
bin\start-host.bat
```

O script, sozinho:

1. usa o PHP/MariaDB/Caddy/Composer de `portable\` (nada instalado no Windows);
2. inicializa o banco na primeira execução e aplica migrações;
3. sobe MariaDB (3306), HTTP PHP (8080), WebSocket (8081) e Caddy (443);
4. confia na CA do Caddy neste PC (sem administrador);
5. gera `deploy\ca\dist\install-ca.bat` para os convidados;
6. mostra o link da sala e o IP do Radmin.

Depois abra no host:

```
https://localhost
```

---

## 2. Convidados

1. Recebem **um único arquivo**: `deploy\ca\dist\install-ca.bat`.
2. Dão duplo clique (não precisa de administrador).
3. Abrem o link que o host enviou: `https://<IP do Radmin>/?code=CÓDIGO`.

> Sem esse passo o navegador avisa do certificado e o WebSocket `wss://` pode
> ser recusado — a sala fica em "Reconectando...".

Detalhes completos do certificado: [`docs/CERTIFICADOS.md`](CERTIFICADOS.md).

---

## 3. Portas e serviços

| Porta | Serviço | Para quê |
|---|---|---|
| 3306 | MariaDB portátil | salas e participantes |
| 8080 | PHP (servidor embutido) | páginas e API REST |
| 8081 | WebSocket (Ratchet) | sinalização WebRTC e chat |
| 443 | Caddy | HTTPS + proxy de `/ws` |

- Host local: `http` em `8080`, `https` em `443`.
- Convidados: **usem o link `https`** (câmera/microfone/tela só funcionam em
  HTTPS).
- Firewall do host: liberar TCP **443** (e 8080/8081 se usar o link HTTP).

---

## 4. O que é portátil

| Componente | Caminho | Observação |
|---|---|---|
| PHP 8.3 | `portable\php` | `php.ini` pronto + `extras\ssl\cacert.pem` |
| MariaDB 11.4 | `portable\mariadb` | banco em `portable\mariadb\data` |
| Caddy 2.11 | `portable\caddy` | HTTPS local (`tls internal`) |
| Composer | `portable\composer\composer.phar` | roda com o PHP portátil |

A pasta do projeto pode ser copiada para outro PC Windows e funciona igual.
Fora do projeto: Radmin VPN, navegador com WebRTC e o runtime do Visual C++
(este último já vem na maioria dos Windows).

---

## 5. Comandos do dia a dia

```bat
REM Subir tudo (host)
bin\start-host.bat

REM Desenvolvimento (HTTP 8000 / WS 8081, sem Caddy)
bin\start-dev.bat

REM Logs
type logs\http.log
type logs\websocket.log
type logs\websocket-error.log
type logs\caddy.log
type logs\mysql.log

REM Banco (usuario/senha definidos em config\local.php)
portable\mariadb\bin\mariadb.exe -h 127.0.0.1 -u grape -p grape_calls

REM Reaplicar migrações (o start-host já faz)
portable\php\php.exe database\migrate.php

REM Certificado
deploy\ca\install-ca.bat          REM host: confia e gera o instalador
deploy\ca\install-ca.bat /silent  REM sem mensagens (usado pelo start-host)
deploy\ca\remove-ca.bat           REM desfaz a confiança
deploy/ca/test-https.bat <IP>       REM diagnostico: este PC confia no certificado?
```

Para parar: feche as janelas `Grape MariaDB (3306)`, `Grape HTTP (8080)`,
`Grape WebSocket (8081)` e `Grape HTTPS (Caddy)` — ou:

```bat
taskkill /F /IM php.exe & taskkill /F /IM caddy.exe & taskkill /F /IM mysqld.exe
```

---

## 6. Checklist da primeira execução

- [ ] `bin\start-host.bat` rodou sem erro (nenhum "ERROR:" no console).
- [ ] Portas 3306/8080/8081/443 escutando.
- [ ] `https://localhost` abre **sem** aviso de certificado.
- [ ] `deploy\ca\dist\install-ca.bat` existe (envie aos convidados).
- [ ] Convidado rodou o `.bat` e fechou/reabriu o navegador.
- [ ] Sala criada, convidado entrou e o cronômetro anda (sem "Reconectando").

---

## 7. Problemas encontrados e corrigidos

Cada linha é um problema **real** que apareceu durante os testes, com a causa e
a correção aplicada.

### 7.1 Portabilidade / inicialização

| Sintoma | Causa | Correção |
|---|---|---|
| `start-host.bat` fechava na hora | `exit /b 1` sem `pause` nos erros | todos os caminhos de erro dão `pause` |
| "Porta 8080/8081 em uso" mesmo sem nada rodando | `netstat \| findstr ":8081 "` casava conexões remotas e `TIME_WAIT` | filtro passou a exigir `LISTENING` |
| Script travava/errava ao ser chamado por outro script | `timeout /t` falha com stdin redirecionado | trocado por `ping -n` |
| Dependia de PHP/MySQL instalados | — | binários portáteis em `portable\`, com fallback para PATH |

### 7.2 Certificado HTTPS

| Sintoma | Causa | Correção |
|---|---|---|
| `install-ca.bat` pedia admin mesmo elevado | `net session` falha com o serviço **LanmanServer** parado | instala via `certutil -user` (sem admin); elevação testada com `fltmc` |
| Instalador não achava o certificado certo | o Caddy usa `tls internal` (CA dele, não a `grape-root.crt`) | script detecta a CA do Caddy em `%APPDATA%\Caddy\pki\...` |
| Convidado precisava receber `.crt` | certificado em arquivo separado | `make-guest-installer.bat` gera um `.bat` com o certificado embutido |
| Instalador dizia "Pronto!" sem instalar | os marcadores `BEGIN/END` apareciam no próprio script e a extração pegava o texto errado | certificado embutido como base64 em `GRAPE_CA_B64`, com validação |
| `CertUtil: -addstore FALHOU: ERROR_INVALID_NAME` | `certutil -addstore` não lê o PEM do Caddy direto | converte para DER (`certutil -decode`) antes de instalar |
| `/silent` travava | abria pedido de UAC invisível | em `/silent` nunca eleva: avisa e sai com erro |
| Remoção "com sucesso" que não removia | `certutil -delstore ROOT "Nome"` não remove da loja Root | `remove-ca.bat` remove por **thumbprint** |
| Convidado: "site nao e seguro", mic/camera bloqueados | CA nao instalada, navegador nao reiniciado, link HTTP ou **Firefox** (loja propria) | rode `deploy/ca/test-https.bat <IP>` no PC dele; ver `docs/CERTIFICADOS.md` 7.1 |

### 7.3 Sala / WebSocket / banco

| Sintoma | Causa | Correção |
|---|---|---|
| Sala ficava em "Reconectando..." para sempre | cliente tinha **um** endereço fixo e desistia em silêncio | lista de candidatos (`wss://host/ws` ⇄ `ws://host:8081`), reaproveita o que funcionou, segue tentando e mostra `Reconectando... (n)` |
| WebSocket fechava ao entrar com foto de perfil | Ratchet limita mensagem a **1 MB**; foto de 2 MB → ~2,7 MB | avatar reduzido para 256 px JPEG no navegador (e reenvio via `profile-update`) |
| `PDOException ... 1406 Data too long for column 'avatar_data'` | coluna era `TEXT` (64 KB) | `MEDIUMTEXT` + migração automática + cliente reduz avatar antigo ao abrir a sala |
| `join` falhava e nada aparecia | erro do banco abortava o `handleJoin` | avatar acima de 8 MB é descartado em vez de derrubar a entrada |
| Redução do avatar não acontecia | `room.js` não importava `Utils` | import corrigido |
| Avatar grande atrasava a conexão | `await` da redução antes de conectar | conexão primeiro; redução em segundo plano |

---

## 7.2 Desempenho (por que às vezes "demora" para abrir)

Medições feitas nesta máquina, com as correções aplicadas:

| Item | Antes | Depois |
|---|---|---|
| 10 requisições à home (via Caddy) | 2,12 s | **0,90 s** |
| Home page (TTFB) | ~100 ms | ~20-50 ms |
| Room page (load completo) | — | **~350 ms** |
| Reconexão do WebSocket após o servidor voltar | até ~8 s | **~1 s** |
| Coleta de ICE (STUN do Google) | — | ~1,1 s |

O que foi ajustado:

- **opcache habilitado** no PHP portátil (`portable/php/php.ini`:
  `opcache.enable_cli=1`), que é o que acelera o servidor embutido;
- **reconexão mais rápida**: espera inicial de 300 ms com teto de 5 s (antes
  500 ms com teto de 8 s);
- **retomada imediata** ao voltar para a aba ou quando a rede volta
  (`visibilitychange` / `online`), sem esperar o intervalo;
- **aquecimento no start**: o `start-host.bat` faz duas requisições à home
  depois de subir tudo, para o primeiro convidado não pegar o servidor "frio".

Observações:

- O `php -S` é **single-thread** (uma requisição por vez). Isso é aceitável para
  até ~10 participantes, mas não escala como Apache/Nginx.
- Se a mídia demora a aparecer (mas o chat funciona), o gargalo é o **ICE/NAT**,
  não o WebSocket: são ~1,1 s de coleta com STUN. Em Radmin VPN os candidatos
  locais bastam; se quiser eliminar essa espera, remova os `stun_servers` em
  `config/config.php` (só faça isso se todos estiverem na mesma VPN).
- Instalar o certificado e **não reiniciar o navegador** é a causa mais comum de
  "demorou para funcionar": o Chrome/Brave guarda o erro de certificado até
  fechar todas as janelas.

## 8. Manutenção

**Backup do banco** (tudo fica em uma pasta):

```bat
taskkill /F /IM mysqld.exe
xcopy /E /I portable\mariadb\data D:\backup\grape-data
```

**Mover o projeto para outro PC**: copie a pasta inteira (inclui
`portable\mariadb\data`). O `start-host.bat` reaplica as migrações.

**Se a CA do Caddy mudar** (por exemplo, `%APPDATA%\Caddy` apagado):

1. rode `bin\start-host.bat` (gera novo `deploy\ca\dist\install-ca.bat`);
2. reenvie esse arquivo aos convidados.

**Trocar senha/banco**: `config\local.php` (já fora do Git).

---

## 9. Arquivos alterados nesta rodada

```
bin/start-host.bat                     # portáteis, migrações, CA automática, portas
bin/start-dev.bat                      # usa PHP/Composer portáteis
database/schema.sql                    # avatar_data -> MEDIUMTEXT
database/migrate.php                   # NOVO: migração idempotente no start
deploy/ca/install-ca.bat               # NOVO: confia na CA (sem admin, /silent)
deploy/ca/remove-ca.bat                # NOVO: desfaz a confiança
deploy/ca/test-https.bat              # NOVO: diagnostico de HTTPS no PC do convidado
deploy/ca/test-https.ps1              # NOVO: apoio do diagnostico
deploy/ca/make-guest-installer.bat     # NOVO: gera o instalador de convidado
deploy/ca/make-guest-installer.ps1     # NOVO: injeta o certificado no modelo
deploy/ca/guest-installer-template.bat # NOVO: modelo do instalador único
public/assets/js/utils.js              # redução de avatar (canvas/JPEG)
public/assets/js/home.js               # usa a redução ao escolher foto
public/assets/js/room.js               # fallback de WS, reconexão, avatar legado
server/websocket-server.php            # comentário sobre o limite do Ratchet
src/Server/SignalingServer.php         # guarda de avatar no join
docs/CERTIFICADOS.md                   # NOVO: detalhes do certificado
docs/GUIA-INICIALIZACAO.md             # este documento
```

Gerados em tempo de execução (fora do Git):

```
deploy/ca/dist/install-ca.bat          # instalador para convidados
deploy/ca/dist/caddy-root.crt          # cópia da CA do host
portable/mariadb/data/                 # banco
logs/*.log                             # logs dos serviços
```

---

## 10. Ainda vale saber

- **Mesh WebRTC**: funciona bem até ~6 participantes; acima disso, considere um
  SFU (LiveKit/mediasoup).
- **Signaling ≠ mídia**: áudio/vídeo vão direto entre os PCs (P2P). Se não
  conectarem, o problema é NAT/rede, não o WebSocket — configure TURN em
  `config/config.php` se necessário.
- **Não exponha** 8080/8081 na internet: o foco é Radmin VPN.
- **Chat não é persistido** no banco.
