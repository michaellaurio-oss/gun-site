<#
Deploy the site to the web host over FTPS (encrypted FTP).

  powershell -ExecutionPolicy Bypass -File deploy\deploy.ps1            upload changed files in public\
  powershell -ExecutionPolicy Bypass -File deploy\deploy.ps1 -DryRun    list what would be uploaded
  powershell -ExecutionPolicy Bypass -File deploy\deploy.ps1 -Full      upload everything again
  powershell -ExecutionPolicy Bypass -File deploy\deploy.ps1 -Backup    download the live database + photos to backups\
  powershell -ExecutionPolicy Bypass -File deploy\deploy.ps1 -InitDb    first deploy only: upload deploy\live-initial.db
  powershell -ExecutionPolicy Bypass -File deploy\deploy.ps1 -Config    upload deploy\config.local.php (admin password etc.)

Never uploads public\data\ (the live database), public\photos\ (uploaded photos) or
public\app\config.local.php. Reads FTP_HOST / FTP_USER / FTP_PASSWORD from .env.
#>
param(
    [switch]$DryRun,
    [switch]$Full,
    [switch]$Backup,
    [switch]$InitDb,
    [switch]$Config,
    # The FTP server's TLS certificate is issued to this name (same server as ftp.the-laurios.com).
    [string]$TlsHost = 'svr201.fastwebhost.com'
)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$public = Join-Path $root 'public'
$state = Join-Path $PSScriptRoot '.deploy-state.json'
$curl = Join-Path $env:SystemRoot 'System32\curl.exe'

# ---- credentials (.env) -> temporary netrc so the password never appears on a command line ----
$envFile = Join-Path $root '.env'
if (-not (Test-Path $envFile)) { throw '.env not found' }
$cfg = @{}
foreach ($line in Get-Content $envFile) {
    if ($line -match '^\s*([A-Z_]+)\s*=\s*(.*?)\s*$') { $cfg[$matches[1]] = $matches[2].Trim('"') }
}
foreach ($k in 'FTP_USER', 'FTP_PASSWORD') { if (-not $cfg[$k]) { throw "$k missing from .env" } }
$netrc = Join-Path $env:TEMP ('gunsite-' + [guid]::NewGuid().ToString('N') + '.netrc')
Set-Content -Path $netrc -Encoding ascii -Value ("machine $TlsHost login " + $cfg['FTP_USER'] + ' password ' + $cfg['FTP_PASSWORD'])
$base = "ftp://$TlsHost/"
$common = @('--ssl-reqd', '--netrc-file', $netrc, '--silent', '--show-error', '--retry', '2', '--connect-timeout', '30')

function Invoke-Curl([string[]]$curlArgs) {
    & $curl @common @curlArgs
    if ($LASTEXITCODE -ne 0) { throw "curl failed (exit $LASTEXITCODE)" }
}
function Get-RemoteList([string]$dir) {
    $out = & $curl @common '--list-only' ($base + $dir) 2>$null
    if ($LASTEXITCODE -ne 0) { return @() }
    return @($out | Where-Object { $_ -and $_ -ne '.' -and $_ -ne '..' })
}

