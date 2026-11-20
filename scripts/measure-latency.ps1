# scripts/measure-latency.ps1 -- measure upload -> result latency (p50/p95).
#
# Usage:
#   scripts\measure-latency.ps1 -Iterations 10            # fake Gemini, offline
#   scripts\measure-latency.ps1 -Iterations 10 -Live      # real Gemini (needs env key)
#   scripts\measure-latency.ps1 -Iterations 10 -Repeat    # same bytes (cache hit)
#
# Never prints the API key. Uses a temporary SQLite DB and, unless -Live is
# set, a local fake Gemini server. No production request is made.

param(
    [int]$Iterations = 10,
    [switch]$Live,
    [switch]$Repeat
)

[Console]::OutputEncoding = [System.Text.UTF8Encoding]::new($false)

# Composer appends `--iterations=N` after the script command; PowerShell does
# not bind that form to the -Iterations parameter, so parse it from $args.
for ($argIndex = 0; $argIndex -lt $args.Count; $argIndex++) {
    $arg = [string]$args[$argIndex]
    if ($arg -match '^--?iterations[= ](\d+)$') {
        $Iterations = [int]$Matches[1]
    } elseif ($arg -match '^--?iterations$' -and ($argIndex + 1) -lt $args.Count) {
        $Iterations = [int]$args[$argIndex + 1]
        $argIndex++
    }
}

$root = (Get-Item $PSScriptRoot).Parent.FullName
Set-Location $root

# Remember where this run starts in the file logs so diagnostics only show
# lines produced by this run.
$logStarts = @{}
foreach ($logPath in @((Join-Path $root 'storage\logs\laravel.log'), (Join-Path $root 'storage\logs\laravel-test.log'))) {
    if (Test-Path $logPath) {
        $logStarts[$logPath] = @(Get-Content $logPath -ErrorAction SilentlyContinue).Count
    } else {
        $logStarts[$logPath] = 0
    }
}

& (Join-Path $PSScriptRoot 'Lint.ps1')
if ($LASTEXITCODE -ne 0) { Write-Host "Script lint FAILED."; exit 1 }

if ($Iterations -lt 1) { $Iterations = 1 }

Write-Host "=== TraceGeo latency measurement ==="
if ($Live) {
    Write-Host "LIVE (real Gemini)"
} else {
    Write-Host "FAKE"
    Write-Host "NOTE: fake-Gemini numbers are dominated by local HTTP/poll overhead."
}
Write-Host ("iterations={0} repeat={1}" -f $Iterations, $Repeat)
# The artisan server reads the key from .env; this script never reads or
# prints it. In fake mode a harmless placeholder is used for Http fakes.

$failures = 0
$tmpDb = Join-Path ([System.IO.Path]::GetTempPath()) ("tg_lat_" + [guid]::NewGuid().ToString("N") + ".sqlite")
$jar = Join-Path ([System.IO.Path]::GetTempPath()) ("tg_lat_" + [guid]::NewGuid().ToString("N") + ".txt")
$fixtures = @{}
foreach ($variant in @('small', 'medium', 'large')) {
    $fixtures[$variant] = Join-Path ([System.IO.Path]::GetTempPath()) ("tg_lat_" + [guid]::NewGuid().ToString("N") + "_{0}.jpg" -f $variant)
}
$fixture = $fixtures['small']
$serverLog = Join-Path ([System.IO.Path]::GetTempPath()) ("tg_lat_" + [guid]::NewGuid().ToString("N") + ".server.log")
$serverErr = Join-Path ([System.IO.Path]::GetTempPath()) ("tg_lat_" + [guid]::NewGuid().ToString("N") + ".server.err")
$workerLog = Join-Path ([System.IO.Path]::GetTempPath()) ("tg_lat_" + [guid]::NewGuid().ToString("N") + ".worker.log")
$envBackup = @{}
$fake = $null
$server = $null
$port = 0

