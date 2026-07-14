#Requires -Version 5.1
<#
.SYNOPSIS
    Auditoría competitiva de 10 nichos WordPress/WooCommerce vía API oficial.
.EXAMPLE
    .\Invoke-CommerceOpsAudit.ps1
    .\Invoke-CommerceOpsAudit.ps1 -OutputPath ..\data\audit-custom.json
#>
[CmdletBinding()]
param(
    [string]$OutputPath,
    [switch]$Quiet
)

$ErrorActionPreference = "Stop"
Import-Module (Join-Path $PSScriptRoot "..\lib\CommerceOps.psm1") -Force

$report = Invoke-CommerceOpsAudit -OutputPath $OutputPath -Quiet:$Quiet
exit 0
