[CmdletBinding()]
param(
    [string]$Source = "",
    [string]$Output = "",
    [string]$Version = "1.0.1",
    [switch]$SkipCompile,
    [switch]$KeepPayload,
    [ValidateSet("Optimal", "Fastest")]
    [string]$Compression = "Optimal"
)

$ErrorActionPreference = "Stop"
$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$root = Split-Path -Parent $scriptDir
if ([string]::IsNullOrWhiteSpace($Source)) { $Source = Join-Path $root "dist\Grape" }
if ([string]::IsNullOrWhiteSpace($Output)) { $Output = Join-Path $root "dist\Grape.exe" }
$Source = [System.IO.Path]::GetFullPath($Source)
$Output = [System.IO.Path]::GetFullPath($Output)
$bootstrapDir = Join-Path $scriptDir "bootstrap"
$bootstrapSource = Join-Path $bootstrapDir "Bootstrap.cs"
$bootstrapExe = Join-Path $bootstrapDir "GrapeBootstrap.exe"
$manifest = Join-Path $scriptDir "launcher\app.manifest"
$iconPath = Join-Path $scriptDir "grape.ico"
$zipPath = Join-Path $scriptDir "sfx-payload.zip"

if (-not (Test-Path -LiteralPath (Join-Path $Source "Grape.exe"))) { throw "Pacote portátil não encontrado em: $Source" }
if (-not (Test-Path -LiteralPath (Join-Path $Source "runtime\webview2\msedgewebview2.exe"))) { throw "Runtime WebView2 ausente do pacote: $Source" }
if (-not (Test-Path -LiteralPath $bootstrapSource)) { throw "Fonte do inicializador não encontrada: $bootstrapSource" }

$cscCandidates = @(
    (Join-Path $env:WINDIR "Microsoft.NET\Framework64\v4.0.30319\csc.exe"),
    (Join-Path $env:WINDIR "Microsoft.NET\Framework\v4.0.30319\csc.exe")
)
$csc = $null
foreach ($candidate in $cscCandidates) { if (Test-Path -LiteralPath $candidate) { $csc = $candidate; break } }
if (-not $csc) { throw "csc.exe do .NET Framework 4.x não foi encontrado." }

if (-not $SkipCompile) {
    Write-Host "Compilando o inicializador (single-file)..."
    New-Item -ItemType Directory -Force -Path $bootstrapDir | Out-Null
    $cscArgs = @(
        "/nologo",
        "/target:winexe",
        "/platform:x64",
        "/optimize+",
        "/utf8output",
        "/codepage:65001",
        "/out:$bootstrapExe",
        "/reference:System.dll",
        "/reference:System.Core.dll",
        "/reference:System.Drawing.dll",
        "/reference:System.Windows.Forms.dll",
        "/reference:System.IO.Compression.dll",
        "/reference:System.IO.Compression.FileSystem.dll",
        $bootstrapSource
    )
    if (Test-Path -LiteralPath $manifest) { $cscArgs += "/win32manifest:$manifest" }
    if (Test-Path -LiteralPath $iconPath) { $cscArgs += "/win32icon:$iconPath" }
    & $csc $cscArgs
    if ($LASTEXITCODE -ne 0) { throw "Falha ao compilar o inicializador: $LASTEXITCODE" }
}
if (-not (Test-Path -LiteralPath $bootstrapExe)) { throw "Inicializador não encontrado: $bootstrapExe" }

Write-Host "Compactando o pacote portátil (pode levar alguns minutos)..."
if (Test-Path -LiteralPath $zipPath) { Remove-Item -LiteralPath $zipPath -Force }
Add-Type -AssemblyName System.IO.Compression.FileSystem
$level = [System.IO.Compression.CompressionLevel]::Optimal
if ($Compression -eq "Fastest") { $level = [System.IO.Compression.CompressionLevel]::Fastest }
[System.IO.Compression.ZipFile]::CreateFromDirectory($Source, $zipPath, $level, $false)
$zipInfo = Get-Item -LiteralPath $zipPath

if (Test-Path -LiteralPath $Output) { Remove-Item -LiteralPath $Output -Force }
$outDir = Split-Path -Parent $Output
if ($outDir -and -not (Test-Path -LiteralPath $outDir)) { New-Item -ItemType Directory -Force -Path $outDir | Out-Null }
Copy-Item -LiteralPath $bootstrapExe -Destination $Output -Force

$versionBytes = New-Object byte[] 16
$versionText = $Version.PadRight(16, [char]0).Substring(0, 16)
[System.Text.Encoding]::ASCII.GetBytes($versionText).CopyTo($versionBytes, 0)
$lengthBytes = [System.BitConverter]::GetBytes([UInt64]$zipInfo.Length)
$magicBytes = [System.Text.Encoding]::ASCII.GetBytes("GRAPESF1")

$fs = [System.IO.File]::Open($Output, [System.IO.FileMode]::Append, [System.IO.FileAccess]::Write, [System.IO.FileShare]::None)
$input = $null
try {
    $input = [System.IO.File]::OpenRead($zipPath)
    $buffer = New-Object byte[] 131072
    while (($read = $input.Read($buffer, 0, $buffer.Length)) -gt 0) { $fs.Write($buffer, 0, $read) }
    $fs.Write($versionBytes, 0, $versionBytes.Length)
    $fs.Write($lengthBytes, 0, $lengthBytes.Length)
    $fs.Write($magicBytes, 0, $magicBytes.Length)
    $fs.Flush()
}
finally {
    if ($input) { $input.Dispose() }
    $fs.Dispose()
}

$result = Get-Item -LiteralPath $Output
$hash = (Get-FileHash -LiteralPath $Output -Algorithm SHA256).Hash
$infoPath = [System.IO.Path]::ChangeExtension($Output, ".txt")
$info = @(
    "Grape - executável único autoextraível",
    ("Versão: " + $Version),
    ("Compilado em: " + (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")),
    ("Origem: " + $Source),
    ("Carga ZIP: " + $zipInfo.Length + " bytes"),
    ("Executável: " + $result.Length + " bytes"),
    ("SHA256: " + $hash),
    "",
    "Na primeira abertura, o Grape extrai os componentes para %LOCALAPPDATA%\Grape\app.",
    "Depois disso, as próximas aberturas usam os arquivos extraídos e são rápidas.",
    "Dados do usuário (banco, logs, sessões, certificado) ficam em %LOCALAPPDATA%\Grape\app."
)
Set-Content -LiteralPath $infoPath -Value $info -Encoding UTF8

if (-not $KeepPayload) { Remove-Item -LiteralPath $zipPath -Force }
Write-Host ""
Write-Host ("Single-file: " + $result.FullName)
Write-Host ("Size:        " + [Math]::Round($result.Length / 1MB, 1) + " MB")
Write-Host ("Payload:     " + [Math]::Round($zipInfo.Length / 1MB, 1) + " MB")
Write-Host ("SHA256:      " + $hash)
Write-Host ("Info:        " + $infoPath)