$ErrorActionPreference='Stop'
$projectPath=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$runner=Join-Path $PSScriptRoot 'run-hidden.ps1'
$statusFile=Join-Path $projectPath 'data/recovery/status.json'
if (!(Test-Path -LiteralPath $statusFile)) { throw 'Önce sunucu dışı kopyayı başarıyla doğrulayın.' }
$status=Get-Content -LiteralPath $statusFile -Raw | ConvertFrom-Json
if ($status.state -ne 'verified') { throw 'Doğrulanmış yedek kopyası gerekiyor.' }
$taskName='Omnigo Portal Yedek Kopyasi'
$nodeExecutable=(Get-Command node -ErrorAction Stop).Source
$legacyArguments='-NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -File "'+$runner+'"'
$taskArguments=$legacyArguments+' -NodePath "'+$nodeExecutable+'"'
. (Join-Path $PSScriptRoot '../windows/new-hidden-task-action.ps1')
$powerShellExecutable=Join-Path $env:WINDIR 'System32/WindowsPowerShell/v1.0/powershell.exe'
$launcher=Join-Path $PSScriptRoot 'run-task-hidden.vbs'
$existing=Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
if ($existing) {
    $owned=$false
    if (@($existing.Actions).Count -eq 1) {
        $old=$existing.Actions[0]
        $owned=($old.Execute -eq $powerShellExecutable -and $old.Arguments -in @($legacyArguments,$taskArguments))
        if ($old.Execute -eq (Join-Path $env:WINDIR 'System32/wscript.exe') -and $old.Arguments -eq ('//B //NoLogo "'+$launcher+'"')) { $owned=$true }
        # Önceden bu bilgisayarda uygulanmış, komutu aynı olan gizli başlatıcı.
        $previousLauncher=Join-Path $projectPath '../../../2026-11-01/bun/outputs/console-fix/run-backup-hidden.vbs'
        if ($old.Execute -eq (Join-Path $env:WINDIR 'System32/wscript.exe') -and $old.Arguments -eq ('//B //NoLogo "'+[IO.Path]::GetFullPath($previousLauncher)+'"') -and (Test-Path -LiteralPath $previousLauncher)) {
            $expected=('"'+$powerShellExecutable+'" '+$taskArguments).Replace('"','""')
            $owned=(Get-Content -LiteralPath $previousLauncher -Raw).Contains('shell.Run("'+$expected+'", 0, True)')
        }
    }
    if (!$owned) { throw 'Aynı adlı farklı görev var; değiştirilmedi.' }
}
$action=New-OmnigoHiddenTaskAction -Executable $powerShellExecutable -Arguments $taskArguments -WorkingDirectory $projectPath -LauncherPath $launcher
$account=[Security.Principal.WindowsIdentity]::GetCurrent().Name
$daily=New-ScheduledTaskTrigger -Daily -At '07:30'
$hourly=New-ScheduledTaskTrigger -Once -At ((Get-Date).AddMinutes(2)) -RepetitionInterval (New-TimeSpan -Hours 1)
$logon=New-ScheduledTaskTrigger -AtLogOn -User $account
$triggers=@($daily,$hourly,$logon)
$settings=New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 15) -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 5) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
$description='Doğrulanmış şifreli portal yedeği: günlük 07:30, oturum açılışı ve saatlik telafi kontrolü. Güncel kopyayı yeniden indirmez. Windows hesabı açık olmalıdır.'
if($existing){
 $existing.Actions=@($action);$existing.Triggers=$triggers;$existing.Settings=$settings;$existing.Description=$description
 Set-ScheduledTask -InputObject $existing | Select-Object TaskName,State
}else{
 $principal=New-ScheduledTaskPrincipal -UserId $account -LogonType Interactive -RunLevel Limited
 Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $triggers -Settings $settings -Principal $principal -Description $description | Select-Object TaskName,State
}