function Save-Env([string[]]$keys) {
    foreach ($k in $keys) { $envBackup[$k] = (Get-Item "env:$k" -ErrorAction SilentlyContinue).Value }
}
function Restore-Env {
    foreach ($k in $envBackup.Keys) {
        $old = $envBackup[$k]
        if ($null -eq $old) { Remove-Item "env:$k" -ErrorAction SilentlyContinue }
        else { Set-Item "env:$k" $old }
    }
}
function Stop-ProcessTree($procId) {
    if (-not $procId) { return }
    & taskkill /F /T /PID $procId | Out-Null
    if ($LASTEXITCODE -ne 0) { Stop-Process -Id $procId -Force -ErrorAction SilentlyContinue }
}
function Get-StatusCode([string]$raw) {
    $first = ($raw -split "`r?`n", 2)[0]
    if ($first -match '^HTTP/\d(\.\d)?\s+(\d{3})') { return [int]$Matches[2] }
    return 0
}
function Split-HttpResponse([string]$raw) {
    $m = [regex]::Match($raw, "`r?`n`r?`n")
    if ($m.Success) { return @($raw.Substring(0, $m.Index), $raw.Substring($m.Index + $m.Length)) }
    return @($raw, "")
}
function Get-Percentile([double[]]$values, [double]$p) {
    $sorted = @($values | Sort-Object)
    $idx = [int][Math]::Ceiling($p * ($sorted.Count - 1))
    if ($idx -lt 0) { $idx = 0 }
    if ($idx -ge $sorted.Count) { $idx = $sorted.Count - 1 }
    return $sorted[$idx]
}

function Redact-Text([string]$text) {
    $value = [string]$text
    $value = $value -replace '\bkey=[^&\s"''<>]+', 'key=[REDACTED]'
    $value = $value -replace 'AIza[0-9A-Za-z_\-]{10,}', '[REDACTED]'
    $value = $value -replace 'AQ[0-9A-Za-z_\-\.]{6,}', '[REDACTED]'
    $value = $value -replace 'sk-[A-Za-z0-9]{16,}', '[REDACTED]'
    $value = $value -replace '-----BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY-----', '[REDACTED PRIVATE KEY]'
    return $value
}

function Show-FailureDiagnostics($status, [int]$analysisId) {
    Write-Host "--- failure diagnostics (before cleanup) ---" -ForegroundColor Yellow
    $stage = if ($status.stage) { $status.stage } else { '?' }
    $label = if ($status.stage_label) { $status.stage_label } else { '(none)' }
    $error = if ($status.error) { $status.error } else { '(none)' }
    Write-Host ("analysis_id={0} stage={1} stage_label={2}" -f $analysisId, $stage, $label)
    Write-Host ("user_facing_error=" + (Redact-Text $error))

    $fileLogs = @(
        (Join-Path $root 'storage\logs\laravel.log'),
        (Join-Path $root 'storage\logs\laravel-test.log')
    )
    $lines = @()
    foreach ($log in @($serverLog, $serverErr, $workerLog) + $fileLogs) {
        if (Test-Path $log) {
            $skip = 0
            if ($logStarts.ContainsKey($log)) { $skip = [int]$logStarts[$log] }
            $lines += @(Get-Content $log -ErrorAction SilentlyContinue |
                Select-Object -Skip $skip |
                Where-Object { $_ -match 'Gemini' } | Select-Object -Last 20)
        }
    }
    if ($lines.Count -eq 0) {
        Write-Host "last 20 Gemini log lines: (none captured)"
    } else {
        Write-Host "last 20 redacted Gemini log lines:"
        foreach ($line in ($lines | Select-Object -Last 20)) { Write-Host ("  " + (Redact-Text $line)) }
    }
    Write-Host "--- end failure diagnostics ---" -ForegroundColor Yellow
}

Save-Env @('DB_DATABASE', 'DB_CONNECTION', 'QUEUE_CONNECTION', 'CACHE_STORE', 'SESSION_DRIVER',
           'LOG_CHANNEL', 'APP_ENV', 'APP_KEY', 'GEMINI_API_KEY', 'GEMINI_BASE_URL',
           'GEMINI_HEALTH_PROBE', 'GEMINI_CONFIDENCE_REVERSE_GEOCODE',
           'CLOUDINARY_CLOUD_NAME', 'CLOUDINARY_API_KEY', 'CLOUDINARY_API_SECRET', 'CLOUDINARY_URL')

