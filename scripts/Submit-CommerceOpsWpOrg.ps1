#Requires -Version 5.1
<#
.SYNOPSIS
    Envía plugin a WordPress.org con navegador visible (login manual si hace falta).
#>
[CmdletBinding()]
param(
    [string]$Plugin = 'woo-ai-schema',
    [int]$LoginWaitSeconds = 300
)

$ErrorActionPreference = 'Stop'
$coRoot = Split-Path $PSScriptRoot -Parent
Import-Module (Join-Path $coRoot 'lib\CommerceOps.psm1') -Force

Build-CommerceOpsPlugin -PluginSlug $Plugin | Out-Null
Get-Process chrome -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue
Start-Sleep -Seconds 2

$env:CO_PLUGIN_ZIP = Join-Path $coRoot "dist\$Plugin.zip"
$env:CO_LOGIN_WAIT_MS = ($LoginWaitSeconds * 1000)

Push-Location (Join-Path $coRoot 'automation')
try {
    node submit-wporg.mjs
    $code = $LASTEXITCODE
}
finally {
    Pop-Location
}

$resultPath = Join-Path $coRoot 'data\wporg-submit-result.json'
if (Test-Path $resultPath) {
    Get-Content $resultPath -Raw | ConvertFrom-Json | Format-List
}
exit $code
