# CortenDesk Agent installer for Windows, served by @SERVER@.
# Usage (PowerShell as Administrator):
#   & ([scriptblock]::Create((irm @SERVER@/agent/v1/install.ps1))) -Token '<token>'
param(
    [Parameter(Mandatory = $true)][string]$Token,
    [string]$CaFile = ''
)
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$Server = '@SERVER@'
$Version = '@VERSION@'

$principal = [Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Run PowerShell as Administrator.'
}

$arch = if ($env:PROCESSOR_ARCHITECTURE -eq 'ARM64') { 'arm64' } else { 'amd64' }
$files = @{
@PS_FILES@
}
$file = $files[$arch]
if (-not $file -or -not $file.Sha256) {
    throw "No CortenDesk Agent $Version build for windows-$arch is published for this console yet."
}

# Windows PowerShell 5.1 may still default to TLS 1.0.
[Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12

$tmp = Join-Path $env:TEMP ('cortendesk-agent-' + [guid]::NewGuid().ToString() + '.exe')
try {
    Write-Host "Downloading CortenDesk Agent $Version for windows-$arch"
    Invoke-WebRequest -UseBasicParsing -Uri $file.Url -OutFile $tmp

    $hash = (Get-FileHash -Algorithm SHA256 -Path $tmp).Hash.ToLowerInvariant()
    if ($hash -ne $file.Sha256) {
        throw "Checksum mismatch: expected $($file.Sha256), got $hash"
    }

    $argList = @('install', '--server', $Server, '--token', $Token)
    if ($CaFile) { $argList += @('--ca-file', $CaFile) }
    & $tmp @argList
    if ($LASTEXITCODE -ne 0) { throw "Install failed (exit code $LASTEXITCODE)" }
} finally {
    Remove-Item -Force -ErrorAction SilentlyContinue $tmp
}
