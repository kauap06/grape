[CmdletBinding()]
param(
    [string]$OutputDir = ""
)

$ErrorActionPreference = "Stop"
Add-Type -AssemblyName System.Drawing

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$root = Split-Path -Parent (Split-Path -Parent $scriptDir)
if ([string]::IsNullOrWhiteSpace($OutputDir)) { $OutputDir = Join-Path $scriptDir "assets" }
New-Item -ItemType Directory -Force -Path $OutputDir | Out-Null

$logoPath = Join-Path $root "public\assets\img\logo-grape.png"
$fontDir = Join-Path $root "public\assets\fonts"
$regular = Join-Path $fontDir "PlusJakartaSans-Regular.ttf"
$semibold = Join-Path $fontDir "PlusJakartaSans-SemiBold.ttf"
foreach ($path in @($logoPath, $regular, $semibold)) {
    if (-not (Test-Path -LiteralPath $path)) { throw "Asset ausente: $path" }
}

$fonts = New-Object System.Drawing.Text.PrivateFontCollection
$fonts.AddFontFile($regular)
$fonts.AddFontFile($semibold)
$ui = $fonts.Families[0]
$strong = $fonts.Families | Where-Object { $_.Name -like "*SemiBold*" } | Select-Object -First 1
if ($null -eq $strong) { $strong = $ui }

function New-Canvas([int]$width, [int]$height) {
    $bmp = New-Object System.Drawing.Bitmap($width, $height, [System.Drawing.Imaging.PixelFormat]::Format24bppRgb)
    $g = [System.Drawing.Graphics]::FromImage($bmp)
    $g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
    $g.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
    $g.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
    $g.TextRenderingHint = [System.Drawing.Text.TextRenderingHint]::AntiAliasGridFit
    return @($bmp, $g)
}

function Add-Glow($g, [int]$width, [int]$height) {
    $path = New-Object System.Drawing.Drawing2D.GraphicsPath
    $path.AddEllipse([int]($width * 0.10), [int]($height * 0.10), [int]($width * 0.80), [int]($height * 0.58))
    $brush = New-Object System.Drawing.Drawing2D.PathGradientBrush($path)
    $brush.CenterColor = [System.Drawing.Color]::FromArgb(90, 124, 58, 237)
    $brush.SurroundColors = @([System.Drawing.Color]::FromArgb(0, 124, 58, 237))
    $brush.CenterPoint = New-Object System.Drawing.PointF(($width * 0.5), ($height * 0.32))
    $g.FillPath($brush, $path)
    $brush.Dispose()
    $path.Dispose()
}

function Add-Grid($g, [int]$width, [int]$height) {
    $dot = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(14, 246, 241, 255))
    $step = 28
    for ($y = 0; $y -le $height; $y += $step) {
        for ($x = 0; $x -le $width; $x += $step) {
            $g.FillEllipse($dot, ($x - 1), ($y - 1), 2, 2)
        }
    }
    $dot.Dispose()
}

function Add-Rings($g, [int]$width, [int]$height) {
    $cx = $width / 2
    $cy = $height * 0.31
    $pen = New-Object System.Drawing.Pen([System.Drawing.Color]::FromArgb(34, 167, 139, 250), 1)
    $radius = $width * 0.36
    $g.DrawEllipse($pen, ($cx - $radius), ($cy - $radius), ($radius * 2), ($radius * 2))
    $pen.Color = [System.Drawing.Color]::FromArgb(22, 110, 231, 183)
    $radius = $width * 0.44
    $g.DrawArc($pen, ($cx - $radius), ($cy - $radius), ($radius * 2), ($radius * 2), 205, 95)
    $pen.Dispose()
}

function Draw-Logo($g, [string]$path, [int]$x, [int]$y, [int]$width, [float]$opacity = 1) {
    $image = [System.Drawing.Image]::FromFile($path)
    try {
        $height = [int]($width * ($image.Height / $image.Width))
        $matrix = New-Object System.Drawing.Imaging.ColorMatrix
        $matrix.Matrix33 = $opacity
        $attributes = New-Object System.Drawing.Imaging.ImageAttributes
        $attributes.SetColorMatrix($matrix, [System.Drawing.Imaging.ColorMatrixFlag]::Default, [System.Drawing.Imaging.ColorAdjustType]::Bitmap)
        $rect = New-Object System.Drawing.Rectangle($x, $y, $width, $height)
        $g.DrawImage($image, $rect, 0, 0, $image.Width, $image.Height, [System.Drawing.GraphicsUnit]::Pixel, $attributes)
        $attributes.Dispose()
    }
    finally { $image.Dispose() }
}

function Draw-Wordmark($g, [int]$width, [float]$top) {
    $font = New-Object System.Drawing.Font($strong, 46, [System.Drawing.FontStyle]::Regular, [System.Drawing.GraphicsUnit]::Pixel)
    $brush = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(246, 241, 255))
    $format = New-Object System.Drawing.StringFormat
    $format.Alignment = [System.Drawing.StringAlignment]::Center
    $format.LineAlignment = [System.Drawing.StringAlignment]::Center
    $rect = New-Object System.Drawing.RectangleF(0, $top, $width, 64)
    $g.DrawString("grape", $font, $brush, $rect, $format)
    $format.Dispose()
    $brush.Dispose()
    $font.Dispose()
}

