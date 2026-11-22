param([Parameter(Mandatory=$true)][string]$ProjectRoot)
$ErrorActionPreference='Stop'
$reportRoot=Join-Path $ProjectRoot 'verification/customer-shortcut-20261107'
[IO.Directory]::CreateDirectory($reportRoot) | Out-Null
try {
    $package=Get-AppxPackage -Name 'OmnigoGlobal.OmnigoGlobalMteriPortal'
    if(-not $package){throw 'Müşteri Store paketi bulunamadı.'}
    $shortcutPath=Join-Path ([Environment]::GetFolderPath('Desktop')) 'OG Müşteri Portalı.lnk'
    $iconSource=Join-Path $package.InstallLocation 'Simgeler\Omnigo-Klasik.ico'
    if(-not(Test-Path -LiteralPath $shortcutPath) -or -not(Test-Path -LiteralPath $iconSource)){throw 'Kısayol veya paket simgesi bulunamadı.'}
    $shell=New-Object -ComObject WScript.Shell
    $link=$shell.CreateShortcut($shortcutPath)
    $target=Join-Path $package.InstallLocation 'omnigo-desktop.exe'
    if($link.TargetPath -ne $target){throw 'Kısayol farklı bir kuruluma ait; değiştirilmedi.'}
    $before=@{Target=$link.TargetPath;Arguments=$link.Arguments;WorkingDirectory=$link.WorkingDirectory;Description=$link.Description;Hotkey=$link.Hotkey;WindowStyle=$link.WindowStyle;Icon=$link.IconLocation}
    $folder=(New-Object -ComObject Shell.Application).Namespace([IO.Path]::GetDirectoryName($shortcutPath))
    $aumid=$folder.ParseName([IO.Path]::GetFileName($shortcutPath)).ExtendedProperty('System.AppUserModel.ID')
    $backup=Join-Path $reportRoot 'OG Musteri Portali.before.lnk'
    if(-not(Test-Path -LiteralPath $backup)){Copy-Item -LiteralPath $shortcutPath -Destination $backup}
    $hash=(Get-FileHash -LiteralPath $iconSource -Algorithm SHA256).Hash.ToLowerInvariant()
    $icons=Join-Path ([Environment]::GetFolderPath('LocalApplicationData')) 'OmnigoGlobal\ShortcutIcons'
    [IO.Directory]::CreateDirectory($icons) | Out-Null
    $icon=Join-Path $icons ('client-'+$hash+'.ico')
    Copy-Item -LiteralPath $iconSource -Destination $icon -Force
    $link.IconLocation=$icon+',0';$link.Save()
    $verified=$shell.CreateShortcut($shortcutPath)
    foreach($property in @('Target','Arguments','WorkingDirectory','Description','Hotkey','WindowStyle')){
        $actual=if($property -eq 'Target'){$verified.TargetPath}else{$verified.$property}
        if($actual -ne $before[$property]){throw ('Kısayol özelliği değişti: '+$property)}
    }
    $afterAumid=$folder.ParseName([IO.Path]::GetFileName($shortcutPath)).ExtendedProperty('System.AppUserModel.ID')
    if($aumid -ne $afterAumid -or $verified.IconLocation -ne $icon+',0' -or (Get-FileHash -LiteralPath $icon).Hash.ToLowerInvariant() -ne $hash){throw 'Simge veya uygulama kimliği doğrulanamadı.'}
    Add-Type -TypeDefinition 'using System;using System.Runtime.InteropServices;public static class OmnigoShortcutRefresh{[DllImport("shell32.dll",CharSet=CharSet.Unicode)]public static extern void SHChangeNotify(uint e,uint f,[MarshalAs(UnmanagedType.LPWStr)]string p,IntPtr q);}'
    [OmnigoShortcutRefresh]::SHChangeNotify(0x00002000,0x00001005,$shortcutPath,[IntPtr]::Zero)
    @{ok=$true;version=$package.Version.ToString();shortcut=$shortcutPath;icon=$icon;hash=$hash;targetPreserved=$true;appIdPreserved=$true;normalUserContext=$true;at=[DateTime]::UtcNow.ToString('o')} | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $reportRoot 'result.json') -Encoding UTF8
} catch {
    if($backup -and (Test-Path -LiteralPath $backup)){Copy-Item -LiteralPath $backup -Destination $shortcutPath -Force}
    @{ok=$false;error=$_.Exception.Message} | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $reportRoot 'result.json') -Encoding UTF8
    exit 1
}
