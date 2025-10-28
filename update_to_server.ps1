<#
    PowerShell deployment helper for ViewStatsDash.
    Mirrors the behaviour of update_to_server.sh for Windows environments.
#>

[CmdletBinding()]
param(
    [string]$RemoteUser = $(if ($env:REMOTE_USER) { $env:REMOTE_USER } else { 'root' }),
    [string]$RemoteHost = $env:REMOTE_HOST,
    [string]$TypechoInstallDir = $(if ($env:TYPECHO_INSTALL_DIR) { $env:TYPECHO_INSTALL_DIR } else { '/root/app/typecho' })
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

if (-not $RemoteHost) {
    $RemoteHost = Read-Host 'Enter remote host (REMOTE_HOST)'
    if (-not $RemoteHost) {
        throw 'REMOTE_HOST is not set. Provide -RemoteHost, set REMOTE_HOST, or enter a value when prompted.'
    }
}

$requiredCommands = @('ssh', 'scp', 'tar')
foreach ($cmd in $requiredCommands) {
    if (-not (Get-Command $cmd -ErrorAction SilentlyContinue)) {
        throw "Required command '$cmd' is not available in PATH."
    }
}

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$pluginName = Split-Path -Leaf $scriptDir
$remoteDir = "$TypechoInstallDir/usr/plugins/$pluginName"
$remote = "$RemoteUser@$RemoteHost"

$timestamp = Get-Date -Format 'yyyyMMddHHmmss'
$tempArchive = Join-Path -Path ([System.IO.Path]::GetTempPath()) -ChildPath ("$pluginName-$timestamp.tar.gz")
$remoteArchive = "/tmp/$pluginName-$timestamp.tar.gz"

$tarArgs = @('--exclude=.git', '--exclude=.github', '--exclude=.DS_Store', '-czf', $tempArchive, '-C', $scriptDir, '.')
& tar @tarArgs
if ($LASTEXITCODE -ne 0) {
    throw "tar command failed with exit code $LASTEXITCODE."
}

try {
    $scpTarget = "$remote`:$remoteArchive"
    & scp $tempArchive $scpTarget
    if ($LASTEXITCODE -ne 0) {
        throw "scp upload failed with exit code $LASTEXITCODE."
    }

    $remoteCmd = "rm -rf '$remoteDir' && mkdir -p '$remoteDir' && tar -xzf '$remoteArchive' -C '$remoteDir' && rm -f '$remoteArchive'"
    & ssh $remote $remoteCmd
    if ($LASTEXITCODE -ne 0) {
        throw "Remote deployment command failed with exit code $LASTEXITCODE."
    }

    Write-Host "Deploy completed to $remoteDir on $remote"
}
finally {
    if (Test-Path $tempArchive) {
        Remove-Item $tempArchive -Force
    }
}
