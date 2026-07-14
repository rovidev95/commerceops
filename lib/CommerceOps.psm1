#Requires -Version 5.1
Set-StrictMode -Version Latest

$script:CoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
if (-not (Test-Path (Join-Path (Split-Path $PSScriptRoot -Parent) "config.json"))) {
    $script:CoRoot = Split-Path $PSScriptRoot -Parent
}

function Get-CommerceOpsRoot {
    Split-Path $PSScriptRoot -Parent
}

function Get-CommerceOpsConfig {
    $path = Join-Path (Get-CommerceOpsRoot) "config.json"
    if (-not (Test-Path $path)) { throw "Missing config.json at $path" }
    Get-Content $path -Raw | ConvertFrom-Json
}

function Invoke-WordPressPluginQuery {
    param(
        [Parameter(Mandatory)][string]$Search,
        [int]$PerPage = 12,
        [string[]]$Tag = @()
    )

    $query = @{
        action  = 'query_plugins'
        request = @{
            per_page = $PerPage
            search   = $Search
        }
    }
    if ($Tag.Count -gt 0) {
        $query.request.tag = ($Tag -join ',')
    }

    $uri = 'https://api.wordpress.org/plugins/info/1.2/?' + (ConvertTo-WpApiQueryString -Object $query)

    try {
        $resp = Invoke-RestMethod -Uri $uri -Method Get -TimeoutSec 45
        if ($null -eq $resp.plugins) { return @() }
        return @($resp.plugins)
    }
    catch {
        Write-Warning "WP API query failed for '$Search': $($_.Exception.Message)"
        return @()
    }
}

function ConvertTo-WpApiQueryString {
    param([hashtable]$Object, [string]$Prefix = '')

    $parts = @()
    foreach ($key in $Object.Keys) {
        $value = $Object[$key]
        $fullKey = if ($Prefix) { "$Prefix[$key]" } else { $key }

        if ($value -is [hashtable]) {
            $parts += ConvertTo-WpApiQueryString -Object $value -Prefix $fullKey
        }
        elseif ($value -is [array]) {
            for ($i = 0; $i -lt $value.Count; $i++) {
                $parts += "{0}[{1}]={2}" -f $fullKey, $i, [uri]::EscapeDataString([string]$value[$i])
            }
        }
        else {
            $parts += "{0}={1}" -f $fullKey, [uri]::EscapeDataString([string]$value)
        }
    }
    return ($parts -join '&')
}

function Get-WordPressPluginDetail {
    param([Parameter(Mandatory)][string]$Slug)

    $query = @{
        action  = 'plugin_information'
        request = @{
            slug = $Slug
        }
    }

    $uri = 'https://api.wordpress.org/plugins/info/1.2/?' + (ConvertTo-WpApiQueryString -Object $query)

    try {
        return Invoke-RestMethod -Uri $uri -Method Get -TimeoutSec 45
    }
    catch {
        Write-Warning "WP API detail failed for '$Slug'"
        return $null
    }
}

function Convert-InstallBandToNumber {
    param($Band)
    if ($null -eq $Band) { return 0 }
    if ($Band -is [int] -or $Band -is [long] -or $Band -is [double]) {
        return [int]$Band
    }
    $text = [string]$Band
    if ($text -match '^(\d+)\+$') { return [int]$Matches[1] }
    if ($text -match '^\d+$') { return [int]$text }
    return 0
}

