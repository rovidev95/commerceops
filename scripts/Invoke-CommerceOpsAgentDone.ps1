#Requires -Version 5.1
<#
.SYNOPSIS
    Marca tarea CommerceOps completada y limpia cursor-wake.
.EXAMPLE
    .\Invoke-CommerceOpsAgentDone.ps1 -TaskId co-001 -Note "SVN submitted"
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory)]
    [string]$TaskId,
    [string]$Note = 'agent done'
)

$ErrorActionPreference = 'Stop'
Import-Module (Join-Path $PSScriptRoot '..\lib\CommerceOps.psm1') -Force

Complete-CommerceOpsWorkItem -TaskId $TaskId -Note $Note | Out-Null
Write-Host "Task $TaskId completed." -ForegroundColor Green

# Encolar siguiente tick parcial (build)
Build-CommerceOpsPlugin -All | Out-Null
Write-Host 'Plugins rebuilt in dist/' -ForegroundColor Cyan
exit 0
