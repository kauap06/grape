# Certificado HTTPS e instalação nos convidados

Este documento explica como o Grape sobe **sem instalar nada** (PHP, MariaDB,
Caddy e Composer portáteis) e como funciona o certificado HTTPS que libera
câmera, microfone e compartilhamento de tela nos navegadores.

Câmera/microfone/tela **só funcionam em HTTPS** (ou `localhost`). Por isso o
Grape usa Caddy com HTTPS local, e a confiança no certificado precisa existir em
cada PC. Esse é o único passo manual — e ele foi reduzido a **um arquivo** para
os convidados.

---

## 1. O que é portátil

| Componente | Onde fica | Precisa instalar? |
|---|---|---|
| PHP 8.3 | `portable/php` (com `php.ini` e CAs para curl/openssl) | não |
| MariaDB 11.4 | `portable/mariadb` (banco em `portable/mariadb/data`) | não |
| Caddy | `portable/caddy` | não |
| Composer | `portable/composer/composer.phar` | não |

O `bin/start-host.bat` encontra tudo em `portable/`, roda as migrações do banco
e sobe os serviços. A pasta do projeto pode ser copiada para outro PC Windows e
funciona igual.

Fora do projeto (e normal): **Radmin VPN** (conecta os PCs), um **navegador**
com WebRTC e o **runtime do Visual C++** do Windows (já presente na maioria;
num Windows limpo pode ser necessário o "Visual C++ 2015-2022 Redistributable
x64").

---

## 2. Como o HTTPS é montado

O `deploy/Caddyfile` escuta em `https://<IP do Radmin>` e `https://localhost`
e repassa tudo para o PHP/WebSocket:

```
https://<IP>, https://localhost {
    tls internal
    handle /ws* { reverse_proxy 127.0.0.1:8081 }   # WebSocket
    handle      { reverse_proxy 127.0.0.1:8080 }   # PHP
}
```

- `tls internal` significa que o **próprio Caddy** cria uma CA local e emite o
  certificado do site. Essa CA fica em:

  ```
  %APPDATA%\Caddy\pki\authorities\local\root.crt
  ```

- Sem confiar nessa CA, o navegador mostra aviso de certificado e, em vários
  casos, **recusa o WebSocket** (`wss://`) — a sala fica em "Reconectando...".
- Como a página é HTTPS, o WebSocket também é `wss://` na mesma origem (`/ws`),
  o que evita depender da porta `8081` liberada no firewall.

> Existe também uma CA própria opcional em `deploy/ca` (`generate-root-ca.bat`,
> `issue-cert.bat`). Ela é um complemento: só passa a valer se o `tls internal`
> for trocado pelos certificados emitidos por ela.

---

## 3. No host: automático

Ao rodar `bin/start-host.bat`, depois de subir os serviços, o script:

1. confia na CA do Caddy **para o usuário atual** (`certutil -user`, sem
   administrador);
2. deixa uma cópia em `deploy\ca\dist\caddy-root.crt`;
3. gera o instalador de convidados `deploy\ca\dist\install-ca.bat`.

Saída esperada no console:

```
Certificado HTTPS confiado neste PC (CA do Caddy).
...
Certificate: a CA do Caddy foi confiada neste PC automaticamente.
  Para os convidados nao verem aviso de certificado, envie UM arquivo:
    deploy\ca\dist\install-ca.bat
```

É idempotente: rodar de novo não duplica nada e não pede nada.

---

## 4. Nos convidados: um arquivo só

Envie **apenas** `deploy\ca\dist\install-ca.bat`. O convidado dá duplo clique:

```
Instalando o certificado HTTPS do Grape neste PC (sem administrador)...
Pronto! Este PC agora confia no certificado do Grape.
```

- Não precisa do `.crt` separado: o certificado do host vai **embutido** no
  `.bat` (variável `GRAPE_CA_B64`).
- Não precisa de administrador: instala na loja do usuário atual.
- Depois de instalar, basta abrir o link `https://<IP do Radmin>/?code=...`.

Se o arquivo for gerado de novo (porque a CA do host mudou), **reenvie** o
`install-ca.bat` atualizado aos convidados.

---

## 5. Uso manual (quando precisar)

```bat
REM Host: confia na CA do Caddy e cria as copias para distribuir
deploy\ca\install-ca.bat

REM Convidado: instala o certificado embutido (arquivo unico)
deploy\ca\dist\install-ca.bat

REM Convidado: instala a partir de um arquivo .crt recebido
deploy\ca\install-ca.bat caddy-root.crt

REM Gera de novo o instalador de convidado (o start-host ja faz isso)
deploy\ca\make-guest-installer.bat

REM Sem mensagens e sem pause (usado pelo start-host)
deploy\ca\install-ca.bat /silent

REM Desfazer a confianca neste PC
deploy\ca\remove-ca.bat
```

`install-ca.bat` procura o certificado nesta ordem: o caminho informado,
`caddy-root.crt` ao lado do `.bat`, `dist\caddy-root.crt` e, por último, a CA do
Caddy do próprio PC.

---

## 6. Conferir e remover

```bat
REM Ver a CA instalada para o usuario atual
certutil -user -store ROOT

REM Remover (desfaz a confianca)
deploy\ca\remove-ca.bat
```

Atenção: `certutil -user -delstore ROOT "Caddy Local Authority"` (por **nome**)
diz que deu sucesso mas **não remove** da loja Root. Por **thumbprint** funciona,
e é o que o `remove-ca.bat` faz.

Teste rápido: abra `https://localhost` — deve carregar **sem** aviso de
certificado.

---

## 7. Solução de problemas

| Sintoma | Causa provável | Solução |
|---|---|---|
| `install-ca.bat` pedia administrador mesmo abrindo como admin | o teste usava `net session`, que falha com o serviço **LanmanServer** parado | já corrigido: instala via `certutil -user` (sem admin) e testa elevação com `fltmc` |
| `CertUtil: -addstore FALHOU: ERROR_INVALID_NAME` | `certutil -addstore` não lê direto o `.crt` em PEM gerado pelo Caddy | já tratado: o script converte para DER (`certutil -decode`) antes de instalar |
| `install-ca.bat /silent` ficou parado | tentou elevar para admin e abriu um UAC invisível | já tratado: em `/silent` nunca pede elevação, apenas avisa e sai com erro |
| `ERRO: este arquivo nao foi gerado corretamente` | o `.bat` é o modelo, não o gerado | rode `deploy\ca\make-guest-installer.bat` no host e envie o de `dist\` |
| `ERRO: nenhuma CA encontrada` | o host nunca rodou o Caddy | rode `bin\start-host.bat` uma vez e depois o `install-ca.bat` |
| Navegador ainda avisa / fica em "Reconectando..." | certificado não confiável naquele PC | rode o `install-ca.bat` e **feche/reabra** o navegador |
| `remove-ca.bat` diz "nenhum certificado encontrado" mas o aviso continua | o certificado foi instalado para o computador inteiro | remova em `certlm.msc` (loja LocalMachine) |
| Convidado não abre nada | firewall do host | libere TCP **443** (e 8080/8081 se usar o link HTTP) |
| O aviso voltou depois de dias | a CA do Caddy foi recriada (por exemplo, `%APPDATA%\Caddy` apagado) | rode `bin/start-host.bat` e reenvie o novo `install-ca.bat` |
| Convidado: "site não é seguro" e câmera/micro bloqueados | CA não instalada, navegador não reiniciado, link HTTP ou **Firefox** | rode `deploy\ca\test-https.bat` no PC dele (ver seção 7.1) |


### 7.1 Convidado diz que o site "não é seguro"

Rode **no PC do convidado** (não no host):

```bat
deploy\ca\test-https.bat 26.22.182.86
```

Ele responde três coisas:

1. se a CA do host está na loja do Windows (usuário / computador);
2. se o Windows aceita o certificado de `https://<IP>` (teste real de TLS);
3. qual é o navegador padrão — e avisa se for **Firefox**.

Como interpretar:

| Resultado | O que fazer |
|---|---|
| `usuario atual: AUSENTE` | rode o `install-ca.bat` que o host enviou |
| `usuario atual: OK` + `Conexao HTTPS: FALHOU` | a CA do host mudou (Caddy recriado) → peça o `install-ca.bat` novo; ou o IP mudou |
| `usuario atual: OK` + `OK - HTTP 200` | o PC está certo: **feche todas as janelas do navegador** e abra de novo |
| navegador padrão = **Firefox** | o Firefox usa a loja **dele**, não a do Windows: use Chrome/Edge, ou importe em `Firefox > Configurações > Privacidade e Segurança > Certificados > Ver Certificados > Autoridades > Importar` |
| abre `http://IP:8080` em vez de `https://IP` | o link HTTP nunca é "seguro": câmera/micro/tela só funcionam no link `https://` |

Checklist rápido para o convidado:

- [ ] instalou o `install-ca.bat` (e viu "Pronto!")?
- [ ] fechou **todas** as janelas do navegador depois disso?
- [ ] está abrindo o link que começa com **https://**?
- [ ] o navegador é Chrome, Edge ou Brave (Chromium)? Firefox precisa de passo extra.

---

## 8. Arquivos envolvidos

```
bin/start-host.bat                     # confia na CA e gera o instalador de convidado
deploy/Caddyfile                       # HTTPS em 443 + proxy /ws
deploy/ca/install-ca.bat               # instala a CA (host) / um .crt (convidado)
deploy/ca/remove-ca.bat                # desfaz a confianca (remove por thumbprint)
deploy/ca/test-https.bat               # diagnostico no PC do convidado
deploy/ca/make-guest-installer.bat     # gera o instalador de arquivo unico
deploy/ca/make-guest-installer.ps1     # injeta o certificado no modelo
deploy/ca/guest-installer-template.bat # modelo do instalador embutido
deploy/ca/dist/install-ca.bat          # GERADO: envie este aos convidados
deploy/ca/dist/caddy-root.crt          # GERADO: copia da CA do host (opcional)
portable/php/extras/ssl/cacert.pem     # CAs publicas para curl/openssl do PHP
```

Os arquivos de `deploy/ca/dist/` são específicos de cada host e estão no
`.gitignore`.
