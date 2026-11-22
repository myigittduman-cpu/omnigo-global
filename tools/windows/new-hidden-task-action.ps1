function New-OmnigoHiddenTaskAction {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory)][string]$Executable,
        [string]$Arguments = '',
        [Parameter(Mandatory)][string]$WorkingDirectory,
        [Parameter(Mandatory)][string]$LauncherPath
    )
    $ErrorActionPreference = 'Stop'
    foreach ($value in @($Executable, $Arguments, $WorkingDirectory, $LauncherPath)) {
        if ($value -match '[\r\n\x00]') { throw 'Başlatıcı girdileri satır sonu veya boş karakter içeremez.' }
    }
    if ($Executable.Contains('"') -or $LauncherPath.Contains('"')) { throw 'Dosya yolu çift tırnak içeremez.' }
    if (!(Test-Path -LiteralPath $Executable -PathType Leaf)) { throw 'Çalıştırılacak program bulunamadı.' }
    if (!(Test-Path -LiteralPath $WorkingDirectory -PathType Container)) { throw 'Çalışma klasörü bulunamadı.' }
    $LauncherPath = [IO.Path]::GetFullPath($LauncherPath)
    if ([IO.Path]::GetExtension($LauncherPath) -ne '.vbs') { throw 'Başlatıcı uzantısı .vbs olmalıdır.' }
    $command = '"' + $Executable + '"'
    if ($Arguments) { $command += ' ' + $Arguments }
    $lines = [Collections.Generic.List[string]]::new()
    $lines.Add('Option Explicit')
    $lines.Add('Dim shell, command, result')
    $lines.Add('Set shell = CreateObject("WScript.Shell")')
    $lines.Add('shell.CurrentDirectory = "' + $WorkingDirectory.Replace('"', '""') + '"')
    $lines.Add('command = ""')
    # Uzun EncodedCommand değerleri VBScript satır sınırına takılmamalıdır.
    for ($offset = 0; $offset -lt $command.Length; $offset += 200) {
        $chunk = $command.Substring($offset, [Math]::Min(200, $command.Length - $offset))
        $lines.Add('command = command & "' + $chunk.Replace('"', '""') + '"')
    }
    $lines.Add('result = shell.Run(command, 0, True)')
    $lines.Add('WScript.Quit result')
    [IO.Directory]::CreateDirectory([IO.Path]::GetDirectoryName($LauncherPath)) | Out-Null
    [IO.File]::WriteAllLines($LauncherPath, $lines.ToArray(), [Text.Encoding]::Unicode)
    New-ScheduledTaskAction -Execute (Join-Path $env:WINDIR 'System32\wscript.exe') -Argument ('//B //NoLogo "' + $LauncherPath + '"') -WorkingDirectory $WorkingDirectory
}
