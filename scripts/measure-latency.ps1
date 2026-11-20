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

$root = (Get-Item $PSScriptRoot).Parent.FullName
Set-Location $root

& (Join-Path $PSScriptRoot 'Lint.ps1')
if ($LASTEXITCODE -ne 0) { Write-Host "Script lint FAILED."; exit 1 }

if ($Iterations -lt 1) { $Iterations = 1 }

if ($Live -and [string]::IsNullOrWhiteSpace($env:GEMINI_API_KEY)) {
    Write-Host "-Live needs `$env:GEMINI_API_KEY in the environment."
    exit 2
}

Write-Host "=== TraceGeo latency measurement ==="
Write-Host ("mode={0} iterations={1} repeat={2}" -f $(if ($Live) { 'LIVE' } else { 'FAKE' }), $Iterations, $Repeat)
if (-not $Live) { Write-Host "No key is read or printed. Local fake Gemini only." }

$failures = 0
$tmpDb = Join-Path ([System.IO.Path]::GetTempPath()) ("tg_lat_" + [guid]::NewGuid().ToString("N") + ".sqlite")
$jar = Join-Path ([System.IO.Path]::GetTempPath()) ("tg_lat_" + [guid]::NewGuid().ToString("N") + ".txt")
$fixture = Join-Path ([System.IO.Path]::GetTempPath()) ("tg_lat_" + [guid]::NewGuid().ToString("N") + ".png")
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

Save-Env @('DB_DATABASE', 'QUEUE_CONNECTION', 'GEMINI_API_KEY', 'GEMINI_BASE_URL',
           'CLOUDINARY_CLOUD_NAME', 'CLOUDINARY_API_KEY', 'CLOUDINARY_API_SECRET', 'CLOUDINARY_URL')

try {
    New-Item -ItemType File $tmpDb | Out-Null
    $env:DB_DATABASE = $tmpDb
    $env:QUEUE_CONNECTION = 'database'
    $env:CLOUDINARY_CLOUD_NAME = ''
    $env:CLOUDINARY_API_KEY = ''
    $env:CLOUDINARY_API_SECRET = ''
    $env:CLOUDINARY_URL = ''
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
        -ArgumentList 'artisan', 'serve', "--host=127.0.0.1", "--port=$port"
    $base = "http://127.0.0.1:$port"
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
    if ($failures -eq 0) {
        for ($i = 1; $i -le $Iterations; $i++) {
            $seed = if ($Repeat) { 'repeat' } else { "seed$i" }
            & php (Join-Path $PSScriptRoot 'make-smoke-image.php') $fixture $seed | Out-Null

            $sw = [System.Diagnostics.Stopwatch]::StartNew()
            $upload = (& curl.exe -s -c $jar -b $jar -X POST `
                -F "image=@$fixture;type=image/png" `
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
                & php artisan queue:work --once --tries=1 --timeout=180 2>&1 | Out-Null
            }

            $done = $false
            $deadline = (Get-Date).AddSeconds(90)
            while ((Get-Date) -lt $deadline) {
                $poll = (& curl.exe -s -c $jar -b $jar "$base/api/analyze/$id/status") | ConvertFrom-Json
                if ($poll.status -eq 'completed') { $done = $true; break }
                if ($poll.status -eq 'failed') { break }
                Start-Sleep -Milliseconds 250
            }
            $sw.Stop()
            $ms = [Math]::Round($sw.Elapsed.TotalMilliseconds, 1)

            if (-not $done) {
                Write-Host "FAIL: iteration $i did not complete (status=$($poll.status))"
                $failures++
                break
            }
            $latencies += $ms
            Write-Host ("  iteration {0}: {1} ms (id={2}, cached={3})" -f $i, $ms, $id, $uj.cached)
        }
    }

    if ($latencies.Count -gt 0) {
        $p50 = Get-Percentile $latencies 0.50
        $p95 = Get-Percentile $latencies 0.95
        $avg = [Math]::Round((($latencies | Measure-Object -Average).Average), 1)
        Write-Host ""
        Write-Host ("RESULT n={0} p50={1} ms p95={2} ms avg={3} ms" -f $latencies.Count, $p50, $p95, $avg)
        Write-Host ("TARGET p50<=12000 ms p95<=40000 ms -> " + $(if ($p50 -le 12000 -and $p95 -le 40000) { 'MET' } else { 'NOT MET' }))
        Write-Host "NOTE: fake-Gemini numbers are dominated by local HTTP/poll overhead."
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
    Remove-Item $fixture -Force -ErrorAction SilentlyContinue
    Restore-Env
}

if ($failures -gt 0) { Write-Host "LATENCY MEASUREMENT FAILED ($failures)"; exit 1 }
Write-Host "LATENCY MEASUREMENT DONE"
exit 0
