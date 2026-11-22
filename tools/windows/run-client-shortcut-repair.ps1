param([string]$ProjectRoot=(Resolve-Path (Join-Path $PSScriptRoot '../..')).Path)
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot 'new-hidden-task-action.ps1')
$taskName='Omnigo - Musteri Simge Onarimi 20261107'
if(Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue){throw 'Aynı adlı görev var; değiştirilmedi.'}
$script=Join-Path $PSScriptRoot 'repair-client-store-shortcut.ps1'
$source='& {'+[IO.File]::ReadAllText($script)+"`n} -ProjectRoot '"+$ProjectRoot.Replace("'","''")+"'"
$encoded=[Convert]::ToBase64String([Text.Encoding]::Unicode.GetBytes($source))
$action=New-OmnigoHiddenTaskAction -Executable (Join-Path $env:WINDIR 'System32\WindowsPowerShell\v1.0\powershell.exe') -Arguments ('-NoProfile -NonInteractive -EncodedCommand '+$encoded) -WorkingDirectory $ProjectRoot -LauncherPath (Join-Path $PSScriptRoot 'launchers\repair-client-shortcut-20261107.vbs')
$principal=New-ScheduledTaskPrincipal -UserId ([Security.Principal.WindowsIdentity]::GetCurrent().Name) -LogonType Interactive -RunLevel Limited
try {
    Register-ScheduledTask -TaskName $taskName -Action $action -Principal $principal -Description 'Yalnız OG Müşteri Portalı masaüstü simgesini kalıcı kaynağa bağlayan tek seferlik bakım.' | Out-Null
    Start-ScheduledTask -TaskName $taskName
    $file=Join-Path $ProjectRoot 'verification\customer-shortcut-20261107\result.json'
    for($i=0;$i -lt 20;$i++){if(Test-Path -LiteralPath $file){break};Start-Sleep -Seconds 1}
    if(-not(Test-Path -LiteralPath $file)){throw 'Simge doğrulama sonucu alınamadı.'}
    Get-Content -LiteralPath $file
} finally {
    $task=Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
    if($task -and $task.State -ne 'Running'){Unregister-ScheduledTask -TaskName $taskName -Confirm:$false}
}
