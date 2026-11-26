[CmdletBinding()]
param(
    [string]$Destination = "",
    [switch]$SkipComposer
)

$ErrorActionPreference = "Stop"
$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$root = Split-Path -Parent $scriptDir
if ([string]::IsNullOrWhiteSpace($Destination)) { $Destination = Join-Path $root "dist\Grape" }
$Destination = [System.IO.Path]::GetFullPath($Destination)
$rootFull = [System.IO.Path]::GetFullPath($root).TrimEnd('\')
if ($Destination -eq $rootFull -or -not $Destination.StartsWith($rootFull + '\', [System.StringComparison]::OrdinalIgnoreCase)) {
    throw "The package destination must be a folder inside the project root: $Destination"
}

$exe = Join-Path $root 'Grape.exe'
$core = Join-Path $root 'Microsoft.Web.WebView2.Core.dll'
$winforms = Join-Path $root 'Microsoft.Web.WebView2.WinForms.dll'
$loader = Join-Path $root 'WebView2Loader.dll'
$fixedRuntime = Join-Path $root 'runtime\webview2'
if (-not (Test-Path -LiteralPath (Join-Path $fixedRuntime 'msedgewebview2.exe'))) {
    $fallback = Get-ChildItem -LiteralPath (Join-Path $root 'build\vendor\webview2-runtime') -Directory -Filter 'Microsoft.WebView2.FixedVersionRuntime.*.x64' -ErrorAction SilentlyContinue | Sort-Object Name -Descending | Select-Object -First 1
    if ($fallback) {
        $fixedRuntime = $fallback.FullName
    }
}
$php = Join-Path $root 'portable\php\php.exe'
$composer = Join-Path $root 'portable\composer\composer.phar'

if (-not (Test-Path -LiteralPath $exe) -or -not (Test-Path -LiteralPath $core) -or -not (Test-Path -LiteralPath $winforms) -or -not (Test-Path -LiteralPath $loader)) {
    Write-Host "  shell compilado ausente; compilando o inicializador"
    & (Join-Path $root 'build\build.ps1')
}

foreach ($required in @($exe, $core, $winforms, $loader, $php, $composer)) {
    if (-not (Test-Path -LiteralPath $required)) { throw "Required build input is missing: $required" }
}
if (-not (Test-Path -LiteralPath (Join-Path $fixedRuntime 'msedgewebview2.exe'))) {
    throw "The fixed WebView2 runtime is incomplete (msedgewebview2.exe is missing): $fixedRuntime"
}
if (Test-Path -LiteralPath (Join-Path $root 'config\local.php')) {
    Write-Host "  config\local.php will be excluded from the package (development credentials)."
}

function Copy-Tree {
    param(
        [string]$Source,
        [string]$Target,
        [string[]]$ExcludeDirs = @(),
        [string[]]$ExcludeFiles = @()
    )
    if (-not (Test-Path -LiteralPath $Source)) { throw "Source not found: $Source" }
    New-Item -ItemType Directory -Force -Path $Target | Out-Null
    Get-ChildItem -LiteralPath $Source -Force | ForEach-Object {
        if ($_.PSIsContainer) {
            if ($ExcludeDirs -contains $_.Name) { return }
            Copy-Tree -Source $_.FullName -Target (Join-Path $Target $_.Name) -ExcludeDirs $ExcludeDirs -ExcludeFiles $ExcludeFiles
        }
        else {
            if ($ExcludeFiles -contains $_.Name) { return }
            Copy-Item -LiteralPath $_.FullName -Destination (Join-Path $Target $_.Name) -Force
        }
    }
}

Write-Host "Grape distribution package"
Write-Host ("  source:      " + $root)
Write-Host ("  destination: " + $Destination)

if (Test-Path -LiteralPath $Destination) {
    Write-Host "  removing previous package contents"
    Remove-Item -LiteralPath $Destination -Recurse -Force
}
New-Item -ItemType Directory -Force -Path $Destination | Out-Null

# Shell and its managed WebView2 dependencies
Copy-Item -LiteralPath $exe -Destination $Destination -Force
Copy-Item -LiteralPath $core -Destination $Destination -Force
Copy-Item -LiteralPath $winforms -Destination $Destination -Force
Copy-Item -LiteralPath $loader -Destination $Destination -Force
Copy-Item -LiteralPath (Join-Path $root 'composer.json') -Destination $Destination -Force
Copy-Item -LiteralPath (Join-Path $root 'composer.lock') -Destination $Destination -Force

# Application code, services and assets
foreach ($folder in @('bin', 'database', 'public', 'server', 'src')) {
    Write-Host ("  copying " + $folder)
    Copy-Tree -Source (Join-Path $root $folder) -Target (Join-Path $Destination $folder)
}

# Config: never ship the generated local credentials
Write-Host "  copying config (without local.php)"
Copy-Tree -Source (Join-Path $root 'config') -Target (Join-Path $Destination 'config') -ExcludeFiles @('local.php')

# Deploy: Caddyfile and CA helpers only; dist is created at runtime
Write-Host "  copying deploy"
New-Item -ItemType Directory -Force -Path (Join-Path $Destination 'deploy') | Out-Null
Copy-Item -LiteralPath (Join-Path $root 'deploy\Caddyfile') -Destination (Join-Path $Destination 'deploy') -Force
if (Test-Path -LiteralPath (Join-Path $root 'deploy\liberar-permissoes.bat')) {
    Copy-Item -LiteralPath (Join-Path $root 'deploy\liberar-permissoes.bat') -Destination (Join-Path $Destination 'deploy') -Force
}
Copy-Tree -Source (Join-Path $root 'deploy\ca') -Target (Join-Path $Destination 'deploy\ca') -ExcludeDirs @('dist')
New-Item -ItemType Directory -Force -Path (Join-Path $Destination 'deploy\ca\dist') | Out-Null

# PHP, MariaDB and Caddy runtimes. Never ship the local database or Composer.
Write-Host "  copying PHP runtime"
Copy-Tree -Source (Join-Path $root 'portable\php') -Target (Join-Path $Destination 'portable\php') -ExcludeDirs @('opcache', 'conf.d')
Write-Host "  copying MariaDB runtime (without data)"
Copy-Tree -Source (Join-Path $root 'portable\mariadb') -Target (Join-Path $Destination 'portable\mariadb') -ExcludeDirs @('data')
Write-Host "  copying Caddy runtime"
Copy-Tree -Source (Join-Path $root 'portable\caddy') -Target (Join-Path $Destination 'portable\caddy')

# Fixed WebView2 runtime. This is a real copy, not the development junction.
Write-Host "  copying fixed WebView2 runtime (this is the large step)"
Copy-Tree -Source $fixedRuntime -Target (Join-Path $Destination 'runtime\webview2')

# Empty writable folders; no user data is shipped
New-Item -ItemType Directory -Force -Path (Join-Path $Destination 'data\sessions') | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $Destination 'logs') | Out-Null

if (-not $SkipComposer) {
    Write-Host "  installing production Composer dependencies"
    Push-Location $Destination
    try {
        & $php -d opcache.enable_cli=0 $composer install --no-dev --no-interaction --no-scripts --prefer-dist --optimize-autoloader --working-dir=$Destination
        if ($LASTEXITCODE -ne 0) { throw "Composer production install failed with exit code $LASTEXITCODE" }
    }
    finally { Pop-Location }
}

if (-not (Test-Path -LiteralPath (Join-Path $Destination 'vendor\autoload.php'))) {
    throw "Production vendor\autoload.php is missing from the package."
}
if (Test-Path -LiteralPath (Join-Path $Destination 'config\local.php')) {
    throw "config\local.php must not be present in the package."
}
if (Test-Path -LiteralPath (Join-Path $Destination 'portable\mariadb\data')) {
    throw "portable\mariadb\data must not be present in the package."
}
if (-not (Test-Path -LiteralPath (Join-Path $Destination 'runtime\webview2\msedgewebview2.exe'))) {
    throw "The packaged WebView2 runtime is incomplete."
}

$hash = (Get-FileHash -LiteralPath (Join-Path $Destination 'Grape.exe') -Algorithm SHA256).Hash
$runtimeVersion = (Get-Item -LiteralPath (Join-Path $Destination 'runtime\webview2\msedgewebview2.exe')).VersionInfo.ProductVersion
$info = @(
    "Grape - distribuição para Windows",
    ("Versão: 1.0.1"),
    ("Compilado em: " + (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")),
    ("Arquitetura: x64"),
    ("Janela: Grape.exe (WinForms + Microsoft Edge WebView2)"),
    ("WebView2: " + $runtimeVersion + " (fixo, incluído em runtime\webview2)"),
    ("Grape.exe SHA256: " + $hash),
    "",
    "Abra Grape.exe com um duplo clique. Todos os serviços rodam a partir desta pasta.",
    "Dados, sessões, logs e o banco local ficam dentro desta pasta.",
    "Não copie Grape.exe para fora da pasta: o runtime e o PHP incluídos são necessários."
)
Set-Content -LiteralPath (Join-Path $Destination 'BUILD-INFO.txt') -Value $info -Encoding UTF8

$readme = @(
    "Grape - Windows (pacote portátil)",
    "",
    "1. Extraia a pasta inteira para um local com permissão de escrita.",
    "2. Abra Grape.exe.",
    "3. Na primeira execução, o Windows pode pedir permissão para confiar no certificado local do Grape. Isso é necessário para HTTPS, câmera, microfone e compartilhamento de tela.",
    "4. O Windows também pode perguntar se o Caddy pode usar redes privadas. Permita para que os convidados consigam entrar nas salas.",
    "5. Feche a janela para encerrar o aplicativo e todos os serviços.",
    "",
    "Não abra Grape.exe de dentro de um ZIP e não mova o executável para fora desta pasta.",
    "Os dados ficam em data\, logs\ e portable\mariadb\data\.",
    "",
    "Também existe uma versão em arquivo único (Grape.exe), distribuída separadamente.",
    "Ela extrai os componentes para %LOCALAPPDATA%\Grape\app e mantém os dados entre atualizações."
)
Set-Content -LiteralPath (Join-Path $Destination 'LEIA-ME.txt') -Value $readme -Encoding UTF8

$files = Get-ChildItem -LiteralPath $Destination -Recurse -File -Force
$bytes = ($files | Measure-Object -Property Length -Sum).Sum
Write-Host ""
Write-Host ("Package:  " + $Destination)
Write-Host ("Files:    " + $files.Count)
Write-Host ("Size:     " + [Math]::Round($bytes / 1MB, 1) + " MB")
Write-Host ("EXE:      " + $hash)
Write-Host ("WebView2: " + $runtimeVersion)