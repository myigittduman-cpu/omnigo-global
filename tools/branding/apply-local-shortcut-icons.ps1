param([string]$ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path)
$ErrorActionPreference = 'Stop'
# A packaged host may virtualize LocalAppData; Explorer must see the same physical file.
$iconDirectory = Join-Path ([Environment]::GetFolderPath('MyDocuments')) 'Codex\Omnigo-Application-Icons'
$backupDirectory = Join-Path $ProjectRoot 'outputs/shortcut-icons-20261027/backup'
[System.IO.Directory]::CreateDirectory($iconDirectory) | Out-Null
[System.IO.Directory]::CreateDirectory($backupDirectory) | Out-Null
$shortcutShell = New-Object -ComObject WScript.Shell
$locations = @(
    @{Name='Desktop'; Path=[Environment]::GetFolderPath('Desktop')},
    @{Name='StartMenu'; Path=(Join-Path ([Environment]::GetFolderPath('StartMenu')) 'Programs')}
)
$roles = @(
    @{Key='client'; Name='OG Müşteri Portalı'},
    @{Key='admin'; Name='OG Yönetici Portalı'}
)
$results = foreach ($role in $roles) {
    $source = Join-Path $ProjectRoot "desktop/icons/$($role.Key)/icon.ico"
    $destination = Join-Path $iconDirectory "$($role.Key)-shortcut-v5.ico"
    Copy-Item -LiteralPath $source -Destination $destination -Force
    foreach ($location in $locations) {
        $shortcutPath = Join-Path $location.Path "$($role.Name).lnk"
        if (-not (Test-Path -LiteralPath $shortcutPath)) { continue }
        $shortcut = $shortcutShell.CreateShortcut($shortcutPath)
        $targetBefore = $shortcut.TargetPath
        if ([System.IO.Path]::GetFileName($targetBefore) -ne 'omnigo-desktop.exe') {
            throw "Unexpected shortcut target: $shortcutPath"
        }
        $backupPath = Join-Path $backupDirectory "$($location.Name)-$($role.Key).lnk"
        if (-not (Test-Path -LiteralPath $backupPath)) { Copy-Item -LiteralPath $shortcutPath -Destination $backupPath }
        $shortcut.IconLocation = "$destination,0"
        $shortcut.Save()
        $verified = $shortcutShell.CreateShortcut($shortcutPath)
        if ($verified.TargetPath -ne $targetBefore -or $verified.IconLocation -ne "$destination,0") {
            throw "Shortcut verification failed: $shortcutPath"
        }
        [pscustomobject]@{Role=$role.Key;Location=$location.Name;Path=$shortcutPath;Icon=$verified.IconLocation;TargetUnchanged=$true}
    }
}
$results | ConvertTo-Json
