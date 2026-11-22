param([string]$NodePath)
$ErrorActionPreference='Stop'
$projectPath=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
Set-Location -LiteralPath $projectPath
$logPath=Join-Path $projectPath 'data/recovery/scheduled.log'
try {
    if (!$NodePath) { $NodePath=(Get-Command node -ErrorAction Stop).Source }
    if (!(Test-Path -LiteralPath $NodePath -PathType Leaf)) { throw 'Yedekleme calistirma dosyasi bulunamadi.' }
    & $NodePath (Join-Path $PSScriptRoot 'pull.mjs') *> $logPath
    exit $LASTEXITCODE
} catch {
    'Yedek kopyalama baslatilamadi. Calistirma dosyasini ve gorev ayarlarini kontrol edin.' | Set-Content -LiteralPath $logPath
    exit 1
}
