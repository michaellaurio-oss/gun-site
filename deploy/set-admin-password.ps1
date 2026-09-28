<#
Set (or change) the admin password for the live site.

  powershell -ExecutionPolicy Bypass -File deploy\set-admin-password.ps1

Asks for the password twice, stores only a secure hash in deploy\config.local.php
(never committed), then uploads it to the server as app/config.local.php.
#>
$ErrorActionPreference = 'Stop'

$php = (Get-Command php -ErrorAction SilentlyContinue).Source
if (-not $php) {
    $php = Get-ChildItem "$env:LOCALAPPDATA\Microsoft\WinGet\Packages" -Recurse -Filter php.exe -ErrorAction SilentlyContinue | Select-Object -First 1 -ExpandProperty FullName
}
if (-not $php) { throw 'PHP not found.' }

function Read-Plain([string]$prompt) {
    $s = Read-Host -Prompt $prompt -AsSecureString
    $b = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($s)
    try { return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($b) } finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($b) }
}

$pw1 = Read-Plain 'New admin password (at least 12 characters)'
if ($pw1.Length -lt 12) { throw 'Please use at least 12 characters.' }
$pw2 = Read-Plain 'Type it again'
if ($pw1 -ne $pw2) { throw 'The two passwords did not match.' }

$contact = Read-Host -Prompt 'Email address for website messages (leave empty to only keep them in the admin inbox)'

# Hash via stdin so the password never appears on a command line.
# No double quotes in the PHP code (Windows PowerShell 5.1 strips them when calling php.exe),
# and no byte-order mark on stdin (PowerShell adds one by default; PHP also removes it just in case).
$OutputEncoding = New-Object System.Text.UTF8Encoding $false
$hash = $pw1 | & $php -r '$s = rtrim(fgets(STDIN), chr(13) . chr(10)); $b = chr(239) . chr(187) . chr(191); if (strpos($s, $b) === 0) { $s = substr($s, 3); } echo password_hash($s, PASSWORD_DEFAULT);'
$pw1 = $null; $pw2 = $null
if (-not $hash -or -not $hash.StartsWith('$2y$')) { throw 'Could not create the password hash.' }
$salt = -join ((1..32) | ForEach-Object { '{0:x}' -f (Get-Random -Maximum 16) })

$lines = @(
    '<?php',
    '// Live-server settings. Not committed to git. Recreate with deploy\set-admin-password.ps1.',
    'return [',
    "    'admin_password_hash' => '$hash',",
    "    'contact_to'          => '$($contact.Replace("'", ''))',",
    "    'contact_from'        => '',",
    "    'ip_salt'             => '$salt',",
    "    'debug'               => false,",
    '];'
)
$file = Join-Path $PSScriptRoot 'config.local.php'
Set-Content -Path $file -Value $lines -Encoding ascii
Write-Host "Saved $file"

& (Join-Path $PSScriptRoot 'deploy.ps1') -Config
Write-Host 'Done. Log in at https://the-laurios.com/gun-site/admin/'
