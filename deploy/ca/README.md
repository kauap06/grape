# Grape Root CA

Uma CA raiz própria e privada, para emitir certificados TLS confiáveis para o
Grape sem depender do `tls internal` do Caddy (que gera um certificado novo e
diferente a cada host, obrigando cada convidado a "aceitar o aviso" sempre).
Com uma CA própria, você instala **um único** certificado raiz
(`grape-root.crt`) no seu PC e no de cada convidado, uma vez, e todo
certificado futuro emitido por ela passa a ser confiável automaticamente.

Não substitui nada do que já existe — é um complemento a `deploy/Caddyfile` e
ao guia `HTTPS_RADMIN.md`.

## Estrutura

```
deploy/ca/
├── root.cnf              # config OpenSSL da CA raiz (sem segredos, versionado)
├── lib.sh                # funções compartilhadas pelos scripts abaixo
├── generate-root-ca.sh   # passo 1: cria a CA raiz (uma vez só)
├── issue-cert.sh         # passo 2: emite um certificado de servidor assinado pela CA
├── renew-cert.sh         # renova um certificado já emitido (mesma chave, mesmos SANs)
├── generate-root-ca.bat  # wrappers .bat equivalentes (via Git Bash)
├── issue-cert.bat
├── renew-cert.bat
└── dist/                 # cópia do certificado PÚBLICO da CA, para distribuir/instalar
```

A chave privada da CA e as chaves/certificados emitidos **nunca** ficam dentro
do repositório — vivem em `$GRAPE_CA_DIR` (por padrão `~/.grape-ca`, ou
`%USERPROFILE%\.grape-ca` no Windows via Git Bash), fora da pasta `grape/`.

## Requisitos

- OpenSSL no PATH. No Windows, o jeito mais simples é usar o **Git Bash**
  (já vem com o Git for Windows, que você provavelmente já tem por causa do
  `.git` do projeto) — ele traz `openssl` e `bash` prontos.
- Os scripts `.bat` apenas chamam os `.sh` via Git Bash; não há lógica
  duplicada para manter.

## 1. Criar a CA raiz (uma vez)

```bash
deploy/ca/generate-root-ca.sh
```

Isso pede uma **senha para a chave privada da CA** (não pule essa etapa) e gera:

| Arquivo | Conteúdo | Segredo? |
|---|---|---|
| `~/.grape-ca/private/grape-root.key` | chave privada RSA 4096, criptografada AES-256 | **SIM** |
| `~/.grape-ca/certs/grape-root.crt` | certificado raiz público | não |
| `deploy/ca/dist/grape-root.crt` | cópia do certificado público, para distribuir | não |

Validade: 10 anos. Restrições aplicadas (`root.cnf`):
`basicConstraints=CA:TRUE,pathlen:0` (não pode assinar outra CA intermediária)
e `keyUsage=keyCertSign,cRLSign` (só serve para assinar certificados e CRLs,
nunca para servir TLS diretamente).

## 2. Guardar a chave privada da CA com segurança

A chave (`grape-root.key`) já nasce fora do repositório, em `~/.grape-ca/`,
mas isso sozinho não é "seguro o suficiente" — é só "fora do Git". Recomendo:

1. **Mova a pasta `~/.grape-ca/private/` para fora do seu PC de uso diário**
   assim que terminar de emitir os certificados que precisa no momento — por
   exemplo, um pendrive criptografado (BitLocker To Go) ou um cofre de
   segredos local (KeePassXC, Bitwarden com anexos, etc.). Só traga de volta
   quando for emitir ou renovar um certificado.
2. **Nunca** anote a senha da chave junto com o arquivo — guarde a senha em
   um gerenciador de senhas separado.
3. Mantenha a permissão `600` no arquivo da chave e `700` na pasta (já
   aplicado pelos scripts; confira com `ls -l` se copiar manualmente).
4. Se preferir não mover nada manualmente, aponte `GRAPE_CA_DIR` direto para
   um volume externo/criptografado antes de rodar `generate-root-ca.sh`:
   ```bash
   GRAPE_CA_DIR=/mnt/cofre/grape-ca deploy/ca/generate-root-ca.sh
   ```
