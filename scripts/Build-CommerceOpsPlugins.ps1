#Requires -Version 5.1
<#
.SYNOPSIS
    Empaqueta plugins CommerceOps con commerceops-core embebido en dist/*.zip
.EXAMPLE
    .\Build-CommerceOpsPlugins.ps1 -All
    .\Build-CommerceOpsPlugins.ps1 -PluginSlug woo-ai-schema
#>
[CmdletBinding()]
param(
    [string]$PluginSlug,
    [switch]$All
)

$ErrorActionPreference = "Stop"
Import-Module (Join-Path $PSScriptRoot "..\lib\CommerceOps.psm1") -Force

if (-not $All -and -not $PluginSlug) {
    throw "Specify -PluginSlug <name> or -All"
}

$params = @{ All = $All }
if ($PluginSlug) { $params.PluginSlug = $PluginSlug }

Build-CommerceOpsPlugin @params | Out-Null
exit 0
