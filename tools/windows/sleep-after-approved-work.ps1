param(
    [ValidateRange(5, 60)][int]$DelaySeconds = 15,
    [Parameter(Mandatory)][string]$ResultPath
)
$ErrorActionPreference = 'Stop'
Start-Sleep -Seconds $DelaySeconds
$sleepAudit = [ordered]@{ requestedAt = (Get-Date).ToUniversalTime().ToString('o'); requestedState = 'sleep'; hibernate = $false; forced = $false; requestedByUser = $true }
$sleepAudit | ConvertTo-Json | Set-Content -LiteralPath $ResultPath -Encoding utf8
try {
    Add-Type -AssemblyName System.Windows.Forms
    $sleepAudit.accepted = [System.Windows.Forms.Application]::SetSuspendState([System.Windows.Forms.PowerState]::Suspend, $false, $false)
    $sleepAudit.returnedAt = (Get-Date).ToUniversalTime().ToString('o')
    $sleepAudit | ConvertTo-Json | Set-Content -LiteralPath $ResultPath -Encoding utf8
    if (!$sleepAudit.accepted) { exit 1 }
} catch {
    $sleepAudit.error = $_.Exception.Message
    $sleepAudit | ConvertTo-Json | Set-Content -LiteralPath $ResultPath -Encoding utf8
    exit 1
}