function Get-PluginOpportunityScore {
    param(
        [object]$Plugin,
        [int]$StaleDays = 365
    )

    $installs = Convert-InstallBandToNumber $Plugin.active_installs
    $ratingRaw = [double]$Plugin.rating
    $rating = if ($ratingRaw -gt 5) { [math]::Round($ratingRaw / 20.0, 2) } else { $ratingRaw }
    if ($rating -eq 0 -and $Plugin.ratings) {
        $total = ($Plugin.ratings.PSObject.Properties | ForEach-Object { [int]$_.Value } | Measure-Object -Sum).Sum
        if ($total -gt 0) {
            $weighted = 0
            foreach ($p in $Plugin.ratings.PSObject.Properties) {
                $stars = [int]$p.Name
                $weighted += $stars * [int]$p.Value
            }
            $rating = [math]::Round($weighted / $total, 2)
        }
    }

    $numRatings = [int]$Plugin.num_ratings
    $lastUpdated = [datetime]::MinValue
    if ($Plugin.last_updated) {
        try { $lastUpdated = [datetime]::Parse($Plugin.last_updated) } catch {}
    }
    $daysSinceUpdate = if ($lastUpdated -ne [datetime]::MinValue) {
        ([datetime]::UtcNow - $lastUpdated).TotalDays
    } else { 9999 }

    $demandScore = [math]::Min(40, [math]::Log10([math]::Max($installs, 1)) * 10)
    $weaknessScore = 0
    if ($rating -gt 0 -and $rating -lt 4.0) { $weaknessScore += 15 }
    if ($numRatings -lt 50) { $weaknessScore += 5 }
    if ($daysSinceUpdate -gt $StaleDays) { $weaknessScore += 20 }
    if ($daysSinceUpdate -gt ($StaleDays * 2)) { $weaknessScore += 10 }

    $trustPenalty = 0
    if ($rating -ge 4.5 -and $installs -gt 50000) { $trustPenalty = 25 }

    $total = [math]::Round([math]::Max(0, [math]::Min(100, $demandScore + $weaknessScore - $trustPenalty)), 1)

    [pscustomobject]@{
        slug            = $Plugin.slug
        name            = $Plugin.name
        active_installs = $Plugin.active_installs
        installs_num    = $installs
        rating          = $rating
        num_ratings     = $numRatings
        last_updated    = $Plugin.last_updated
        days_stale      = [math]::Round($daysSinceUpdate, 0)
        short_description = $Plugin.short_description
        opportunity_score = $total
        signals         = @(
            $(if ($daysSinceUpdate -gt $StaleDays) { 'stale' }),
            $(if ($rating -gt 0 -and $rating -lt 4.0) { 'low_rating' }),
            $(if ($installs -gt 10000) { 'high_demand' }),
            $(if ($trustPenalty -gt 0) { 'strong_incumbent' })
        ) | Where-Object { $_ }
    }
}

function Invoke-CommerceOpsNicheAudit {
    param(
        [object]$Niche,
        [int]$PerPage = 12,
        [int]$StaleDays = 365
    )

    $seen = @{}
    $allPlugins = @()

    foreach ($term in $Niche.search_terms) {
        Start-Sleep -Milliseconds 350
        $results = Invoke-WordPressPluginQuery -Search $term -PerPage $PerPage -Tag $Niche.tags
        foreach ($p in $results) {
            if (-not $seen.ContainsKey($p.slug)) {
                $seen[$p.slug] = $true
                $allPlugins += $p
            }
        }
    }

    $scored = foreach ($p in $allPlugins) {
        Get-PluginOpportunityScore -Plugin $p -StaleDays $StaleDays
    }

    $ranked = @($scored | Sort-Object opportunity_score -Descending)
    $topWeak = @($ranked | Where-Object { 'strong_incumbent' -notin $_.signals } | Select-Object -First 5)
    $incumbents = @($ranked | Sort-Object installs_num -Descending | Select-Object -First 3)

    $avgInstalls = if ($ranked.Count -gt 0) {
        [math]::Round(($ranked | ForEach-Object { $_.installs_num } | Measure-Object -Average).Average, 0)
    } else { 0 }

    $nicheScore = [math]::Round([math]::Min(100,
        ($topWeak | Select-Object -First 3 | ForEach-Object { $_.opportunity_score } | Measure-Object -Average).Average * 0.6 +
        ([math]::Min(30, [math]::Log10([math]::Max($avgInstalls, 1)) * 8)) +
        ($(if ($Niche.our_product) { 15 } else { 0 }))
    ), 1)

    [pscustomobject]@{
        niche_id       = $Niche.id
        niche_name     = $Niche.name
        our_product    = $Niche.our_product
        target_price   = $Niche.target_price_usd
        priority       = $Niche.priority
        plugins_found  = $ranked.Count
        avg_installs   = $avgInstalls
        niche_score    = $nicheScore
        recommendation = $(if ($nicheScore -ge 55) { 'BUILD' } elseif ($nicheScore -ge 40) { 'WATCH' } else { 'SKIP' })
        top_opportunities = @($topWeak)
        top_incumbents    = @($incumbents)
        audited_at     = (Get-Date).ToUniversalTime().ToString('o')
    }
}