try {
    if ($Backup) {
        $dest = Join-Path $root ('backups\' + (Get-Date -Format 'yyyyMMdd-HHmm'))
        New-Item -ItemType Directory -Force (Join-Path $dest 'photos') | Out-Null
        Write-Host "Backing up to $dest"
        Invoke-Curl @('-o', (Join-Path $dest 'gun_specs.db'), ($base + 'data/gun_specs.db'))
        $n = 0
        foreach ($folder in Get-RemoteList 'photos/') {
            if ($folder -like '.*') { continue }
            $local = Join-Path $dest "photos\$folder"
            New-Item -ItemType Directory -Force $local | Out-Null
            $pairs = @()
            foreach ($f in Get-RemoteList "photos/$folder/") { $pairs += @('-o', (Join-Path $local $f), ($base + "photos/$folder/$f")); $n++ }
            if ($pairs.Count) { Invoke-Curl $pairs }
        }
        Write-Host "Done: database + $n photo files."
        return
    }

    if ($InitDb) {
        $db = Join-Path $PSScriptRoot 'live-initial.db'
        if (-not (Test-Path $db)) { throw 'deploy\live-initial.db not found. Build it with: php dev/build_db.php real deploy/live-initial.db' }
        if ((Get-RemoteList 'data/') -contains 'gun_specs.db') { throw 'The server already has data/gun_specs.db. Refusing to overwrite the live database.' }
        Invoke-Curl @('--ftp-create-dirs', '-T', $db, ($base + 'data/gun_specs.db'))
        Write-Host 'Uploaded the initial database.'
        return
    }

    if ($Config) {
        $file = Join-Path $PSScriptRoot 'config.local.php'
        if (-not (Test-Path $file)) { throw 'deploy\config.local.php not found. Create it with: php -S 127.0.0.1:8765 deploy/password-tool.php' }
        Invoke-Curl @('--ftp-create-dirs', '-T', $file, ($base + 'app/config.local.php'))
        Write-Host 'Uploaded app/config.local.php.'
        return
    }

    # ---- normal deploy: changed files only ----
    $old = @{}
    if ((Test-Path $state) -and -not $Full) {
        (Get-Content $state -Raw | ConvertFrom-Json).PSObject.Properties | ForEach-Object { $old[$_.Name] = $_.Value }
    }
    $new = @{}
    $todo = @()
    foreach ($f in Get-ChildItem $public -Recurse -File -Force) {
        $rel = $f.FullName.Substring($public.Length + 1).Replace('\', '/')
        if ($rel -match '^data/' -and $rel -ne 'data/.htaccess') { continue }
        if ($rel -match '^photos/' -or $rel -match '^assets/demo/') { continue }
        if ($rel -eq 'app/config.local.php' -or $rel -match '^zz-') { continue }
        $hash = (Get-FileHash $f.FullName -Algorithm SHA256).Hash
        $new[$rel] = $hash
        if ($old[$rel] -ne $hash) { $todo += , @($f.FullName, $rel) }
    }
    if (-not $todo.Count) { Write-Host 'Nothing changed.'; return }
    Write-Host ("{0} file(s) to upload:" -f $todo.Count)
    $todo | ForEach-Object { Write-Host ('  ' + $_[1]) }
    if ($DryRun) { return }

    # One file per curl call so every upload is checked; the server size must match afterwards.
    $failed = @()
    foreach ($t in $todo) {
        $size = (Get-Item -LiteralPath $t[0] -Force).Length
        $ok = $false
        for ($try = 1; $try -le 3 -and -not $ok; $try++) {
            & $curl @common '--ftp-create-dirs' '-T' $t[0] ($base + $t[1]) 2>$null
            if ($LASTEXITCODE -eq 0) {
                $head = & $curl @common '--head' ($base + $t[1]) 2>$null
                $ok = [bool]($head | Where-Object { $_ -match "^Content-Length:\s*$size\s*$" })
            }
            if (-not $ok) { Start-Sleep -Seconds 2 }
        }
        if ($ok) { $old[$t[1]] = $new[$t[1]]; Write-Host ('  uploaded ' + $t[1]) }
        else { $failed += $t[1]; Write-Host ('  FAILED   ' + $t[1]) -ForegroundColor Red }
    }
    # Remember what's on the server so the next run only sends changes (failed files are retried).
    $keep = @{}
    foreach ($k in $new.Keys) { if ($old.ContainsKey($k)) { $keep[$k] = $old[$k] } }
    $keep | ConvertTo-Json | Set-Content -Path $state -Encoding ascii
    if ($failed.Count) { throw ("{0} file(s) failed to upload. Run the deploy again." -f $failed.Count) }
    Write-Host 'Deploy complete.'
}
finally {
    Remove-Item $netrc -Force -ErrorAction SilentlyContinue
}
