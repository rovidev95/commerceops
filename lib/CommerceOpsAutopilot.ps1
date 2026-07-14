#Requires -Version 5.1
Set-StrictMode -Version Latest

function Save-CommerceOpsJson {
    param(
        [Parameter(Mandatory)][string]$Path,
        [Parameter(Mandatory)]$Data
    )
    $dir = Split-Path $Path -Parent
    if ($dir -and -not (Test-Path $dir)) {
        New-Item -ItemType Directory -Path $dir -Force | Out-Null
    }
    $json = $Data | ConvertTo-Json -Depth 12
    [System.IO.File]::WriteAllText($Path, $json, [System.Text.UTF8Encoding]::new($false))
}

function Get-CommerceOpsWorkQueue {
    $root = Get-CommerceOpsRoot
    $config = Get-CommerceOpsConfig
    $path = Join-Path $root ($config.autopilot.work_queue_file -replace '/', '\')
    if (-not (Test-Path $path)) { throw "Missing work queue: $path" }
    Get-Content $path -Raw | ConvertFrom-Json
}

function Save-CommerceOpsWorkQueue {
    param($Queue)
    $root = Get-CommerceOpsRoot
    $config = Get-CommerceOpsConfig
    $path = Join-Path $root ($config.autopilot.work_queue_file -replace '/', '\')
    Save-CommerceOpsJson -Path $path -Data $Queue
}

function Get-CommerceOpsMetrics {
    $root = Get-CommerceOpsRoot
    $config = Get-CommerceOpsConfig
    $path = Join-Path $root ($config.autopilot.metrics_file -replace '/', '\')
    if (-not (Test-Path $path)) {
        return [pscustomobject]@{ ticks_total = 0; last_tick_at = $null }
    }
    Get-Content $path -Raw | ConvertFrom-Json
}

function Update-CommerceOpsMetrics {
    param([hashtable]$Delta)

    $root = Get-CommerceOpsRoot
    $config = Get-CommerceOpsConfig
    $path = Join-Path $root ($config.autopilot.metrics_file -replace '/', '\')
    $raw = @{}
    if (Test-Path $path) {
        $existing = Get-Content $path -Raw | ConvertFrom-Json
        $existing.PSObject.Properties | ForEach-Object { $raw[$_.Name] = $_.Value }
    }

    foreach ($key in $Delta.Keys) {
        $val = $Delta[$key]
        if ($raw.ContainsKey($key) -and $raw[$key] -is [int] -and $val -is [int]) {
            $raw[$key] = [int]$raw[$key] + $val
        }
        else {
            $raw[$key] = $val
        }
    }

    Save-CommerceOpsJson -Path $path -Data $raw
    return $raw
}

function Write-CommerceOpsDailyLog {
    param([hashtable]$Entry)

    $root = Get-CommerceOpsRoot
    $config = Get-CommerceOpsConfig
    $path = Join-Path $root ($config.autopilot.daily_log_file -replace '/', '\')
    $Entry['ts'] = (Get-Date).ToUniversalTime().ToString('o')
    $line = ($Entry | ConvertTo-Json -Compress)
    Add-Content -Path $path -Value $line -Encoding UTF8
}

function Compare-CommerceOpsAuditReports {
    param(
        [string]$CurrentPath,
        [string]$PreviousPath
    )

    if (-not (Test-Path $CurrentPath)) { return $null }
    $current = Get-Content $CurrentPath -Raw | ConvertFrom-Json
    if (-not (Test-Path $PreviousPath)) {
        return [pscustomobject]@{
            has_previous = $false
            top_score_delta = 0
            build_count_delta = 0
        }
    }
    $prev = Get-Content $PreviousPath -Raw | ConvertFrom-Json
    $scoreDelta = [double]$current.summary.top_score - [double]$prev.summary.top_score
    $buildDelta = [int]$current.summary.build_count - [int]$prev.summary.build_count
    return [pscustomobject]@{
        has_previous      = $true
        top_score_delta   = $scoreDelta
        build_count_delta = $buildDelta
        prev_top          = $prev.summary.top_niche
        curr_top          = $current.summary.top_niche
    }
}

function Invoke-CommerceOpsAutoTask {
    param(
        [Parameter(Mandatory)]$Item
    )

    switch ($Item.handler) {
        'Run-Audit' {
            $root = Get-CommerceOpsRoot
            $reportPath = Join-Path $root 'data\audit-report.json'
            $prevPath = Join-Path $root 'data\audit-prev.json'
            if (Test-Path $reportPath) {
                Copy-Item $reportPath $prevPath -Force
            }
            $report = Invoke-CommerceOpsAudit -Quiet
            $diff = Compare-CommerceOpsAuditReports -CurrentPath $reportPath -PreviousPath $prevPath
            return @{ ok = $true; detail = "audit score=$($report.summary.top_score)"; diff = $diff }
        }
        'Run-Build' {
            $built = Build-CommerceOpsPlugin -All
            return @{ ok = $true; detail = "built $($built.Count) zips"; files = $built }
        }
        default {
            return @{ ok = $false; detail = "unknown handler $($Item.handler)" }
        }
    }
}

function Set-CommerceOpsCursorWake {
    param(
        [Parameter(Mandatory)]$Task,
        [string]$WorkspaceRoot = ''
    )

    if (-not $WorkspaceRoot) {
        $WorkspaceRoot = Split-Path (Get-CommerceOpsRoot) -Parent
    }

    $coRoot = Get-CommerceOpsRoot
    $config = Get-CommerceOpsConfig
    $wakeJson = Join-Path $coRoot 'data\cursor-wake.json'
    $wakeMd = Join-Path $coRoot 'data\CURSOR_WAKE.md'
    $briefPath = Join-Path $coRoot 'data\AGENT_WORK_NOW.md'
    $now = (Get-Date).ToUniversalTime().ToString('o')

    $brief = @(
        '# CommerceOps - tarea del dia'
        ""
        "**ID:** $($Task.id)"
        "**Producto:** $($Task.product)"
        '**Titulo:** ' + $Task.title
        ''
        '## Objetivo'
        $Task.brief
        ''
        '## Contexto'
        '- Raiz: ``commerceops/``'
        '- Config: ``commerceops/config.json``'
        '- Auditoria: ``commerceops/data/audit-report.json``'
        '- Builds: ``commerceops/dist/*.zip``'
        ''
        '## Al terminar'
        '1. Ejecuta tests/build si aplica'
        '2. Marca tarea completada:'
        ('   ``powershell -NoProfile -ExecutionPolicy Bypass -File commerceops/scripts/Invoke-CommerceOpsAgentDone.ps1 -TaskId {0}``' -f $Task.id)
        ''
        '## Reglas'
        '- Mejora minima viable, no over-engineering'
        '- Un issue = un entregable claro'
        '- Actualizar readme/changelog del plugin tocado'
    ) -join "`n"

    [System.IO.File]::WriteAllText($briefPath, $brief, [System.Text.UTF8Encoding]::new($false))

    $md = @(
        '# CommerceOps Autopilot - implementar hoy'
        ''
        "**$($Task.title)** ($($Task.id))"
        ''
        '1. Lee ``commerceops/data/AGENT_WORK_NOW.md``'
        '2. Implementa y mejora lo necesario en el repo'
        '3. Al terminar: ``Invoke-CommerceOpsAgentDone.ps1 -TaskId ' + $Task.id + '``'
        ''
        "Producto: $($Task.product)"
    ) -join "`n"
    [System.IO.File]::WriteAllText($wakeMd, $md, [System.Text.UTF8Encoding]::new($false))

    $payload = @{
        wake         = $true
        timestamp    = $now
        task_id      = $Task.id
        title        = $Task.title
        product      = $Task.product
        brief_file   = 'data/AGENT_WORK_NOW.md'
        continue_cmd = $config.autopilot.continue_cmd
    }
    Save-CommerceOpsJson -Path $wakeJson -Data $payload

    return @{ ok = $true; brief = $briefPath; wake = $wakeMd }
}

function Clear-CommerceOpsCursorWake {
    param([string]$Reason = 'done')

    $coRoot = Get-CommerceOpsRoot
    $wakeJson = Join-Path $coRoot 'data\cursor-wake.json'
    Save-CommerceOpsJson -Path $wakeJson -Data @{
        wake       = $false
        cleared_at = (Get-Date).ToUniversalTime().ToString('o')
        reason     = $Reason
    }
}

function Complete-CommerceOpsWorkItem {
    param(
        [Parameter(Mandatory)][string]$TaskId,
        [string]$Note = ''
    )

    $queue = Get-CommerceOpsWorkQueue
    $found = $false
    foreach ($item in $queue.items) {
        if ($item.id -eq $TaskId) {
            $item.status = 'completed'
            $item | Add-Member -NotePropertyName 'completed_at' -NotePropertyValue ((Get-Date).ToUniversalTime().ToString('o')) -Force
            if ($Note) { $item | Add-Member -NotePropertyName 'note' -NotePropertyValue $Note -Force }
            $found = $true
            break
        }
    }
    if (-not $found) {
        throw "Task not found: $TaskId"
    }
    Save-CommerceOpsWorkQueue -Queue $queue
    Clear-CommerceOpsCursorWake -Reason "completed $TaskId"
    Update-CommerceOpsMetrics -Delta @{ tasks_completed = 1 }
    return $queue
}

function Get-NextCommerceOpsWorkItem {
    param(
        [ValidateSet('auto', 'agent', 'any')]
        [string]$Type = 'any'
    )

    $queue = Get-CommerceOpsWorkQueue
    $pending = @($queue.items | Where-Object { $_.status -eq 'pending' })
    if ($Type -ne 'any') {
        $pending = @($pending | Where-Object { $_.type -eq $Type })
    }
    return $pending | Sort-Object { [int]$_.priority } | Select-Object -First 1
}

function Invoke-CommerceOpsDailyTick {
    param(
        [switch]$Quiet,
        [switch]$SkipWake
    )

    $root = Get-CommerceOpsRoot
    $config = Get-CommerceOpsConfig
    $auto = $config.autopilot

    if (-not $auto.enabled) {
        if (-not $Quiet) { Write-Host 'CommerceOps autopilot disabled' -ForegroundColor Yellow }
        return @{ ok = $false; reason = 'disabled' }
    }

    if (-not $Quiet) {
        Write-Host '=== CommerceOps Daily Tick ===' -ForegroundColor Magenta
    }

    $results = @()
    $auditPath = Join-Path $root 'data\audit-report.json'
    $metrics = Get-CommerceOpsMetrics
    $lastTick = $null
    if ($metrics.last_tick_at) {
        try { $lastTick = [datetime]::Parse($metrics.last_tick_at) } catch {}
    }

    $daysSinceAudit = 999
    if ($metrics.last_tick_at -and (Test-Path $auditPath)) {
        $auditAge = (Get-Item $auditPath).LastWriteTimeUtc
        $daysSinceAudit = ((Get-Date).ToUniversalTime() - $auditAge).TotalDays
    }

    # Auto build each tick
    if ($auto.auto_build_each_tick) {
        if (-not $Quiet) { Write-Host '[auto] Building plugins...' -ForegroundColor Cyan }
        $built = Build-CommerceOpsPlugin -All
        $results += @{ step = 'build'; ok = $true; count = $built.Count }
        Update-CommerceOpsMetrics -Delta @{ builds_run = 1; plugins_built = @($built | ForEach-Object { Split-Path $_ -Leaf }) }
    }

    # Audit on interval
    if ($daysSinceAudit -ge [int]$auto.audit_interval_days) {
        if (-not $Quiet) { Write-Host '[auto] Running competitive audit...' -ForegroundColor Cyan }
        $prev = Join-Path $root 'data\audit-prev.json'
        if (Test-Path $auditPath) { Copy-Item $auditPath $prev -Force }
        $report = Invoke-CommerceOpsAudit -Quiet
        $results += @{ step = 'audit'; ok = $true; top_score = $report.summary.top_score }
        Update-CommerceOpsMetrics -Delta @{ audits_run = 1; top_niche_score = $report.summary.top_score }
    }

    # Process pending auto tasks (max 3 per tick)
    $autoDone = 0
    while ($autoDone -lt 3) {
        $autoTask = Get-NextCommerceOpsWorkItem -Type 'auto'
        if (-not $autoTask) { break }
        if (-not $Quiet) { Write-Host "[auto] $($autoTask.id): $($autoTask.title)" -ForegroundColor Green }
        $q = Get-CommerceOpsWorkQueue
        foreach ($it in $q.items) {
            if ($it.id -eq $autoTask.id) { $it.status = 'in_progress'; break }
        }
        Save-CommerceOpsWorkQueue -Queue $q

        $exec = Invoke-CommerceOpsAutoTask -Item $autoTask
        Complete-CommerceOpsWorkItem -TaskId $autoTask.id -Note ($exec.detail)
        $results += @{ step = 'auto_task'; id = $autoTask.id; ok = $exec.ok; detail = $exec.detail }
        $autoDone++
    }

    # Agent task for today
    $agentTask = Get-NextCommerceOpsWorkItem -Type 'agent'
    $wakeResult = $null
    if ($agentTask -and -not $SkipWake -and $auto.wake_cursor_agent) {
        $q = Get-CommerceOpsWorkQueue
        foreach ($it in $q.items) {
            if ($it.id -eq $agentTask.id) {
                $it.status = 'in_progress'
                $it | Add-Member -NotePropertyName 'started_at' -NotePropertyValue ((Get-Date).ToUniversalTime().ToString('o')) -Force
                break
            }
        }
        Save-CommerceOpsWorkQueue -Queue $q

        if (-not $Quiet) {
            Write-Host ("[agent] Wake: {0} - {1}" -f $agentTask.id, $agentTask.title) -ForegroundColor Magenta
        }
        $wakeResult = Set-CommerceOpsCursorWake -Task $agentTask
        Update-CommerceOpsMetrics -Delta @{ tasks_agent_pending = 1 }
    }

    Update-CommerceOpsMetrics -Delta @{
        ticks_total  = 1
        last_tick_at = (Get-Date).ToUniversalTime().ToString('o')
    }

    Write-CommerceOpsDailyLog -Entry @{
        event      = 'daily_tick'
        auto_steps = $results.Count
        agent_task = if ($agentTask) { $agentTask.id } else { $null }
        wake       = [bool]$wakeResult
    }

    if (-not $Quiet) {
        Write-Host ''
        Write-Host 'Tick complete.' -ForegroundColor Green
        if ($wakeResult) {
            Write-Host 'Cursor wake: commerceops/data/CURSOR_WAKE.md' -ForegroundColor Magenta
        }
    }

    return @{
        ok         = $true
        results    = $results
        agent_task = $agentTask
        wake       = $wakeResult
    }
}

Export-ModuleMember -Function @(
    'Invoke-CommerceOpsDailyTick',
    'Complete-CommerceOpsWorkItem',
    'Clear-CommerceOpsCursorWake',
    'Set-CommerceOpsCursorWake',
    'Get-CommerceOpsWorkQueue',
    'Get-CommerceOpsMetrics',
    'Update-CommerceOpsMetrics'
)