function Invoke-CommerceOpsAudit {
    param(
        [switch]$Quiet,
        [string]$OutputPath
    )

    $root = Get-CommerceOpsRoot
    $config = Get-CommerceOpsConfig
    $nichesPath = Join-Path $root "data\niches.json"
    $nichesData = Get-Content $nichesPath -Raw | ConvertFrom-Json

    if (-not $OutputPath) {
        $OutputPath = Join-Path $root "data\audit-report.json"
    }

    $results = @()
    $i = 0
    foreach ($niche in $nichesData.niches) {
        $i++
        if (-not $Quiet) {
            Write-Host "[$i/$($nichesData.niches.Count)] Auditing: $($niche.name)..." -ForegroundColor Cyan
        }
        $results += Invoke-CommerceOpsNicheAudit -Niche $niche -PerPage $config.audit.plugins_per_niche -StaleDays $config.audit.stale_days_threshold
    }

    $buildFirst = $results |
        Where-Object { $_.recommendation -eq 'BUILD' -and $_.our_product } |
        Sort-Object niche_score -Descending

    $report = [ordered]@{
        generated_at   = (Get-Date).ToUniversalTime().ToString('o')
        brand          = $config.brand.name
        niches_audited = $results.Count
        summary        = [ordered]@{
            build_count  = @($results | Where-Object recommendation -eq 'BUILD').Count
            watch_count  = @($results | Where-Object recommendation -eq 'WATCH').Count
            skip_count   = @($results | Where-Object recommendation -eq 'SKIP').Count
            top_niche    = ($results | Sort-Object niche_score -Descending | Select-Object -First 1).niche_name
            top_score    = ($results | Sort-Object niche_score -Descending | Select-Object -First 1).niche_score
        }
        build_priority = @($buildFirst | ForEach-Object {
            [ordered]@{
                product     = $_.our_product
                niche       = $_.niche_name
                score       = $_.niche_score
                price_usd   = $_.target_price
                top_targets = @($_.top_opportunities | Select-Object -First 3 | ForEach-Object {
                    @{ slug = $_.slug; score = $_.opportunity_score; installs = $_.active_installs; rating = $_.rating }
                })
            }
        })
        niches = @($results)
    }

    $json = $report | ConvertTo-Json -Depth 8
    Set-Content -Path $OutputPath -Value $json -Encoding UTF8

    if (-not $Quiet) {
        Write-Host ""
        Write-Host "=== CommerceOps Audit Complete ===" -ForegroundColor Green
        Write-Host "Report: $OutputPath"
        Write-Host "Top niche: $($report.summary.top_niche) ($($report.summary.top_score))"
        Write-Host ""
        Write-Host "Build priority:" -ForegroundColor Yellow
        foreach ($b in $report.build_priority) {
            Write-Host ("  [{0,5}] {1} -> {2}" -f $b.score, $b.product, $b.niche)
        }
    }

    return $report
}

function Build-CommerceOpsPlugin {
    param(
        [string]$PluginSlug,
        [switch]$All
    )

    $root = Get-CommerceOpsRoot
    $dist = Join-Path $root "dist"
    if (-not (Test-Path $dist)) { New-Item -ItemType Directory -Path $dist | Out-Null }

    $slugs = if ($All) {
        Get-ChildItem (Join-Path $root "plugins") -Directory | ForEach-Object { $_.Name }
    } else { @($PluginSlug) }

    $built = @()
    foreach ($slug in $slugs) {
        $src = Join-Path $root "plugins\$slug"
        if (-not (Test-Path $src)) { Write-Warning "Plugin not found: $slug"; continue }

        $zipPath = Join-Path $dist "$slug.zip"
        if (Test-Path $zipPath) { Remove-Item $zipPath -Force }

        $temp = Join-Path $env:TEMP "co-build-$slug-$(Get-Random)"
        $stage = Join-Path $temp $slug
        New-Item -ItemType Directory -Path $stage -Force | Out-Null

        Copy-Item -Path "$src\*" -Destination $stage -Recurse -Force
        $coreSrc = Join-Path $root "packages\commerceops-core"
        $coreDest = Join-Path $stage "vendor\commerceops-core"
        New-Item -ItemType Directory -Path $coreDest -Force | Out-Null
        Copy-Item -Path "$coreSrc\*" -Destination $coreDest -Recurse -Force

        Compress-Archive -Path $stage -DestinationPath $zipPath -Force
        Remove-Item $temp -Recurse -Force -ErrorAction SilentlyContinue
        $built += $zipPath
        Write-Host "Built: $zipPath" -ForegroundColor Green
    }

    return $built
}

. (Join-Path $PSScriptRoot 'CommerceOpsAutopilot.ps1')

Export-ModuleMember -Function @(
    'Get-CommerceOpsRoot',
    'Get-CommerceOpsConfig',
    'Invoke-CommerceOpsAudit',
    'Invoke-CommerceOpsNicheAudit',
    'Build-CommerceOpsPlugin',
    'Get-WordPressPluginDetail',
    'Invoke-CommerceOpsDailyTick',
    'Complete-CommerceOpsWorkItem',
    'Clear-CommerceOpsCursorWake',
    'Set-CommerceOpsCursorWake',
    'Get-CommerceOpsWorkQueue',
    'Get-CommerceOpsMetrics',
    'Update-CommerceOpsMetrics'
)