function Draw-PlatformLabel($g, [int]$width, [int]$height) {
    $font = New-Object System.Drawing.Font($ui, 12, [System.Drawing.FontStyle]::Regular, [System.Drawing.GraphicsUnit]::Pixel)
    $brush = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(118, 161, 161, 168))
    $format = New-Object System.Drawing.StringFormat
    $format.Alignment = [System.Drawing.StringAlignment]::Center
    $rect = New-Object System.Drawing.RectangleF(0, ($height - 58), $width, 24)
    $g.DrawString("para Windows", $font, $brush, $rect, $format)
    $format.Dispose()
    $brush.Dispose()
    $font.Dispose()
}

function New-Large([int]$width, [int]$height, [string]$fileName) {
    $pair = New-Canvas $width $height
    $bmp = $pair[0]
    $g = $pair[1]
    try {
        $rect = New-Object System.Drawing.Rectangle(0, 0, $width, $height)
        $bg = New-Object System.Drawing.Drawing2D.LinearGradientBrush($rect, [System.Drawing.Color]::FromArgb(11, 11, 12), [System.Drawing.Color]::FromArgb(20, 16, 31), 90)
        $g.FillRectangle($bg, $rect)
        $bg.Dispose()
        Add-Glow $g $width $height
        Add-Grid $g $width $height
        Add-Rings $g $width $height

        $logoWidth = [int]($width * 0.445)
        $logoHeight = [int]($logoWidth * 499 / 512)
        $logoX = [int](($width - $logoWidth) / 2)
        $logoY = [int]($height * 0.165)
        Draw-Logo $g $logoPath $logoX $logoY $logoWidth 1

        $wordTop = $height * 0.455
        Draw-Wordmark $g $width $wordTop
        Draw-PlatformLabel $g $width $height

        $dot = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(160, 110, 231, 183))
        $g.FillEllipse($dot, [int](($width / 2) - 2), [int]($height * 0.535), 4, 4)
        $dot.Dispose()

        $png = [System.IO.Path]::ChangeExtension($fileName, ".png")
        $bmp.Save($fileName, [System.Drawing.Imaging.ImageFormat]::Bmp)
        $bmp.Save($png, [System.Drawing.Imaging.ImageFormat]::Png)
    }
    finally {
        $g.Dispose()
        $bmp.Dispose()
    }
}

function New-Small([int]$size, [string]$fileName) {
    $pair = New-Canvas $size $size
    $bmp = $pair[0]
    $g = $pair[1]
    try {
        $rect = New-Object System.Drawing.Rectangle(0, 0, $size, $size)
        $bg = New-Object System.Drawing.Drawing2D.LinearGradientBrush($rect, [System.Drawing.Color]::FromArgb(13, 10, 19), [System.Drawing.Color]::FromArgb(22, 16, 37), 90)
        $g.FillRectangle($bg, $rect)
        $bg.Dispose()

        $glow = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(55, 124, 58, 237))
        $g.FillEllipse($glow, [int]($size * 0.12), [int]($size * 0.10), [int]($size * 0.76), [int]($size * 0.76))
        $glow.Dispose()

        $logoWidth = [int]($size * 0.64)
        $logoHeight = [int]($logoWidth * 499 / 512)
        $logoX = [int](($size - $logoWidth) / 2)
        $logoY = [int](($size - $logoHeight) / 2)
        Draw-Logo $g $logoPath $logoX $logoY $logoWidth 1

        $pen = New-Object System.Drawing.Pen([System.Drawing.Color]::FromArgb(55, 246, 241, 255), 1)
        $g.DrawRectangle($pen, 0, 0, ($size - 1), ($size - 1))
        $pen.Dispose()

        $png = [System.IO.Path]::ChangeExtension($fileName, ".png")
        $bmp.Save($fileName, [System.Drawing.Imaging.ImageFormat]::Bmp)
        $bmp.Save($png, [System.Drawing.Imaging.ImageFormat]::Png)
    }
    finally {
        $g.Dispose()
        $bmp.Dispose()
    }
}

function Resize-Image([string]$source, [int]$width, [int]$height, [string]$target) {
    $input = [System.Drawing.Image]::FromFile($source)
    try {
        $bmp = New-Object System.Drawing.Bitmap($width, $height, [System.Drawing.Imaging.PixelFormat]::Format24bppRgb)
        $g = [System.Drawing.Graphics]::FromImage($bmp)
        try {
            $g.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
            $g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::HighQuality
            $g.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
            $g.DrawImage($input, 0, 0, $width, $height)
            $bmp.Save($target, [System.Drawing.Imaging.ImageFormat]::Bmp)
        }
        finally {
            $g.Dispose()
            $bmp.Dispose()
        }
    }
    finally { $input.Dispose() }
}

$large2x = Join-Path $OutputDir "wizard-large@2x.bmp"
$large = Join-Path $OutputDir "wizard-large.bmp"
$small2x = Join-Path $OutputDir "wizard-small@2x.bmp"
$small = Join-Path $OutputDir "wizard-small.bmp"

New-Large 480 918 $large2x
Resize-Image $large2x 240 459 $large
New-Small 294 $small2x
Resize-Image $small2x 147 147 $small

$iconSource = Join-Path $root "build\grape.ico"
$iconTarget = Join-Path $OutputDir "grape.ico"
if (Test-Path -LiteralPath $iconSource) { Copy-Item -LiteralPath $iconSource -Destination $iconTarget -Force }

$fonts.Dispose()
Write-Host ("Assets gerados em: " + $OutputDir)
Get-ChildItem -LiteralPath $OutputDir -File | Select-Object Name,Length | Format-Table -AutoSize