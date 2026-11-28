[CmdletBinding()]
param(
    [string]$Source = "",
    [string]$OutputDir = "",
    [string]$Version = "",
    [ValidateSet("none", "zip", "bzip", "lzma", "lzma2/fast", "lzma2/max", "lzma2/ultra", "lzma2/ultra64")]
    [string]$Compression = "lzma2/max",
    [switch]$SkipAssets
)

$ErrorActionPreference = "Stop"
$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$root = Split-Path -Parent $scriptDir
if ([string]::IsNullOrWhiteSpace($Source)) { $Source = Join-Path $root "dist\Grape" }
$Source = [System.IO.Path]::GetFullPath($Source)
if ([string]::IsNullOrWhiteSpace($OutputDir)) { $OutputDir = Join-Path $root "dist" }
$OutputDir = [System.IO.Path]::GetFullPath($OutputDir)
New-Item -ItemType Directory -Force -Path $OutputDir | Out-Null

$isccCandidates = @(
    (Join-Path $env:LOCALAPPDATA "Programs\Inno Setup 6\ISCC.exe"),
    "C:\Program Files (x86)\Inno Setup 6\ISCC.exe",
    "C:\Program Files\Inno Setup 6\ISCC.exe"
)
$iscc = $isccCandidates | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1
if (-not $iscc) {
    throw "Inno Setup 6 não encontrado. Instale com: winget install --id JRSoftware.InnoSetup -e"
}

if (-not (Test-Path -LiteralPath (Join-Path $Source "Grape.exe"))) {
    throw "Payload não encontrado em $Source. Gere o pacote com build\package.ps1 primeiro."
}

if ([string]::IsNullOrWhiteSpace($Version)) {
    $fileVersion = (Get-Item -LiteralPath (Join-Path $Source "Grape.exe")).VersionInfo.FileVersion
    if ($fileVersion -match "^([0-9]+\.[0-9]+\.[0-9]+)") { $Version = $Matches[1] }
    else { $Version = "1.0.1" }
}

if (-not $SkipAssets) {
    Write-Host "Gerando imagens do instalador..."
    & (Join-Path $scriptDir "installer\make-assets.ps1")
}

$iss = Join-Path $scriptDir "installer\grape.iss"
$isccArgs = @(
    "/Qp",
    "/DMyAppVersion=$Version",
    "/DPayloadDir=$Source",
    "/DOutputDir=$OutputDir",
    "/DCompression=$Compression",
    $iss
)

Write-Host ("Compilando instalador Inno Setup " + $Version + "...")
Write-Host ("  payload:    " + $Source)
Write-Host ("  saída:      " + $OutputDir)
Write-Host ("  compressão: " + $Compression)
& $iscc @isccArgs
if ($LASTEXITCODE -ne 0) { throw "Falha ao compilar o instalador (código $LASTEXITCODE)." }

$setupPath = Join-Path $OutputDir ("GrapeSetup-" + $Version + ".exe")
if (-not (Test-Path -LiteralPath $setupPath)) { throw "Instalador não encontrado após a compilação: $setupPath" }
$setup = Get-Item -LiteralPath $setupPath
$hash = (Get-FileHash -LiteralPath $setupPath -Algorithm SHA256).Hash
$payloadBytes = (Get-ChildItem -LiteralPath $Source -Recurse -File | Measure-Object -Property Length -Sum).Sum

$infoPath = [System.IO.Path]::ChangeExtension($setupPath, ".txt")
$info = @(
    "Grape - instalador (Inno Setup)",
    ("Versão: " + $Version),
    ("Compilado em: " + (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")),
    ("Origem: " + $Source),
    ("Instalador: " + $setup.Length + " bytes"),
    ("Payload: " + $payloadBytes + " bytes"),
    ("SHA256: " + $hash),
    "",
    "Instala por usuário em %LOCALAPPDATA%\Grape\app e preserva dados, logs e banco local.",
    ("Para assinar: signtool sign /fd SHA256 /tr https://timestamp.digicert.com /td SHA256 /a " + [char]34 + $setupPath + [char]34 + ".")
)
Set-Content -LiteralPath $infoPath -Value $info -Encoding UTF8

Write-Host ""
Write-Host ("Instalador: " + $setup.FullName)
Write-Host ("Tamanho:    " + [Math]::Round($setup.Length / 1MB, 1) + " MB")
Write-Host ("SHA256:     " + $hash)
Write-Host ("Info:       " + $infoPath)