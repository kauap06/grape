# Grape

O Grape é um aplicativo para conversar por voz e vídeo, compartilhar a tela e trocar mensagens com um grupo pequeno. Ele funciona em salas privadas entre pessoas que estão na mesma rede Radmin VPN: uma pessoa hospeda a sala no próprio computador e as outras entram pelo navegador.

O aplicativo é distribuído em um arquivo único, `Grape.exe`. Ele já inclui o servidor e o banco de dados que usa. Não é preciso instalar um servidor à parte nem criar uma conta.

## O que o Grape oferece

### Voz e vídeo

Cada participante pode ligar e desligar o próprio microfone e a própria câmera. Durante a chamada, o Grape destaca quem está falando e permite escolher os dispositivos de áudio e vídeo nas configurações.

### Compartilhamento de tela

Qualquer participante pode compartilhar a tela inteira, uma janela de aplicativo ou uma aba do navegador. O navegador pede para escolher a fonte, e a transmissão aparece para todos na sala. Quem compartilha pode encerrar pelo botão do Grape ou pelo aviso do próprio navegador.

### Várias telas

Até quatro compartilhamentos de tela podem aparecer ao mesmo tempo. A visão de várias telas organiza as transmissões em uma galeria 16:9, com posições diferentes para uma, duas, três ou quatro telas. Um duplo clique abre uma transmissão em foco; a tecla Esc ou um clique fora volta à galeria. Clicar na identificação de um participante abre a aba individual da tela dele. Fechar um bloco só o remove da galeria: o compartilhamento continua.

### Câmera flutuante

Quando você está assistindo à tela de outra pessoa, sua câmera, se estiver ligada, pode aparecer em uma janela flutuante sobre a transmissão. Arraste a janela para posicioná-la, redimensione pelos cantos mantendo a proporção 16:9 e feche quando quiser. O Grape lembra o tamanho escolhido.

### Moderação da sala

Quem criou a sala pode silenciar ou reativar o microfone de um participante, desligar a câmera à força e remover a pessoa da sala. Também há uma ação para silenciar todos. As ações de moderação ficam disponíveis para o anfitrião no menu de cada participante; silenciar todos também aparece na lista de pessoas.

### Chat

O chat envia mensagens em tempo real para todos os participantes, com o nome de quem escreveu e o horário. Quando chega uma mensagem com a aba do chat fechada, a aba mostra um indicador `!`.

### Perfil e preferências

Você pode escolher o nome exibido e uma foto de perfil de até 5 MB; a imagem é reduzida antes de ser guardada. Na página inicial também é possível trocar os sons de entrada, saída, silenciamento, reativação e início de compartilhamento de tela. Cada som pode ser ouvido, substituído por um arquivo de até 5 MB ou removido para voltar ao padrão. Os sons personalizados ficam no navegador.

O modo compacto deixa a interface com superfícies planas e sem transparências. As preferências de nome, áudio, dispositivos e interface são lembradas neste navegador.

## Como iniciar

1. Salve `Grape.exe` em uma pasta normal do computador. Não há instalador.
2. Dê duplo clique no arquivo. Na primeira execução, ele extrai os arquivos para `%LOCALAPPDATA%\Grape\app`, prepara o banco de dados e inicia o servidor. Isso pode levar alguns minutos e precisa de cerca de 1,2 GB de espaço livre.
3. Se aparecer a pergunta "Confiar no certificado local do Grape?", clique em "Instalar certificado" e confirme no Windows. Não é preciso ser administrador. Se pular essa etapa, o botão "Instalar certificado" fica na barra de título.
4. Quando a janela do Grape abrir, crie uma sala ou entre em uma sala existente.
5. Para convidar, clique no código ao lado do nome da sala para copiá-lo. Envie o código, o endereço `https://<IP-do-Radmin>:8443` e o arquivo `install-ca.bat` para os outros participantes; a próxima seção explica onde encontrá-lo.

O endereço local usado pela janela é `https://localhost:8443`. Você não precisa digitá-lo no computador que hospeda, mas ele também pode ser aberto em um navegador. O formato do link de convite é `https://<IP-do-Radmin>:8443/?code=<CÓDIGO>`; se preferir, envie o endereço e o código separados.

Enquanto a janela estiver aberta, o Grape mantém a sala funcionando. Fechar a janela encerra a sala. Se o Windows Firewall perguntar, permita o acesso em redes privadas; o aviso pode citar Caddy, que é o servidor HTTPS do Grape.

Para encontrar o IP, abra o Radmin VPN no computador que hospeda e veja o endereço IPv4 da máquina.

## Entrando em uma sala

Os convidados não instalam o Grape; usam o navegador. Todos precisam estar conectados à mesma rede Radmin VPN. Antes de abrir a sala, cada convidado precisa confiar no certificado do anfitrião. Esse procedimento é feito uma vez para cada certificado.

