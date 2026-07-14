#Requires -Version 5.1
<#
.SYNOPSIS
    Abre el formulario WordPress.org con el ZIP listo.
#>
[CmdletBinding()]
param(
    [ValidateSet('woo-ai-schema','order-webhook-hub','bulk-product-sync')]
    [string]$Plugin = 'woo-ai-schema'
)

$ErrorActionPreference = 'Stop'
Import-Module (Join-Path $PSScriptRoot '..\lib\CommerceOps.psm1') -Force

Build-CommerceOpsPlugin -PluginSlug $Plugin | Out-Null
$zip = Join-Path (Get-CommerceOpsRoot) "dist\$Plugin.zip"

Write-Host ""
Write-Host "=== WordPress.org - subir plugin ===" -ForegroundColor Cyan
Write-Host "ZIP: $zip"
Write-Host ""
Write-Host "1. Inicia sesion en wordpress.org (cuenta: rovidev95@gmail.com)"
Write-Host "2. Se abrira el formulario de envio"
Write-Host "3. Sube el ZIP de arriba"
Write-Host "4. Slug sugerido: $Plugin"
Write-Host ""

if (Test-Path $zip) {
    Set-Clipboard -Path $zip -ErrorAction SilentlyContinue
    Write-Host "ZIP copiado al portapapeles (si el sistema lo permite)." -ForegroundColor Green
}

Start-Process 'https://wordpress.org/plugins/developers/add/'
Write-Host "Formulario abierto en el navegador." -ForegroundColor Green
