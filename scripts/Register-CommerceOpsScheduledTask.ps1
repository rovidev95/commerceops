#Requires -Version 5.1
<#
.SYNOPSIS
    Registra tarea programada Windows para tick diario CommerceOps.
.EXAMPLE
    .\Register-CommerceOpsScheduledTask.ps1
    .\Register-CommerceOpsScheduledTask.ps1 -Hour 9 -Unregister
#>
[CmdletBinding()]
param(
    [int]$Hour = 9,
    [switch]$Unregister
)

$ErrorActionPreference = 'Stop'
$taskName = 'CommerceOps-DailyTick'
$tickScript = Join-Path $PSScriptRoot 'Invoke-CommerceOpsDailyTick.ps1'

if ($Unregister) {
    Unregister-ScheduledTask -TaskName $taskName -Confirm:$false -ErrorAction SilentlyContinue
    Write-Host "Removed scheduled task: $taskName" -ForegroundColor Yellow
    exit 0
}

$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$tickScript`""
$trigger = New-ScheduledTaskTrigger -Daily -At ([datetime]::Today.AddHours($Hour))
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable
$principal = New-ScheduledTaskPrincipal -UserId $env:USERNAME -LogonType Interactive -RunLevel Limited

Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Force | Out-Null
Write-Host "Registered: $taskName daily at ${Hour}:00" -ForegroundColor Green
Write-Host "Script: $tickScript"