O Grape gera automaticamente um arquivo `install-ca.bat` para os convidados. No computador que hospeda, ele fica em `%LOCALAPPDATA%\Grape\app\deploy\ca\dist\install-ca.bat`. Para abrir a pasta, cole `%LOCALAPPDATA%\Grape\app\deploy\ca\dist` na barra de endereço do Explorador de Arquivos.

Envie esse arquivo junto com o endereço e o código da sala. Ele contém apenas o certificado público do anfitrião, não uma senha.

No computador do convidado, dê duplo clique em `install-ca.bat` e confirme a instalação. O Windows pode mostrar o aviso do SmartScreen; nesse caso, use "Mais informações" e "Executar assim mesmo". Não é necessário ser administrador.

Depois, feche todas as janelas do navegador e abra de novo. Isso é necessário porque Chrome, Edge e Brave mantêm o erro de certificado em cache enquanto o navegador está aberto.

Abra `https://<IP-do-Radmin>:8443` e digite o código, ou use o link `https://<IP-do-Radmin>:8443/?code=<CÓDIGO>`. O código é o mesmo que aparece ao lado do nome da sala.

Se o anfitrião trocar o certificado, os convidados precisam executar o novo instalador. No Firefox, o certificado é importado manualmente; veja [docs/CERTIFICADOS.md](docs/CERTIFICADOS.md).

A conexão dos convidados precisa ser HTTPS. Um endereço HTTP na rede não libera câmera, microfone ou compartilhamento de tela.

## Requisitos

- Windows 10 ou 11, 64 bits.
- Radmin VPN instalado e conectado no anfitrião e nos convidados.
- Chrome, Edge ou Brave atualizado nos convidados. O Firefox funciona, mas exige a importação manual do certificado.
- Cerca de 1,2 GB de espaço livre no computador que hospeda, para a primeira execução.

O anfitrião não precisa de um navegador à parte: a janela do Grape já tem um navegador integrado.

## Problemas comuns

| Situação | O que fazer |
|---|---|
| O Grape não abre | Se o Windows bloquear o arquivo, clique com o botão direito nele, escolha "Propriedades", marque "Desbloquear" e tente de novo. Se o SmartScreen avisar que o aplicativo não é reconhecido, clique em "Mais informações" e "Executar assim mesmo". Confirme que há espaço livre e que o arquivo não está dentro de um ZIP. Se o antivírus bloquear, permita o Grape. |
| Aparece um erro de porta ocupada | Feche outra janela do Grape e abra de novo. Se continuar, reinicie o computador. Se você usa XAMPP, pare o Apache; ele pode estar usando a porta 8443. |
| Os convidados não conseguem abrir a sala | Confirme que o Grape está aberto no anfitrião, que todos estão conectados à mesma Radmin VPN e que o IP usado é o do adaptador Radmin. Permita o acesso em redes privadas quando o Windows perguntar (o aviso pode citar Caddy). |
| O navegador avisa que a conexão não é segura | Execute o `install-ca.bat` enviado pelo anfitrião, feche todas as janelas do navegador e abra de novo. Use um endereço `https://`. No Firefox, importe o certificado manualmente. |
| A câmera ou o microfone não funcionam | Permita o acesso quando o navegador pedir. Verifique as permissões de câmera e microfone no Windows, feche OBS, Zoom, Teams ou outro programa que esteja usando o dispositivo e escolha o dispositivo correto nas configurações do Grape. |
| O compartilhamento de tela não começa | Use o botão de compartilhar tela e escolha uma tela, janela ou aba. Se o seletor não abrir, atualize o navegador e feche outros aplicativos que estejam capturando a tela. |
| O chat funciona, mas a voz ou o vídeo não | A conexão direta entre os navegadores pode estar bloqueada. Confirme se todos estão na mesma VPN e se o firewall permite o Grape. Em redes muito restritivas, pode ser necessário um servidor TURN; os detalhes estão na [referência técnica](docs/REFERENCIA-TECNICA.md). |
| O `install-ca.bat` não executa | Se o SmartScreen bloquear, clique em "Mais informações" e "Executar assim mesmo". Se o arquivo estiver bloqueado, clique com o botão direito nele, escolha "Propriedades" e marque "Desbloquear". Não é preciso executar como administrador. |

## Limitações

A sala aceita até 10 pessoas. Ao criar, o campo começa em 8 e pode ser ajustado até o limite.

A chamada usa uma conexão direta entre os navegadores, sem servidor central de mídia. Cada participante envia áudio, vídeo e tela para os demais. Por isso, quanto mais pessoas e mais telas ao mesmo tempo, maior o consumo de rede e de processamento; a qualidade depende da conexão de cada um.

Não há gravação de chamadas nem histórico de mensagens do chat. As mensagens existem enquanto a sala está aberta.

A sala só funciona enquanto o Grape estiver aberto no anfitrião. O aplicativo foi feito para a rede privada da Radmin VPN; não é um serviço para expor na internet.

Não há login nem conta: o perfil e as preferências ficam no navegador. Limpar os dados do navegador pode apagar essas informações.

## Licença

O Grape é distribuído sob a licença MIT.