5. **Nunca** copie `grape-root.key` para `deploy/ca/dist/` ou para qualquer
   pasta do repositório — só o `.crt` (público) deve circular.
6. Faça um backup do `grape-root.key` (ele é a sua CA; perdê-lo significa ter
   que gerar tudo de novo e reinstalar o certificado em cada convidado).
   Guarde o backup também criptografado, fora do repositório.

## 3. Emitir um certificado de servidor

Use o(s) endereço(s) que os convidados realmente digitam no navegador — o IP
do Radmin VPN e/ou `localhost`. Eles vão para o SAN (Subject Alternative
Name); é isso que o navegador valida, não o "nome" do certificado.

```bash
deploy/ca/issue-cert.sh radmin-host ip:26.22.182.86 dns:localhost ip:127.0.0.1
```

Isso pede a **senha da CA** (etapa de assinatura) e gera, em
`~/.grape-ca/issued/radmin-host/`:

- `radmin-host.key` — chave privada do certificado de servidor
- `radmin-host.crt` — certificado assinado pela Grape Root CA, válido 397 dias

Aponte o Caddy para esses dois arquivos em `deploy/Caddyfile`, no lugar de
`tls internal`:

```caddyfile
https://{$RADMIN_IP:127.0.0.1}, https://localhost {
    tls /caminho/para/radmin-host.crt /caminho/para/radmin-host.key
    ...
}
```

(Essa troca no Caddyfile não foi feita automaticamente — é só um exemplo;
aplique quando estiver com os caminhos definitivos.)

## 4. Instalar `grape-root.crt` como raiz confiável

Uma vez, em cada máquina (a sua e a de cada convidado):

- **Windows**: duplo clique em `deploy/ca/dist/grape-root.crt` → "Instalar
  Certificado" → **Máquina Local** (requer admin) → "Colocar todos os
  certificados no repositório a seguir" → **Autoridades de Certificação
  Raiz Confiáveis**.
- **Chrome/Edge no Windows** usam o repositório do Windows acima — não
  precisa de passo extra.
- **Firefox**: `about:preferences#privacy` → Certificados → Ver
  Certificados → Autoridades → Importar → selecione o `.crt` → marque
  "Confiar nesta CA para identificar sites".

O IP do Radmin muda? Rode `issue-cert.sh` de novo com o novo IP — a CA raiz
não muda, só o certificado de servidor, então ninguém precisa reinstalar nada.

## 5. Renovar antes de expirar

```bash
deploy/ca/renew-cert.sh radmin-host
```

Reaproveita a mesma chave e os mesmos SANs gravados na emissão original, pede
a senha da CA de novo, e gera um novo `radmin-host.crt` com +397 dias.
A emissão anterior é copiada para `radmin-host.crt.<timestamp>.bak` antes de
ser sobrescrita. Reinicie o Caddy depois.

Se o IP do Radmin mudou desde a última emissão, **não** use `renew-cert.sh`
(ele reaproveita os SANs antigos) — rode `issue-cert.sh` de novo com a lista
de SAN atualizada.

## Por que esses valores

- **Chave da CA com senha (AES-256) + 4096 bits**: é o segredo mais crítico
  do sistema — comprometê-lo permite emitir certificados confiáveis para
  qualquer site, nos navegadores onde a raiz foi instalada. Senha obrigatória
  evita uso acidental/automatizado sem intervenção humana.
- **`pathlen:0`**: mesmo que a chave da CA vaze, ela não pode ser usada para
  criar outra CA intermediária e estender a confiança.
- **397 dias nos certificados de servidor**: é o teto que os navegadores
  atuais aceitam para certificados "normais"; manter esse limite evita
  qualquer bloqueio futuro, mesmo sendo uma CA privada sem essa obrigação.
- **Chaves de servidor sem senha**: elas precisam ser lidas automaticamente
  pelo Caddy ao iniciar, sem interação humana; a proteção delas é a
  permissão de arquivo (`600`) e o fato de nunca saírem da máquina que
  hospeda o Grape.
