#Requires -Version 5.1
<#
.SYNOPSIS
    Loop diario que despierta al agente Cursor para CommerceOps.
.EXAMPLE
    .\Start-CommerceOpsAutopilot.ps1
    .\Start-CommerceOpsAutopilot.ps1 -IntervalHours 24 -RunNow
#>
[CmdletBinding()]
param(
    [int]$IntervalHours = 24,
    [switch]$RunNow
)

$ErrorActionPreference = 'Continue'
$tickScript = Join-Path $PSScriptRoot 'Invoke-CommerceOpsDailyTick.ps1'
$prompt = 'CommerceOps autopilot: lee commerceops/data/cursor-wake.json. Si wake=true, lee commerceops/data/AGENT_WORK_NOW.md y CURSOR_WAKE.md e implementa la tarea. Mejora plugins/dist/landing. Al terminar ejecuta commerceops/scripts/Invoke-CommerceOpsAgentDone.ps1 con el TaskId correspondiente.'

function Write-CommerceOpsLoopTick {
    $payload = (@{ prompt = $prompt } | ConvertTo-Json -Compress)
    Write-Host "AGENT_LOOP_TICK_COMMERCEOPS $payload"
}

Write-Host "CommerceOps Autopilot - intervalo ${IntervalHours}h" -ForegroundColor Magenta
Write-Host "Tick script: $tickScript"

if ($RunNow) {
    & $tickScript
    Write-CommerceOpsLoopTick
}

$seconds = $IntervalHours * 3600
while ($true) {
    Start-Sleep -Seconds $seconds
    & $tickScript
    Write-CommerceOpsLoopTick
}
