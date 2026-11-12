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
