#Requires -Version 5.1
<#
.SYNOPSIS
    Prepara deploy SVN WordPress.org para woo-ai-schema.
.NOTES
    Requiere: cuenta wordpress.org, SVN password, slug aprobado.
#>
[CmdletBinding()]
param(
    [string]$SvnUsername = $env:WPORG_SVN_USER,
    [string]$PluginSlug = 'woo-ai-schema'
)

$ErrorActionPreference = 'Stop'
Import-Module (Join-Path $PSScriptRoot '..\lib\CommerceOps.psm1') -Force

$coRoot = Get-CommerceOpsRoot
Build-CommerceOpsPlugin -PluginSlug $PluginSlug | Out-Null
$zip = Join-Path $coRoot "dist\$PluginSlug.zip"

Write-Host "=== WordPress.org deploy prep: $PluginSlug ===" -ForegroundColor Cyan
Write-Host "ZIP: $zip"
Write-Host ""
Write-Host "Pasos manuales (requiere credenciales WP.org):" -ForegroundColor Yellow
Write-Host "1. svn co https://plugins.svn.wordpress.org/$PluginSlug/ svn-$PluginSlug"
Write-Host "2. Descomprimir dist\$PluginSlug.zip en svn-$PluginSlug/trunk/"
Write-Host "3. Copiar .wordpress-org/* a svn-$PluginSlug/assets/"
Write-Host "4. svn add --force svn-$PluginSlug/trunk svn-$PluginSlug/assets"
Write-Host "5. svn ci -m 'Release $(Get-Date -Format yyyy-MM-dd)' --username $SvnUsername"
Write-Host ""
Write-Host "Tag release: svn cp trunk tags/1.0.0 && svn ci tags/1.0.0"

if (-not $SvnUsername) {
    Write-Host "Tip: set WPORG_SVN_USER env var" -ForegroundColor DarkGray
}
