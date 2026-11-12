# Hospedagem Local com Radmin VPN — Grape

Este documento explica como executar o **Grape** localmente, permitindo que o computador anfitrião (host) abrisse uma sala e outros participantes conectados à mesma rede **Radmin VPN** possam acessá-la.

---

## 1. Requisitos

- PHP 8.2 ou superior (XAMPP recomendado no Windows)
- Dependências do Composer instaladas (`vendor/` presente)
- MySQL/MariaDB (para persistência de salas — já incluso no XAMPP)
- Radmin VPN instalado em **todos** os computadores (host e convidados)
- Navegador moderno com suporte a WebRTC (câmera, microfone, compartilhamento de tela)

---

## 2. Conectar todos os participantes à mesma rede Radmin VPN

1. Instale o Radmin VPN em cada computador.
2. Todos devem entrar na **mesma rede** (mesmo Network ID e senha).
3. Aguarde até que todos apareçam como online na rede do Radmin VPN.
4. O computador host deverá usar o seu **endereço IPv4 da rede Radmin VPN** para expor a aplicação.

---

## 3. Iniciar a aplicação com `bin/start-host.bat`

No computador host:

1. Abra o prompt de comando (PowerShell ou cmd) na pasta do projeto.
2. Execute:

   ```bat
   bin/start-host.bat
   ```

O script fará o seguinte:

- Detecta automaticamente a raiz do projeto.
- Detecta o PHP (preferindo `C:\xampp\php\php.exe`; caso contrário, usa o PATH do sistema).
- Verifica que as portas `8080` e `8081` estão livres.
- Inicia o **PHP HTTP server** em `0.0.0.0:8080` (janela separada).
- Inicia o **WebSocket server** em `0.0.0.0:8081` (janela separada).
- Exibe os endereços locais e instruções para encontrar o IPv4 da VPN.

Os serviços ficam em janelas de terminal independentes. Não feche o script enquanto quiser manter os serviços no ar.

Comandos equivalentes executados pelo script:

```bat
php -S 0.0.0.0:8080 -t public
php server/websocket-server.php
```

---

## 4. Acesso local (host)

No próprio computador host, abra:

```
http://localhost:8080
```

---

## 5. Acesso remoto pelos outros usuários (Radmin VPN)

Cada convidado conectado à mesma rede Radmin VPN deve acessar:

```
http://<HOST_VPN_IP>:8080
```

Substitua `<HOST_VPN_IP>` pelo endereço IPv4 do host na rede Radmin VPN.

O WebSocket de sinalização será acessado automaticamente pelo navegador dos convidados através de:

```
ws://<HOST_VPN_IP>:8081
```

O endereço é gerado dinamicamente a partir do host da página atual, portanto não há `localhost` hardcoded nas conexões WebSocket do lado do cliente.

---

## 6. Encontrar o IPv4 do host na rede Radmin VPN

1. Abra o Prompt de Comando.
2. Execute:

   ```cmd
   ipconfig
   ```

3. Procure pelo adaptador de rede virtual do Radmin VPN (geralmente chamado "Radmin Virtual Network Adapter" ou similar).
4. Anote o **Endereço IPv4** (por exemplo, `26.x.x.x`).

Esse é o `<HOST_VPN_IP>` que os convidados usarão.

---

## 7. Configurar o Firewall do Windows para TCP 8080 e 8081

Para que outros computadores da rede Radmin VPN consigam acessar, libere as portas no Firewall do Windows do host:

### Via Prompt de Comando (administrador)

```cmd
netsh advfirewall firewall add rule name="Grape HTTP 8080" dir=in action=allow protocol=TCP localport=8080
netsh advfirewall firewall add rule name="Grape WebSocket 8081" dir=in action=allow protocol=TCP localport=8081
```

### Via interface gráfica

1. Painel de Controle > Sistema e Segurança > Windows Defender Firewall > Configurações Avançadas.
2. Regras de Entrada > Nova Regra > Porta > TCP > portas `8080` e `8081`.
3. Permitir a conexão > aplicar a todos os perfis > dê um nome à regra.

**Importante:** abra essas portas apenas para a rede privada/Radmin VPN. Não exponha as portas `8080` e `8081` à internet pública.

---

## 8. Parar os servidores

- Feche a janela do terminal "Grape HTTP (8080)" ou pressione `Ctrl+C` dentro dela.
- Feche a janela do terminal "Grape WebSocket (8081)" ou pressione `Ctrl+C` dentro dela.

Os logs ficam em `logs/http.log` e `logs/websocket.log`.

---

## 9. Problemas comuns e soluções

| Problema | Solução |
|----------|---------|
| Porta 8080 ou 8081 já em uso | Feche o programa que está usando a porta ou altere a configuração. |
| `php` não encontrado | Instale o XAMPP ou adicione o PHP ao PATH do sistema. |
| Convidado não acessa `http://<HOST_VPN_IP>:8080` | Confirme que o host está com o Radmin VPN ativo, que o convidado está na mesma rede, que o Firewall libera TCP 8080 e que o PHP server escuta em `0.0.0.0` (não apenas `127.0.0.1`). |
| WebSocket não conecta | Confirme que o Firewall libera TCP 8081 e que o servidor WebSocket está rodando. O endereço é gerado dinamicamente a partir do hostname da página. |
| Sala não cria / não entra | Verifique se o MySQL está rodando e se o banco de dados foi criado com `database/schema.sql`. |
| Câmera/microfone não funcionam | WebRTC exige HTTPS em produção, mas em `localhost` e redes locais (Radmin VPN) os navegadores geralmente permitem. Certifique-se de conceder permissão ao navegador. |
| Áudio/vídeo não chega entre pares | A sinalização (PHP + WebSocket) funciona, mas a **mídia WebRTC trafega diretamente entre pares**. Se a rede Radmin VPN não permitir conexão direta entre os pares, será necessário um servidor STUN/TURN. A sinalização sozinha não garante conectividade de áudio e vídeo. |

---

## Limitação importante sobre WebRTC

A sinalização (oferta, resposta e candidatos ICE) ocorre pelo WebSocket na porta `8081`. No entanto, as conexões de mídia (áudio, vídeo, tela) são estabelecidas **peer-to-peer** diretamente entre os navegadores. Embora a rede Radmin VPN normalmente permita conexões diretas entre seus membros, em algumas configurações de NAT/firewall pode ser necessário um servidor STUN ou TURN. Caso a mídia não estabeleça, configure servidores STUN/TURN em `config/config.php` (`webrtc.stun_servers` / `webrtc.turn_servers`).

Não exponha os serviços à internet pública. O acesso pretendido é exclusivamente pela rede Radmin VPN.