try {
    New-Item -ItemType File $tmpDb | Out-Null
    # Hermetic: never depend on the developer's .env values.
    $testKey = [regex]::Match((Get-Content (Join-Path $root 'phpunit.xml') -Raw), '<env name="APP_KEY" value="([^"]+)"').Groups[1].Value
    $env:APP_KEY = $testKey
    $env:APP_ENV = 'local'
    $env:DB_CONNECTION = 'sqlite'
    $env:DB_DATABASE = $tmpDb
    $env:QUEUE_CONNECTION = 'database'
    $env:CACHE_STORE = 'array'
    $env:SESSION_DRIVER = 'database'
    $env:LOG_CHANNEL = 'single'
    $env:GEMINI_HEALTH_PROBE = 'false'
    $env:GEMINI_CONFIDENCE_REVERSE_GEOCODE = 'false'
    # Windows treats an empty env var as unset, so use a falsy sentinel to
    # force the local-disk upload path without reading any real .env.
    $env:CLOUDINARY_CLOUD_NAME = '0'
    $env:CLOUDINARY_API_KEY = '0'
    $env:CLOUDINARY_API_SECRET = '0'
    $env:CLOUDINARY_URL = '0'
    if (-not $Live) {
        $env:GEMINI_API_KEY = 'latency-fake-key'
        $listener = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Loopback, 0)
        $listener.Start(); $fakePort = $listener.LocalEndpoint.Port; $listener.Stop()
        $router = (Join-Path $PSScriptRoot 'fake-gemini-router.php')
        $fake = Start-Process -PassThru -NoNewWindow -FilePath 'php' `
            -ArgumentList '-S', "127.0.0.1:$fakePort", ('"' + $router + '"')
        $env:GEMINI_BASE_URL = "http://127.0.0.1:$fakePort/v1beta"
    }

    & php artisan migrate --force 2>&1 | Out-Null
    if ($LASTEXITCODE -ne 0) { Write-Host "FAIL: migrations failed"; $failures++ }

    $listener2 = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Loopback, 0)
    $listener2.Start(); $port = $listener2.LocalEndpoint.Port; $listener2.Stop()
    $server = Start-Process -PassThru -NoNewWindow -FilePath 'php' `
        -ArgumentList 'artisan', 'serve', "--host=127.0.0.1", "--port=$port" `
        -RedirectStandardOutput $serverLog -RedirectStandardError $serverErr
    $base = "http://127.0.0.1:$port"
    $modelInfo = (& php artisan tinker --execute="echo config('services.gemini.model').'|'.config('services.gemini.thinking_level');" 2>$null | Out-String).Trim()
    $modelParts = $modelInfo -split '\|'
    $activeModel = if ($modelParts.Count -gt 0) { $modelParts[0].Trim() } else { 'unknown' }
    $activeThinking = if ($modelParts.Count -gt 1) { $modelParts[1].Trim() } else { 'unknown' }
    $email = "latency+" + [guid]::NewGuid().ToString("N").Substring(0, 8) + "@example.com"

    $up = $false
    for ($i = 0; $i -lt 30; $i++) {
        $code = & curl.exe -s -o NUL -w "%{http_code}" "$base/api/health"
        if ($code -eq '200') { $up = $true; break }
        Start-Sleep -Milliseconds 500
    }
    if (-not $up) { Write-Host "FAIL: server did not start"; $failures++ }

    if ($failures -eq 0) {
        $reg = (& curl.exe -s -i -c $jar -b $jar "$base/register") -join "`n"
        $regBody = (Split-HttpResponse $reg)[1]
        $m = [regex]::Match($regBody, '<meta name="csrf-token" content="([^"]+)"')
        if ($m.Success) {
            & curl.exe -s -i -c $jar -b $jar -X POST `
                --data-urlencode "name=Latency User" `
                --data-urlencode "email=$email" `
                --data-urlencode "password=password" `
                --data-urlencode "password_confirmation=password" `
                --data-urlencode "_token=$($m.Groups[1].Value)" `
                "$base/register" | Out-Null
            & curl.exe -s -c $jar -b $jar "$base/login" | Out-Null
        } else {
            Write-Host "FAIL: no CSRF token on /register"; $failures++
        }
    }

    $page = (& curl.exe -s -c $jar -b $jar "$base/analysis") -join "`n"
    $pm = [regex]::Match($page, '<meta name="csrf-token" content="([^"]+)"')
    if (-not $pm.Success) { Write-Host "FAIL: no CSRF token on /analysis"; $failures++ }

    $latencies = @()
    $serverSeconds = @()
    if ($failures -eq 0) {
        # Three real CC0 photographic-noise JPEGs (approx 110/260/378 KB).
        foreach ($variant in @('small', 'medium', 'large')) {
            $seed = if ($Repeat) { 'repeat' } else { "seed-$variant" }
            & php (Join-Path $PSScriptRoot 'make-latency-fixture.php') $fixtures[$variant] $variant $seed | Out-Null
        }

        for ($i = 1; $i -le $Iterations; $i++) {
            $variant = @('small', 'medium', 'large')[($i - 1) % 3]
            $fixture = $fixtures[$variant]

            $sw = [System.Diagnostics.Stopwatch]::StartNew()
            $upload = (& curl.exe -s -c $jar -b $jar -X POST `
                -F "image=@$fixture;type=image/jpeg" `
                --form "_token=$($pm.Groups[1].Value)" `
                "$base/api/analyze/store") -join "`n"
            $uj = ($upload | ConvertFrom-Json)
            $id = $uj.id
            if (-not $id) {
                Write-Host "FAIL: iteration $i upload returned no id: $upload"
                $failures++
                break
            }

            # QUEUE_CONNECTION=database: process this one job exactly as a
            # real worker would. A cache hit has no job to process.
            if (-not $uj.cached) {
                & php artisan queue:work --once --tries=1 --timeout=180 2>&1 | Tee-Object -FilePath $workerLog -Append | Out-Null
            }

            $done = $false
            $deadline = (Get-Date).AddSeconds(90)
            while ((Get-Date) -lt $deadline) {
                $poll = (& curl.exe -s -c $jar -b $jar "$base/api/analyze/$id/status") | ConvertFrom-Json
                if ($poll.status -eq 'completed') { $done = $true; break }
                if ($poll.status -eq 'failed') { break }
                Start-Sleep -Milliseconds 500
            }
            $sw.Stop()
            $ms = [Math]::Round($sw.Elapsed.TotalMilliseconds, 1)

            if (-not $done) {
                Write-Host "FAIL: iteration $i did not complete (status=$($poll.status))"
                Show-FailureDiagnostics $poll $id
                $failures++
                break
            }
            $serverSecs = 'n/a'
            $code = '$a = \App\Models\GeoAnalysis::find(' + $id + '); echo $a && $a->finished_at && $a->started_at ? round($a->finished_at->diffInSeconds($a->started_at), 2) : 0;'
            $serverRaw = (& php artisan tinker --execute=$code 2>$null | Out-String)
            if ($serverRaw -match '(\d+(?:\.\d+)?)') { $serverSecs = $Matches[1] }

            $latencies += $ms
            $serverSeconds += $serverSecs
            Write-Host ("  iteration {0}: e2e={1} ms server={2} s model={3} thinking={4} fixture={5} id={6} cached={7}" -f $i, $ms, $serverSecs, $activeModel, $activeThinking, $variant, $id, $uj.cached)
        }
    }

    if ($latencies.Count -gt 0) {
        $p50 = Get-Percentile $latencies 0.50
        $p95 = Get-Percentile $latencies 0.95
        $avg = [Math]::Round((($latencies | Measure-Object -Average).Average), 1)
        Write-Host ""
        Write-Host ("RESULT n={0} e2e p50={1} ms p95={2} ms avg={3} ms" -f $latencies.Count, $p50, $p95, $avg)
        $numericServer = @($serverSeconds | Where-Object { $_ -match '^\d+(\.\d+)?$' } | ForEach-Object { [double]$_ })
        if ($numericServer.Count -gt 0) {
            $sAvg = [Math]::Round((($numericServer | Measure-Object -Average).Average), 2)
            Write-Host ("SERVER n={0} processing avg={1} s min={2} s max={3} s" -f $numericServer.Count, $sAvg, ($numericServer | Measure-Object -Minimum).Minimum, ($numericServer | Measure-Object -Maximum).Maximum)
        }
        Write-Host ("TARGET p50<=12000 ms p95<=40000 ms -> " + $(if ($p50 -le 12000 -and $p95 -le 40000) { 'MET' } else { 'NOT MET' }))
    }
}
finally {
    if ($fake) { Stop-ProcessTree $fake.Id }
    if ($server) {
        Stop-ProcessTree $server.Id
        try {
            $conns = @(netstat -ano | Select-String -Pattern ":$([int]$port)\s")
            foreach ($c in $conns) {
                $listenPid = [int]((($c.ToString() -split '\s+') | Where-Object { $_ })[-1])
                if ($listenPid -gt 0) { Stop-Process -Id $listenPid -Force -ErrorAction SilentlyContinue }
            }
        } catch { }
    }
    Remove-Item $tmpDb -Force -ErrorAction SilentlyContinue
    Remove-Item $jar -Force -ErrorAction SilentlyContinue
    foreach ($variant in @('small', 'medium', 'large')) {
        if ($fixtures.ContainsKey($variant)) { Remove-Item $fixtures[$variant] -Force -ErrorAction SilentlyContinue }
    }
    Remove-Item $serverLog -Force -ErrorAction SilentlyContinue
    Remove-Item $serverErr -Force -ErrorAction SilentlyContinue
    Remove-Item $workerLog -Force -ErrorAction SilentlyContinue
    Restore-Env
}

if ($failures -gt 0) { Write-Host "LATENCY MEASUREMENT FAILED ($failures)"; exit 1 }
Write-Host "LATENCY MEASUREMENT DONE"
exit 0
