# Grape - Gera o instalador de certificado para convidados (uso interno).
# Chamado por make-guest-installer.bat. Sem ele nao funciona, pois precisa
# reescrever o modelo com o certificado do host embutido.

param(
    [Parameter(Mandatory = $true)][string]$CertPath,
    [Parameter(Mandatory = $true)][string]$TemplatePath,
    [Parameter(Mandatory = $true)][string]$OutputPath
)

$ErrorActionPreference = 'Stop'

try {
    $pem = Get-Content -LiteralPath $CertPath -Raw

    # Extrai o base64 do DER de dentro do PEM (sem cabecalho/rodape)
    $begin = '-----BEGIN CERTIFICATE-----'
    $end   = '-----END CERTIFICATE-----'
    $i = $pem.IndexOf($begin)
    $j = $pem.IndexOf($end)
    if ($i -lt 0 -or $j -lt 0 -or $j -le $i) {
        Write-Error "Certificado sem bloco PEM valido: $CertPath"
        exit 2
    }

    $b64 = $pem.Substring($i + $begin.Length, $j - $i - $begin.Length)
    $b64 = ($b64 -replace '\s', '')
    if ($b64.Length -lt 100) {
        Write-Error "Base64 do certificado parece invalido ($($b64.Length) caracteres)."
        exit 2
    }

    $template = Get-Content -LiteralPath $TemplatePath -Raw
    if ($template.IndexOf('@CERT_BASE64@') -lt 0) {
        Write-Error "Modelo sem o marcador @CERT_BASE64@: $TemplatePath"
        exit 2
    }

    $result = $template.Replace('@CERT_BASE64@', $b64)
    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    [IO.File]::WriteAllText($OutputPath, $result, $utf8NoBom)

    exit 0
}
catch {
    Write-Error $_.Exception.Message
    exit 1
}
