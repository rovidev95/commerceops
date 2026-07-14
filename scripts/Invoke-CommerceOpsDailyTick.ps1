#Requires -Version 5.1
<#
.SYNOPSIS
    Tick diario CommerceOps: build, auditoría, cola de trabajo, wake Cursor.
.EXAMPLE
    .\Invoke-CommerceOpsDailyTick.ps1
#>
[CmdletBinding()]
param([switch]$SkipWake)

$ErrorActionPreference = 'Stop'
$root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
if (Test-Path (Join-Path (Split-Path $PSScriptRoot -Parent) 'config.json')) {
    $root = Split-Path $PSScriptRoot -Parent
}
Import-Module (Join-Path (Split-Path $PSScriptRoot -Parent) 'lib\CommerceOps.psm1') -Force

$result = Invoke-CommerceOpsDailyTick -SkipWake:$SkipWake
if ($result.agent_task) {
    Write-Host ("Tarea agente: {0} - {1}" -f $result.agent_task.id, $result.agent_task.title) -ForegroundColor Magenta
}
exit 0
