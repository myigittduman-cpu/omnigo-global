param([ValidatePattern('^Local\\OmnigoBackup-[a-f0-9]{32}$')][string]$Name)
$ErrorActionPreference='Stop'
$mutex=New-Object System.Threading.Mutex($false,$Name)
$owned=$false
try {
    try { $owned=$mutex.WaitOne(0) } catch [System.Threading.AbandonedMutexException] { $owned=$true }
    if (!$owned) { [Console]::Out.WriteLine('busy'); exit 2 }
    [Console]::Out.WriteLine('acquired');[Console]::Out.Flush()
    # The parent closes stdin on success, failure or process termination.
    [void][Console]::In.ReadLine()
} finally {
    if ($owned) { $mutex.ReleaseMutex() }
    $mutex.Dispose()
}
