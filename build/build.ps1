[CmdletBinding()]
param(
    [string]$OutputDir = "",
    [switch]$SkipIcon
)

$ErrorActionPreference = "Stop"
$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$root = Split-Path -Parent $scriptDir
if ([string]::IsNullOrWhiteSpace($OutputDir)) { $OutputDir = $root }
$OutputDir = [System.IO.Path]::GetFullPath($OutputDir)
$launcherDir = Join-Path $scriptDir "launcher"
$manifest = Join-Path $launcherDir "app.manifest"
$iconPath = Join-Path $scriptDir "grape.ico"
$logoPath = Join-Path $root "public\assets\img\logo-grape.png"
$outputExe = Join-Path $OutputDir "Grape.exe"
$webViewSdk = Join-Path $scriptDir "vendor\webview2"
$webViewCore = Join-Path $webViewSdk "lib\net462\Microsoft.Web.WebView2.Core.dll"
$webViewWinForms = Join-Path $webViewSdk "lib\net462\Microsoft.Web.WebView2.WinForms.dll"
$webViewLoader = Join-Path $webViewSdk "build\native\x64\WebView2Loader.dll"

function New-GrapeIcon {
    param(
        [string]$SourcePng,
        [string]$Destination
    )
    Add-Type -AssemblyName System.Drawing
    $sizes = @(16, 24, 32, 48, 64, 128, 256)
    $source = [System.Drawing.Image]::FromFile($SourcePng)
    $entries = New-Object "System.Collections.Generic.List[object]"
    try {
        foreach ($size in $sizes) {
            $bitmap = New-Object System.Drawing.Bitmap($size, $size, [System.Drawing.Imaging.PixelFormat]::Format32bppArgb)
            $graphics = [System.Drawing.Graphics]::FromImage($bitmap)
            try {
                $graphics.Clear([System.Drawing.Color]::Transparent)
                $graphics.CompositingMode = [System.Drawing.Drawing2D.CompositingMode]::SourceOver
                $graphics.CompositingQuality = [System.Drawing.Drawing2D.CompositingQuality]::HighQuality
                $graphics.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
                $graphics.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::HighQuality
                $graphics.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
                $scale = [Math]::Min($size / $source.Width, $size / $source.Height)
                $width = [int][Math]::Round($source.Width * $scale)
                $height = [int][Math]::Round($source.Height * $scale)
                $x = [int](($size - $width) / 2)
                $y = [int](($size - $height) / 2)
                $graphics.DrawImage($source, $x, $y, $width, $height)
            }
            finally {
                $graphics.Dispose()
            }
            $stream = New-Object System.IO.MemoryStream
            try {
                $bitmap.Save($stream, [System.Drawing.Imaging.ImageFormat]::Png)
                $entries.Add([pscustomobject]@{ Size = $size; Bytes = $stream.ToArray() })
            }
            finally {
                $stream.Dispose()
                $bitmap.Dispose()
            }
        }
    }
    finally {
        $source.Dispose()
    }

    $file = [System.IO.File]::Create($Destination)
    $writer = New-Object System.IO.BinaryWriter($file)
    try {
        $writer.Write([UInt16]0)
        $writer.Write([UInt16]1)
        $writer.Write([UInt16]$entries.Count)
        $offset = 6 + (16 * $entries.Count)
        foreach ($entry in $entries) {
            $dimension = if ($entry.Size -ge 256) { 0 } else { $entry.Size }
            $writer.Write([byte]$dimension)
            $writer.Write([byte]$dimension)
            $writer.Write([byte]0)
            $writer.Write([byte]0)
            $writer.Write([UInt16]1)
            $writer.Write([UInt16]32)
            $writer.Write([UInt32]$entry.Bytes.Length)
            $writer.Write([UInt32]$offset)
            $offset += $entry.Bytes.Length
        }
        foreach ($entry in $entries) {
            $writer.Write($entry.Bytes)
        }
    }
    finally {
        $writer.Dispose()
        $file.Dispose()
    }
}

