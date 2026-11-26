# Grape - Diagnostico de HTTPS para o convidado (uso interno).
# Chamado por test-https.bat. Verifica:
#   1. se existe a CA do host nas lojas do Windows (usuario e computador);
#   2. se o navegador/Windows aceita o certificado de https://<IP>;
#   3. qual navegador padrao esta em uso (Firefox usa loja propria!).

param(
    [string]$Ip
)

$ErrorActionPreference = 'Continue'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

if (-not $Ip) {
    $Ip = Read-Host 'Digite o IP do host (o mesmo do link, ex: 26.12.34.56)'
}
$Ip = $Ip.Trim()
if ($Ip -match '^https?://') { $Ip = ($Ip -replace '^https?://', '') -replace '/.*$', '' }
if ($Ip -match ':') { $Ip = $Ip.Split(':')[0] }

Write-Host ''
Write-Host "=== Grape: diagnostico de HTTPS (host $Ip) ==="
Write-Host ''

# 1) CA do host instalada?
$userRoots = @(Get-ChildItem Cert:\CurrentUser\Root -ErrorAction SilentlyContinue |
    Where-Object { $_.Subject -like '*Caddy Local Authority*' })
$machineRoots = @(Get-ChildItem Cert:\LocalMachine\Root -ErrorAction SilentlyContinue |
    Where-Object { $_.Subject -like '*Caddy Local Authority*' })

Write-Host '1) Certificado do host nas lojas do Windows:'
Write-Host ("   usuario atual : {0}" -f $(if ($userRoots.Count) { 'OK (' + $userRoots.Count + ')' } else { 'AUSENTE' }))
Write-Host ("   computador    : {0}" -f $(if ($machineRoots.Count) { 'OK (' + $machineRoots.Count + ')' } else { 'ausente (nao obrigatorio)' }))

# 2) O Windows valida o certificado do host?
Write-Host ''
Write-Host '2) Conexao HTTPS com o host:'
$ok = $false
try {
    $resp = Invoke-WebRequest -Uri "https://$Ip/" -UseBasicParsing -TimeoutSec 12
    Write-Host ("   OK - HTTP {0} (certificado confiavel)" -f $resp.StatusCode)
    $ok = $true
} catch {
    $msg = $_.Exception.Message
    Write-Host "   FALHOU - $msg"
    if ($msg -match 'SSL|certificate|certificado|confia|trust') {
        Write-Host '   -> o Windows ainda nao confia no certificado do host'
    }
}

# 3) Que navegador esta padrao? (Firefox NAO usa a loja do Windows)
Write-Host ''
Write-Host '3) Navegador padrao:'
$progId = ''
try {
    $progId = (Get-ItemProperty 'HKCU:\SOFTWARE\Microsoft\Windows\Shell\Associations\UrlAssociations\https\UserChoice' -ErrorAction Stop).ProgId
} catch { }
if ($progId) { Write-Host "   $progId" } else { Write-Host '   (nao identificado)' }
if ($progId -match 'Firefox') {
    Write-Host '   ATENCAO: Firefox usa a loja de certificados DELE, nao a do Windows.'
    Write-Host '   Use Chrome/Edge, ou importe a CA em:'
    Write-Host '   Firefox > Configuracoes > Privacidade e Seguranca > Certificados'
    Write-Host '   > Ver Certificados > Autoridades > Importar (caddy-root.crt)'
}

# 4) Resumo
Write-Host ''
Write-Host '=== Resumo ==='
if ($ok) {
    Write-Host ("Este PC confia no certificado: pode abrir https://{0}/?code=... direto." -f $Ip)
    Write-Host 'Se o navegador ainda avisar, feche TODAS as janelas dele e abra de novo.'
    exit 0
}
if (-not $userRoots.Count -and -not $machineRoots.Count) {
    Write-Host 'A CA do host nao esta instalada neste PC.'
    Write-Host 'Rode o install-ca.bat que o host enviou (duplo clique) e teste de novo.'
    exit 1
}
Write-Host 'A CA existe, mas o certificado nao foi aceito. Possiveis causas:'
Write-Host '  - o host reiniciou o Caddy e a CA mudou: peca o install-ca.bat novo;'
Write-Host '  - o IP mudou (confira o link atual com o host);'
Write-Host '  - voce esta usando Firefox (veja item 3 acima).'
exit 2
