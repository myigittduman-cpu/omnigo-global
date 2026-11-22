param([ValidateSet('Protect','Unprotect')][string]$Mode)
$ErrorActionPreference='Stop'
Add-Type -AssemblyName System.Security
$value=[Convert]::FromBase64String([Console]::In.ReadToEnd().Trim())
$scope=[System.Security.Cryptography.DataProtectionScope]::CurrentUser
if ($Mode -eq 'Protect') {
    $result=[System.Security.Cryptography.ProtectedData]::Protect($value,$null,$scope)
} else {
    $result=[System.Security.Cryptography.ProtectedData]::Unprotect($value,$null,$scope)
}
[Console]::Out.Write([Convert]::ToBase64String($result))
[Array]::Clear($value,0,$value.Length)
[Array]::Clear($result,0,$result.Length)
