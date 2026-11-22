param([string]$ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path,[string]$ArtworkOutput='',[int]$LabelSize=176)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Drawing
$sourceDirectory = Join-Path $ProjectRoot 'assets/app-icons/windows-source'
$outputDirectory = if($ArtworkOutput){[System.IO.Path]::GetFullPath($ArtworkOutput)}else{Join-Path $ProjectRoot 'assets/app-icons'}
[System.IO.Directory]::CreateDirectory($sourceDirectory) | Out-Null
[System.IO.Directory]::CreateDirectory($outputDirectory) | Out-Null

function Get-VisibleBounds([System.Drawing.Bitmap]$Bitmap, [int]$Top, [int]$Bottom) {
    $left = $Bitmap.Width; $right = -1; $first = $Bottom; $last = -1
    for ($y = $Top; $y -lt $Bottom; $y++) {
        for ($x = 0; $x -lt $Bitmap.Width; $x++) {
            if ($Bitmap.GetPixel($x, $y).A -gt 48) {
                $left = [Math]::Min($left, $x); $right = [Math]::Max($right, $x)
                $first = [Math]::Min($first, $y); $last = [Math]::Max($last, $y)
            }
        }
    }
    if ($right -lt 0) { throw 'Source artwork contains no visible pixels.' }
    return [System.Drawing.Rectangle]::new($left, $first, $right-$left+1, $last-$first+1)
}

foreach ($role in @('client', 'admin')) {
    $target = Join-Path $outputDirectory "$role-windows.png"
    $original = Join-Path $sourceDirectory "$role-original.png"
    if (-not (Test-Path -LiteralPath $original)) { Copy-Item -LiteralPath $target -Destination $original }
    $source = [System.Drawing.Bitmap]::new((Join-Path $ProjectRoot 'desktop/icons/icon.png'))
    $canvas = [System.Drawing.Bitmap]::new(1024, 1024, [System.Drawing.Imaging.PixelFormat]::Format32bppArgb)
    $graphics = [System.Drawing.Graphics]::FromImage($canvas)
    try {
        $graphics.Clear([System.Drawing.Color]::Transparent)
        $graphics.CompositingMode = [System.Drawing.Drawing2D.CompositingMode]::SourceCopy
        $graphics.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
        $graphics.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
        $globe = Get-VisibleBounds $source 0 $source.Height
        $globeScale = [Math]::Min(976.0/$globe.Width, 976.0/$globe.Height)
        $globeWidth = [single]($globe.Width*$globeScale)
        $globeHeight = [single]($globe.Height*$globeScale)
        $graphics.DrawImage($source, [System.Drawing.RectangleF]::new((1024-$globeWidth)/2, (1024-$globeHeight)/2, $globeWidth, $globeHeight), $globe, [System.Drawing.GraphicsUnit]::Pixel)
        # One font and one pixel size for both labels; never stretch text to equal widths.
        $graphics.CompositingMode = [System.Drawing.Drawing2D.CompositingMode]::SourceOver
        $graphics.TextRenderingHint = [System.Drawing.Text.TextRenderingHint]::AntiAliasGridFit
        $font = [System.Drawing.FontFamily]::new('Segoe UI')
        $brush = [System.Drawing.SolidBrush]::new([System.Drawing.ColorTranslator]::FromHtml('#FFE5A3'))
        $outline = [System.Drawing.Pen]::new([System.Drawing.ColorTranslator]::FromHtml('#071322'), 16)
        $outline.LineJoin = [System.Drawing.Drawing2D.LineJoin]::Round
        $textPath = [System.Drawing.Drawing2D.GraphicsPath]::new()
        $format = [System.Drawing.StringFormat]::new()
        try {
            $format.Alignment = [System.Drawing.StringAlignment]::Center
            $format.LineAlignment = [System.Drawing.StringAlignment]::Center
            $labelText = if ($role -eq 'client') { 'Müşteri' } else { 'Yönetici' }
            $graphics.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
            $textPath.AddString($labelText, $font, [int][System.Drawing.FontStyle]::Bold, $LabelSize, [System.Drawing.RectangleF]::new(70, 380, 884, 205), $format)
            $graphics.DrawPath($outline, $textPath)
            $graphics.FillPath($brush, $textPath)
        } finally { $textPath.Dispose(); $outline.Dispose(); $format.Dispose(); $brush.Dispose(); $font.Dispose() }
        # The role sits over the globe, above Windows' bottom-left shortcut overlay.
        $canvas.Save($target, [System.Drawing.Imaging.ImageFormat]::Png)
        Write-Output "$role`: 1024x1024, full-size globe and matching overlay label."
    } finally { $graphics.Dispose(); $canvas.Dispose(); $source.Dispose() }
}
