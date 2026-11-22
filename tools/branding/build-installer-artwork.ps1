param([string]$ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path)
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Drawing
$outputDirectory = Join-Path $ProjectRoot 'desktop/windows/branding'
[System.IO.Directory]::CreateDirectory($outputDirectory) | Out-Null
$logo = [System.Drawing.Bitmap]::new((Join-Path $ProjectRoot 'desktop/icons/icon.png'))
$renderScale = 6

function Write-BrandText($Graphics, [string]$Text, [float]$Size, [string]$Color, [float]$X, [float]$Y, [float]$Width, [float]$Height, [bool]$Bold = $false) {
    $style = if ($Bold) { [System.Drawing.FontStyle]::Bold } else { [System.Drawing.FontStyle]::Regular }
    $font = [System.Drawing.Font]::new('Segoe UI', $Size, $style, [System.Drawing.GraphicsUnit]::Pixel)
    $brush = [System.Drawing.SolidBrush]::new([System.Drawing.ColorTranslator]::FromHtml($Color))
    try { $Graphics.DrawString($Text, $font, $brush, [System.Drawing.RectangleF]::new($X,$Y,$Width,$Height)) }
    finally { $brush.Dispose(); $font.Dispose() }
}

try {
    foreach ($role in @('client','admin')) {
        # Segoe UI 9 dialog proportions; 6x master prevents upscaling at high DPI.
        $canvas = [System.Drawing.Bitmap]::new(164*$renderScale,362*$renderScale,[System.Drawing.Imaging.PixelFormat]::Format24bppRgb)
        $graphics = [System.Drawing.Graphics]::FromImage($canvas)
        $gradient = [System.Drawing.Drawing2D.LinearGradientBrush]::new([System.Drawing.Rectangle]::new(0,0,164,362),[System.Drawing.ColorTranslator]::FromHtml('#091423'),[System.Drawing.ColorTranslator]::FromHtml('#1C3049'),[single]35)
        $line = [System.Drawing.Pen]::new([System.Drawing.ColorTranslator]::FromHtml('#776440'),[single]0.6)
        try {
            $graphics.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
            $graphics.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
            $graphics.TextRenderingHint = [System.Drawing.Text.TextRenderingHint]::AntiAliasGridFit
            $graphics.ScaleTransform($renderScale, $renderScale)
            $graphics.FillRectangle($gradient,0,0,164,362)
            $graphics.DrawArc($line,-90,230,260,260,235,210)
            $graphics.DrawArc($line,-70,250,260,260,235,210)
            $graphics.DrawImage($logo,[System.Drawing.Rectangle]::new(24,30,116,116))
            Write-BrandText $graphics 'OMNIGO GLOBAL' 10 '#E2C682' 18 166 144 22 $true
            $title = if($role -eq 'client') { "Müşteri`nPortalı" } else { "Yönetici`nPaneli" }
            Write-BrandText $graphics $title 22 '#F4F6F9' 17 197 140 70 $true
            Write-BrandText $graphics 'İşinizle aynı ritimde.' 10 '#C5CEDA' 18 302 138 20
            $graphics.DrawLine($line,18,334,146,334)
            Write-BrandText $graphics 'WINDOWS İÇİN' 8 '#D5B775' 18 341 130 14
            $canvas.Save((Join-Path $outputDirectory "$role-sidebar.bmp"),[System.Drawing.Imaging.ImageFormat]::Bmp)
            $canvas.Save((Join-Path $outputDirectory "$role-sidebar.png"),[System.Drawing.Imaging.ImageFormat]::Png)
        } finally { $line.Dispose(); $gradient.Dispose(); $graphics.Dispose(); $canvas.Dispose() }
    }
    $header = [System.Drawing.Bitmap]::new(150*$renderScale,67*$renderScale,[System.Drawing.Imaging.PixelFormat]::Format24bppRgb)
    $graphics = [System.Drawing.Graphics]::FromImage($header)
    try {
        $graphics.Clear([System.Drawing.Color]::White)
        $graphics.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
        $graphics.TextRenderingHint = [System.Drawing.Text.TextRenderingHint]::AntiAliasGridFit
        $graphics.ScaleTransform($renderScale, $renderScale)
        $graphics.DrawImage($logo,[System.Drawing.Rectangle]::new(102,11,45,45))
        Write-BrandText $graphics 'OMNIGO' 13 '#17273A' 18 18 85 20 $true
        Write-BrandText $graphics 'GLOBAL' 9 '#876F39' 39 37 65 16
        $header.Save((Join-Path $outputDirectory 'header.bmp'),[System.Drawing.Imaging.ImageFormat]::Bmp)
        $header.Save((Join-Path $outputDirectory 'header.png'),[System.Drawing.Imaging.ImageFormat]::Png)
    } finally { $graphics.Dispose(); $header.Dispose() }
} finally { $logo.Dispose() }
Write-Output "Kurulum görselleri hazır: $outputDirectory"
