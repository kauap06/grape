# HTTPS local com Caddy (Radmin VPN)

## Por que isso é necessário

Navegadores só liberam **câmera, microfone e compartilhamento de tela** em contexto seguro (HTTPS ou `localhost`).
Pelo Radmin VPN o site é aberto como `http://26.x.x.x:8080`, que não é considerado seguro. Por isso `navigator.mediaDevices`
fica indefinido e aparece o erro:

```
Erro ao compartilhar tela: Cannot read properties of undefined (reading 'getDisplayMedia')
```

A solução é colocar o **Caddy** na frente do site. Ele recebe as conexões HTTPS e repassa para os servidores que já existem.
Nada do PHP, do banco ou do WebSocket é substituído.

```
Convidado ──https://26.x.x.x──▶ Caddy (443) ──/ws──▶ WebSocket (8081)
                                          └─ /   ──▶ PHP (8080)
```

## Arquivos

| Arquivo | Local no projeto | Ação |
|---|---|---|
| `room.js` | `public/assets/js/room.js` | substitui o existente |
| `Url.php` | `src/Support/Url.php` | substitui o existente |
| `start-host.bat` | `bin/start-host.bat` | substitui o existente |
| `Caddyfile` | `deploy/Caddyfile` | novo |
| `liberar-permissoes.bat` | `deploy/liberar-permissoes.bat` | novo (opcional) |
| `HTTPS_RADMIN.md` | `docs/HTTPS_RADMIN.md` | novo (este guia) |

### O que cada alteração faz

- **`room.js`**: não quebra mais quando `mediaDevices` ou `getDisplayMedia` não existem. Mostra uma mensagem explicando o motivo
  (falta de HTTPS ou navegador sem suporte) em vez do erro técnico.
- **`Url.php`**: em HTTPS, o WebSocket passa a usar `wss://<host>/ws` (sem porta). Em HTTP continua `ws://<host>:8081`.
- **`Caddyfile`**: envia `/ws` para a porta 8081 e o resto para a 8080. Lê o IP do Radmin da variável `RADMIN_IP`.
- **`start-host.bat`**: além do PHP e do WebSocket, detecta sozinho o IP do Radmin (o que começa com `26.`) e inicia o Caddy em uma terceira janela, "Grape HTTPS (Caddy)". Não é preciso rodar mais nada.

## Configuração (uma vez só)

1. **Instalar o Caddy**, no PowerShell:
   ```
   curl.exe https://webi.ms/caddy | powershell
   ```
   O webi instala em `%USERPROFILE%\.local\bin`. Feche e abra o terminal depois. Os scripts `.bat` já procuram o Caddy nessa pasta,
   então funcionam mesmo se o PATH ainda não estiver atualizado.

2. **Liberar a porta 443 no firewall**, no Prompt de Comando como administrador:
   ```
   netsh advfirewall firewall add rule name="Grape HTTPS 443" dir=in action=allow protocol=TCP localport=443
   ```
   Se a porta 443 já estiver em uso (por exemplo pelo Apache do XAMPP), pare esse programa antes.

## Uso diário

1. Abra o **Radmin VPN** e conecte na rede.
2. Dê dois cliques em `bin/start-host.bat`. Ele abre três janelas:
   - Grape HTTP (8080)
   - Grape WebSocket (8081)
   - Grape HTTPS (Caddy)
   O endereço para os convidados (`https://26.x.x.x`) aparece no resumo final do script.
3. **Você** acessa `https://localhost` (ou `http://localhost:8080`).
4. **Convidados** acessam `https://26.x.x.x` (o IP mostrado no resumo do `start-host.bat`).
5. Na primeira vez, o navegador mostra um aviso de certificado. Clique em **Avançado → Continuar**.
   Isso é normal: o certificado é gerado localmente pelo Caddy.
6. Para parar, feche as três janelas.

## Liberar permissões automaticamente (opcional)

O script `deploy/liberar-permissoes.bat` grava políticas do Chrome e do Edge no registro do Windows. Com elas, câmera e microfone
são liberados sem a pergunta do navegador para o endereço do host. Rode **uma vez em cada PC** (o seu e o de cada convidado):

1. Clique com o botão direito em `liberar-permissoes.bat` e escolha **Executar como administrador**.
2. Digite o IP do Radmin do host (ex.: `26.12.34.56`).
3. Feche **todas** as janelas do navegador e abra de novo.
4. Confira em `chrome://policy` (ou `edge://policy`) se as políticas aparecem.

O que o script configura, para `https://<IP>`:

| Política | Efeito |
|---|---|
| `VideoCaptureAllowedUrls` | câmera liberada sem perguntar |
| `AudioCaptureAllowedUrls` | microfone liberado sem perguntar |
| `ScreenCaptureAllowedByOrigins` | permite o compartilhamento de tela para o endereço |
| `OverrideSecurityRestrictionsOnInsecureOrigin` | trata `http://<IP>:8080` como seguro (dispensa o HTTPS do Caddy) |

Limites:
- O seletor de tela/janela do compartilhamento continua aparecendo, por segurança do navegador.
- O navegador mostra "gerenciado pela sua organização". É normal.
- Funciona em Windows com Chrome e Edge. Brave e Opera costumam não ler essas chaves; no Firefox e no celular não vale.
- Para remover, apague as chaves criadas em `HKLM\SOFTWARE\Policies\Google\Chrome` e `HKLM\SOFTWARE\Policies\Microsoft\Edge`
  (`VideoCaptureAllowedUrls`, `AudioCaptureAllowedUrls`, `ScreenCaptureAllowedByOrigins` e `OverrideSecurityRestrictionsOnInsecureOrigin`).
  Apagar as chaves inteiras do Chrome e do Edge também remove outras políticas que existirem.

## Problemas comuns

| Sintoma | O que verificar |
|---|---|
| Convidado não consegue abrir o site | Firewall da porta 443 e se o Radmin está conectado nos dois PCs |
| O script diz que não achou o IP do Radmin | Abra e conecte o Radmin VPN antes de rodar; ele sobe só com `https://localhost` |
| O Caddy não inicia | A porta 443 pode estar ocupada por outro programa |
| Ainda aparece erro de mídia | Confirme que a barra de endereço mostra `https://` e use Ctrl+F5 |
| O IP do Radmin mudou | Rode o `start-host.bat` de novo; os convidados aceitam o aviso de certificado mais uma vez |
| Abrindo pelo Discord/Instagram | Navegadores embutidos costumam bloquear; use Chrome, Edge ou Firefox |

## Alternativas (sem Caddy)

- **Cloudflare Tunnel**: `cloudflared tunnel --url http://localhost:8080` gera um link `https://...trycloudflare.com`.
  Também exige o WebSocket em `wss`.
- **Flag do Chrome em cada PC**: em `chrome://flags/#unsafely-treat-insecure-origin-as-secure`, adicionar
  `http://26.x.x.x:8080`. Funciona, mas é manual e não vale para celular.