Write-Host "Grape launcher build"
Write-Host ("  root:    " + $root)
Write-Host ("  output:  " + $outputExe)

if (-not (Test-Path -LiteralPath $manifest)) { throw "Manifest not found: $manifest" }
$sources = @(
    (Join-Path $launcherDir "Launcher.cs"),
    (Join-Path $launcherDir "MainForm.cs"),
    (Join-Path $launcherDir "Dialogs.cs")
)
foreach ($source in $sources) {
    if (-not (Test-Path -LiteralPath $source)) { throw "Source not found: $source" }
}
if (-not (Test-Path -LiteralPath $logoPath)) { throw "Logo not found: $logoPath" }
foreach ($dependency in @($webViewCore, $webViewWinForms, $webViewLoader)) {
    if (-not (Test-Path -LiteralPath $dependency)) { throw "WebView2 dependency not found: $dependency" }
}

$cscCandidates = @(
    (Join-Path $env:WINDIR "Microsoft.NET\Framework64\v4.0.30319\csc.exe"),
    (Join-Path $env:WINDIR "Microsoft.NET\Framework\v4.0.30319\csc.exe")
)
$csc = $null
foreach ($candidate in $cscCandidates) {
    if (Test-Path -LiteralPath $candidate) { $csc = $candidate; break }
}
if (-not $csc) { throw "csc.exe from .NET Framework 4.x was not found. Install the .NET Framework 4.x developer pack." }

if (-not $SkipIcon) {
    Write-Host "  icon:    generating multi-resolution ICO"
    New-GrapeIcon -SourcePng $logoPath -Destination $iconPath
}
elseif (-not (Test-Path -LiteralPath $iconPath)) {
    Write-Host "  icon:    skipped (no existing grape.ico)"
}

New-Item -ItemType Directory -Force -Path $OutputDir | Out-Null

$cscArgs = @(
    "/nologo",
    "/target:winexe",
    "/platform:x64",
    "/optimize+",
    "/utf8output",
    "/codepage:65001",
    "/out:$outputExe",
    "/reference:System.dll",
    "/reference:System.Core.dll",
    "/reference:System.Drawing.dll",
    "/reference:System.Windows.Forms.dll",
    "/reference:$webViewCore",
    "/reference:$webViewWinForms",
    "/win32manifest:$manifest"
)
if (Test-Path -LiteralPath $iconPath) { $cscArgs += "/win32icon:$iconPath" }
foreach ($source in $sources) { $cscArgs += $source }

Write-Host ("  compiler: " + $csc)
& $csc $cscArgs
if ($LASTEXITCODE -ne 0) { throw "csc.exe failed with exit code $LASTEXITCODE" }

Copy-Item -LiteralPath $webViewCore -Destination $OutputDir -Force
Copy-Item -LiteralPath $webViewWinForms -Destination $OutputDir -Force
Copy-Item -LiteralPath $webViewLoader -Destination $OutputDir -Force

$file = Get-Item -LiteralPath $outputExe
$hash = (Get-FileHash -LiteralPath $outputExe -Algorithm SHA256).Hash
Write-Host ""
Write-Host ("Built: " + $file.FullName)
Write-Host ("Size:  " + $file.Length + " bytes")
Write-Host ("SHA256: " + $hash)

$sourceHash = (Get-FileHash -LiteralPath $sources -Algorithm SHA256 | ForEach-Object { $_.Hash }) -join ""
$info = @(
    "Grape launcher build",
    ("Built (local): " + (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")),
    ("Compiler: " + $csc),
    ("Output: " + $outputExe),
    ("SHA256: " + $hash),
    ("Sources SHA256: " + $sourceHash),
    ("Version: " + "1.0.0"),
    ("Desktop runtime: Microsoft.Web.WebView2 1.0.4314.35 (x64)"),
    ("Architecture: x64")
)
Set-Content -LiteralPath (Join-Path $scriptDir "build-info.txt") -Value $info -Encoding UTF8
Write-Host ("Info:  " + (Join-Path $scriptDir "build-info.txt"))