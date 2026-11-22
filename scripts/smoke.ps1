# scripts/smoke.ps1 -- run the local end-to-end smoke test.
# Prints PASS/FAIL and exits non-zero on failure.
# Parse-checks all scripts with the Windows PowerShell parser first.
#
# Usage:
#   scripts\smoke.ps1           # fake Gemini (Http::fake) -- deterministic, offline
#   scripts\smoke.ps1 -Live     # real Gemini via $env:GEMINI_API_KEY (prints latency)
#
# Stage A (offline): PHPUnit SmokeTest with Http::fake -- exits non-zero on failure.
# Stage B (real HTTP): artisan serve on a free port + curl.exe cookie jar,
#                      temporary SQLite DB, fake-Gemini HTTP server, real
#                      queue worker process. Fails if any check fails.

param([switch]$Live)

[Console]::OutputEncoding = [System.Text.UTF8Encoding]::new($false)

$root = (Get-Item $PSScriptRoot).Parent.FullName
Set-Location $root

# Remember where this run starts in the file logs so failure diagnostics only
# show lines produced by this run.
$logStarts = @{}
foreach ($logPath in @((Join-Path $root 'storage\logs\laravel.log'), (Join-Path $root 'storage\logs\laravel-test.log'))) {
    if (Test-Path $logPath) {
        $logStarts[$logPath] = @(Get-Content $logPath -ErrorAction SilentlyContinue).Count
    } else {
        $logStarts[$logPath] = 0
    }
}

Write-Host "=== TraceGeo smoke test ==="

# 0. Parse check (Windows PowerShell 5.1 compatibility gate)
& (Join-Path $PSScriptRoot 'Lint.ps1')
if ($LASTEXITCODE -ne 0) {
    Write-Host "Script parse check FAILED. Fix syntax errors first."
    exit 1
}

# ---------------- Stage A: offline PHPUnit smoke ----------------
if ($Live) {
    if ([string]::IsNullOrWhiteSpace($env:GEMINI_API_KEY)) {
        Write-Host "LIVE mode needs `$env:GEMINI_API_KEY in the environment."
        Write-Host "Set it, then re-run:  scripts\smoke.ps1 -Live"
        exit 2
    }
    Write-Host "Mode: LIVE (real Gemini API)"

    # Probe each configured model. gemini:probe prints only model, HTTP
    # status and seconds -- never the API key. GEMINI_BASE_URL is honored so
    # a local mock or a corporate proxy can be measured too.
    Write-Host "--- Live Gemini model probe (status + seconds, key hidden) ---"
    $probe = & php artisan gemini:probe 2>&1 | Out-String
    $probeExit = $LASTEXITCODE
    Write-Host $probe
    if ($probeExit -ne 0) {
        Write-Host "LIVE Gemini probe FAILED."
        exit 1
    }
} else {
    Write-Host "Mode: FAKE (Http::fake, offline)"
}

$output = & php artisan test --filter=SmokeTest 2>&1
$text = ($output | Out-String)
Write-Host $text

if ($LASTEXITCODE -ne 0) {
    Write-Host "STAGE A (offline smoke) FAILED"
    exit 1
}

# ---------------- Stage B: real HTTP smoke ----------------
Write-Host ""
Write-Host "=== Stage B: real HTTP smoke (artisan serve + curl.exe) ==="

$failures = 0
$tmpDb = Join-Path ([System.IO.Path]::GetTempPath()) ("tg_smoke_" + [guid]::NewGuid().ToString("N") + ".sqlite")
$fixture = Join-Path ([System.IO.Path]::GetTempPath()) ("tg_smoke_" + [guid]::NewGuid().ToString("N") + ".png")
$serverLog = Join-Path ([System.IO.Path]::GetTempPath()) ("tg_smoke_" + [guid]::NewGuid().ToString("N") + ".server.log")
$serverErr = Join-Path ([System.IO.Path]::GetTempPath()) ("tg_smoke_" + [guid]::NewGuid().ToString("N") + ".server.err")
$workerLog = Join-Path ([System.IO.Path]::GetTempPath()) ("tg_smoke_" + [guid]::NewGuid().ToString("N") + ".worker.log")
$envBackup = @{}

function Save-Env([string[]]$keys) {
    foreach ($k in $keys) {
        $envBackup[$k] = (Get-Item "env:$k" -ErrorAction SilentlyContinue).Value
    }
}

function Restore-Env {
    foreach ($k in $envBackup.Keys) {
        $old = $envBackup[$k]
        if ($null -eq $old) {
            Remove-Item "env:$k" -ErrorAction SilentlyContinue
        } else {
            Set-Item "env:$k" $old
        }
    }
}

function Stop-ProcessTree($procId) {
    if (-not $procId) { return }
    # taskkill /T kills the whole tree (artisan serve spawns an inner php -S
    # listener through an intermediate shell; kill by tree, not by pid lookup).
    & taskkill /F /T /PID $procId | Out-Null
    if ($LASTEXITCODE -ne 0) {
        Stop-Process -Id $procId -Force -ErrorAction SilentlyContinue
    }
}

function Split-HttpResponse([string]$raw) {
    $m = [regex]::Match($raw, "`r?`n`r?`n")
    if ($m.Success) {
        return @($raw.Substring(0, $m.Index), $raw.Substring($m.Index + $m.Length))
    }
    return @($raw, "")
}

function Get-StatusCode([string]$raw) {
    $firstLine = (($raw -split "`r?`n", 2)[0])
    if ($firstLine -match '^HTTP/\d(\.\d)?\s+(\d{3})') {
        return [int]$Matches[2]
    }
    return 0
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
    $lines = @()
    $fileLogs = @(
        (Join-Path $root 'storage\logs\laravel.log'),
        (Join-Path $root 'storage\logs\laravel-test.log')
    )
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

function Start-FreePortServer([string]$file, [string[]]$extraArgs) {
    $listener = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Loopback, 0)
    $listener.Start()
    $port = $listener.LocalEndpoint.Port
    $listener.Stop()
    $args = @("artisan", "serve", "--host=127.0.0.1", "--port=$port") + $extraArgs
    $proc = Start-Process -PassThru -NoNewWindow -FilePath "php" -ArgumentList $args `
        -RedirectStandardOutput $serverLog -RedirectStandardError $serverErr
    return @($proc, $port)
}

Save-Env @('DB_DATABASE', 'QUEUE_CONNECTION', 'GEMINI_API_KEY', 'GEMINI_BASE_URL', 'CLOUDINARY_CLOUD_NAME', 'CLOUDINARY_API_KEY', 'CLOUDINARY_API_SECRET', 'CLOUDINARY_URL')
try {
    # Fresh, isolated database for the run -- never the real one.
    New-Item -ItemType File $tmpDb | Out-Null
    $env:DB_DATABASE = $tmpDb
    $env:QUEUE_CONNECTION = "database"
    $env:GEMINI_API_KEY = "smoke-fake-key"
    # Force the local-disk upload path: no Cloudinary credentials means no
    # cloud call, no cost and a deterministic offline smoke run.
    $env:CLOUDINARY_CLOUD_NAME = ""
    $env:CLOUDINARY_API_KEY = ""
    $env:CLOUDINARY_API_SECRET = ""
    $env:CLOUDINARY_URL = ""
    Write-Host "Cloudinary disabled for smoke (no cloud calls expected)."

    & php artisan migrate --force 2>&1 | Out-Null
    if ($LASTEXITCODE -ne 0) {
        Write-Host "FAIL: migrations on the temporary SQLite DB failed"
        $failures++
    }

    # Fake Gemini HTTP server (structured-output JSON).
    # Path is quoted so spaces survive Windows process creation.
    $fakeListener = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Loopback, 0)
    $fakeListener.Start()
    $fakePort = $fakeListener.LocalEndpoint.Port
    $fakeListener.Stop()
    $fakeRouter = (Join-Path $PSScriptRoot 'fake-gemini-router.php')
    $fake = Start-Process -PassThru -NoNewWindow -FilePath "php" `
        -ArgumentList '-S', "127.0.0.1:$fakePort", ('"' + $fakeRouter + '"')
    $env:GEMINI_BASE_URL = "http://127.0.0.1:$fakePort/v1beta"

    $serverInfo = Start-FreePortServer 'artisan' @()
    $server = $serverInfo[0]
    $port = $serverInfo[1]
    $jar = (Join-Path ([System.IO.Path]::GetTempPath()) ("tg_jar_" + [guid]::NewGuid().ToString("N") + ".txt"))
    $jar2 = (Join-Path ([System.IO.Path]::GetTempPath()) ("tg_jar_" + [guid]::NewGuid().ToString("N") + ".txt"))
    $base = "http://127.0.0.1:$port"
    $email = "smoke+" + [guid]::NewGuid().ToString("N").Substring(0, 8) + "@example.com"
    $email2 = "smoke+" + [guid]::NewGuid().ToString("N").Substring(0, 8) + "@example.com"

    & php (Join-Path $PSScriptRoot 'make-smoke-image.php') $fixture | Out-Null

    # Wait for the app server
    $up = $false
    for ($i = 0; $i -lt 30; $i++) {
        $code = & curl.exe -s -o NUL -w "%{http_code}" "$base/api/health"
        if ($code -eq '200') { $up = $true; break }
        Start-Sleep -Milliseconds 500
    }
    if (-not $up) {
        Write-Host "FAIL: server did not come up (health check never returned 200)"
        $failures++
    } else {
        Write-Host "PASS: server is up, /api/health = 200"
    }

    if ($failures -eq 0) {
        # ---------- B1. register flow ----------
        $reg = (& curl.exe -s -i -c $jar -b $jar "$base/register") -join "`n"
        $regHead = (Split-HttpResponse $reg)[0]
        $regBody = (Split-HttpResponse $reg)[1]

        $bodyOk = $regBody.StartsWith('<!DOCTYPE', [System.StringComparison]::OrdinalIgnoreCase)
        $warn = ($regBody -match 'Deprecated:|Warning:')
        if ($bodyOk -and -not $warn) {
            Write-Host "PASS: register HTML starts with <!DOCTYPE and contains no Deprecated:/Warning:"
        } else {
            Write-Host "FAIL: register HTML (startsWithDOCTYPE=$bodyOk containsWarnings=$warn)"
            $failures++
        }

        if ($regHead -match 'Set-Cookie:\s*[A-Za-z_]+_session=') {
            Write-Host "PASS: Set-Cookie session cookie present"
        } else {
            Write-Host "FAIL: no session Set-Cookie header in /register response"
            $failures++
        }

        $m = [regex]::Match($regBody, '<meta name="csrf-token" content="([^"]+)"')
        if (-not $m.Success) {
            Write-Host "FAIL: could not extract CSRF token from /register page"
            $failures++
        } else {
            $token = $m.Groups[1].Value

            $post = (& curl.exe -s -i -c $jar -b $jar -X POST `
                --data-urlencode "name=Smoke User" `
                --data-urlencode "email=$email" `
                --data-urlencode "password=password" `
                --data-urlencode "password_confirmation=password" `
                --data-urlencode "_token=$token" `
                "$base/register") -join "`n"
            $postStatus = Get-StatusCode $post
            if ($postStatus -eq 302) {
                Write-Host "PASS: POST /register -> 302"
            } else {
                Write-Host "FAIL: POST /register -> $postStatus (expected 302)"
                $failures++
            }

            $login = (& curl.exe -s -i -c $jar -b $jar "$base/login") -join "`n"
            $loginBody = (Split-HttpResponse $login)[1]
            $lm = [regex]::Match($loginBody, '<meta name="csrf-token" content="([^"]+)"')
            if ($lm.Success) {
                $loginToken = $lm.Groups[1].Value
                $loginPost = (& curl.exe -s -i -c $jar -b $jar -X POST `
                    --data-urlencode "email=$email" `
                    --data-urlencode "password=password" `
                    --data-urlencode "_token=$loginToken" `
                    "$base/login") -join "`n"
                $loginStatus = Get-StatusCode $loginPost
                if ($loginStatus -eq 302) {
                    Write-Host "PASS: POST /login -> 302"
                } else {
                    Write-Host "FAIL: POST /login -> $loginStatus (expected 302)"
                    $failures++
                }
            } else {
                Write-Host "FAIL: could not extract CSRF token from /login page"
                $failures++
            }

            # ---------- B3b. real-HTTP CSRF reproduction from "/" ----------
            # Reproduces the reported 419: load the home page with a cookie
            # jar, read the meta csrf-token, POST a PNG and require success.
            $homePage = (& curl.exe -s -i -c $jar -b $jar "$base/") -join "`n"
            $homeStatus = Get-StatusCode $homePage
            $homeBody = (Split-HttpResponse $homePage)[1]
            $homeTokenMatch = [regex]::Match($homeBody, '<meta name="csrf-token" content="([^"]+)"')
            if ($homeStatus -ne 200 -or -not $homeTokenMatch.Success) {
                Write-Host "FAIL: GET / -> $homeStatus or no csrf-token on home page"
                $failures++
            } else {
                $homeToken = $homeTokenMatch.Groups[1].Value
                $homeUpload = (& curl.exe -s -i -c $jar -b $jar -X POST `
                    -H "X-CSRF-TOKEN: $homeToken" `
                    -H "Accept: application/json" `
                    -F "image=@$fixture;type=image/png" `
                    "$base/api/analyze/store") -join "`n"
                $homeUploadStatus = Get-StatusCode $homeUpload
                if ($homeUploadStatus -eq '419') {
                    Write-Host "FAIL: POST /api/analyze/store with home CSRF token -> 419"
                    $failures++
                } elseif ($homeUploadStatus -eq '200') {
                    Write-Host "PASS: POST /api/analyze/store with home CSRF token -> 200 (not 419)"
                } else {
                    Write-Host "FAIL: POST /api/analyze/store with home CSRF token -> $homeUploadStatus"
                    $failures++
                }
            }

            $analysisPage = (& curl.exe -s -i -c $jar -b $jar "$base/analysis") -join "`n"
            $analysisStatus = Get-StatusCode $analysisPage
            if ($analysisStatus -eq 200) {
                Write-Host "PASS: GET /analysis -> 200"
            } else {
                Write-Host "FAIL: GET /analysis -> $analysisStatus (expected 200)"
                $failures++
            }

            # ---------- B4. full analysis flow with the fake Gemini ----------
            $analysisBody = (Split-HttpResponse $analysisPage)[1]
            $pm = [regex]::Match($analysisBody, '<meta name="csrf-token" content="([^"]+)"')
            if (-not $pm.Success) {
                Write-Host "FAIL: could not extract CSRF token from /analysis page"
                $failures++
            } else {
                $csrf = $pm.Groups[1].Value
                $upload = (& curl.exe -s -i -c $jar -b $jar -X POST `
                    -F "image=@$fixture;type=image/png" `
                    --form "_token=$csrf" `
                    "$base/api/analyze/store") -join "`n"
                $uploadStatus = Get-StatusCode $upload
                $uploadBody = (Split-HttpResponse $upload)[1]
                if ($uploadStatus -eq 200) {
                    Write-Host "PASS: POST /api/analyze/store -> 200"
                } else {
                    Write-Host "FAIL: POST /api/analyze/store -> $uploadStatus"
                    Write-Host ("upload body: " + $uploadBody.Substring(0, [Math]::Min(300, $uploadBody.Length)))
                    $failures++
                }

                $uploadJson = $uploadBody | ConvertFrom-Json
                $analysisId = $uploadJson.id

                $uploadImageUrl = "$($uploadJson.data.image_url)"
                if ($uploadImageUrl -and $uploadImageUrl -notmatch 'cloudinary\.com') {
                    Write-Host "PASS: no Cloudinary call (image_url=$uploadImageUrl)"
                } else {
                    Write-Host "FAIL: upload used Cloudinary or returned no local URL: $uploadImageUrl"
                    $failures++
                }

                if ($analysisId) {
                    Write-Host "Analysis id: $analysisId"

                    # ---- B4a. NO worker running yet: status must stay pending
                    # and the local hint must appear once queued > 20s.
                    # Backdate the row instead of sleeping 20s.
                    $bd = "\App\Models\GeoAnalysis::where('id',$analysisId)->update(['created_at' => now()->subMinutes(3), 'started_at' => now()->subMinutes(3)]); 'ok';"
                    & php artisan tinker --execute="$bd" 2>$null | Out-Null
                    $pend = (& curl.exe -s -c $jar -b $jar "$base/api/analyze/$analysisId/status") -join "`n"
                    $pendJson = $pend | ConvertFrom-Json
                    if ($pendJson.status -eq 'pending' -and $pendJson.queued_seconds -ge 20 -and "$($pendJson.hint)" -match 'queue worker not running') {
                        Write-Host "PASS: no worker -> status=pending queued_seconds=$($pendJson.queued_seconds) hint='$($pendJson.hint)'"
                    } else {
                        Write-Host "FAIL: no-worker check: status=$($pendJson.status) queued_seconds=$($pendJson.queued_seconds) hint=$($pendJson.hint)"
                        $failures++
                    }

                    # Run one job from the queue as a separate process
                    $workerOut = & php artisan queue:work --once --tries=1 --timeout=180 2>&1 | Tee-Object -FilePath $workerLog -Append | Out-String
                    if ($workerOut -match 'DONE|Processed|Completed|Success' -or $LASTEXITCODE -eq 0) {
                        Write-Host "PASS: queue worker processed the analysis job"
                    } else {
                        Write-Host "FAIL: queue worker did not process the job"
                        Write-Host $workerOut
                        $failures++
                    }

                    # Poll until completed (30s timeout)
                    $done = $false
                    $deadline = (Get-Date).AddSeconds(30)
                    $finalStatus = $null
                    while ((Get-Date) -lt $deadline) {
                        $poll = (& curl.exe -s -c $jar -b $jar "$base/api/analyze/$analysisId/status") -join "`n"
                        $pollJson = $poll | ConvertFrom-Json
                        if ($pollJson.status -eq 'completed') {
                            $done = $true
                            $finalStatus = $pollJson
                            break
                        }
                        if ($pollJson.status -eq 'failed') {
                            Write-Host ("FAIL: analysis job reported status=failed: " + (Redact-Text $pollJson.error))
                            Show-FailureDiagnostics $pollJson $analysisId
                            $failures++
                            break
                        }
                        Start-Sleep -Milliseconds 500
                    }

                    if ($done) {
                        $lat = $finalStatus.result.latitude
                        $lng = $finalStatus.result.longitude
                        $name = $finalStatus.result.landmark_name
                        if ($lat -and $lng -and $name) {
                            Write-Host "PASS: status=completed with latitude=$lat longitude=$lng landmark_name=$name"
                        } else {
                            Write-Host "FAIL: completed result missing latitude/longitude/landmark_name"
                            $failures++
                        }
                    } elseif ($failures -eq 0) {
                        Write-Host "FAIL: analysis did not reach status=completed within 30s"
                        Show-FailureDiagnostics $pollJson $analysisId
                        $failures++
                    }

                    # ---------- B5. authorization checks ----------
                    $reg2 = (& curl.exe -s -i -c $jar2 -b $jar2 "$base/register") -join "`n"
                    $reg2Body = (Split-HttpResponse $reg2)[1]
                    $m2 = [regex]::Match($reg2Body, '<meta name="csrf-token" content="([^"]+)"')
                    if ($m2.Success) {
                        $token2 = $m2.Groups[1].Value
                        & curl.exe -s -i -c $jar2 -b $jar2 -X POST `
                            --data-urlencode "name=Second User" `
                            --data-urlencode "email=$email2" `
                            --data-urlencode "password=password" `
                            --data-urlencode "password_confirmation=password" `
                            --data-urlencode "_token=$token2" `
                            "$base/register" | Out-Null
                    }

                    $other = (& curl.exe -s -o NUL -w "%{http_code}" -c $jar2 -b $jar2 "$base/api/analyze/$analysisId/status")
                    if ($other -eq '404') {
                        Write-Host "PASS: second user gets 404 on the analysis"
                    } else {
                        Write-Host "FAIL: second user got $other on the analysis (expected 404)"
                        $failures++
                    }

                    # No Accept header on purpose: anonymous API access must be 401
                    # regardless of the request shape (see bootstrap/app.php).
                    $anon = (& curl.exe -s -o NUL -w "%{http_code}" "$base/api/search?query=paris")
                    if ($anon -eq '401') {
                        Write-Host "PASS: anonymous /api/search returns 401"
                    } else {
                        Write-Host "FAIL: anonymous /api/search returned $anon (expected 401)"
                        $failures++
                    }
                } else {
                    Write-Host "FAIL: no analysis id in /api/analyze/store response"
                    $failures++
                }
            }
        }
    }
}
finally {
    if ($fake) { Stop-ProcessTree $fake.Id }
    if ($server) {
        Stop-ProcessTree $server.Id
        # Kill the inner artisan-serve listener bound to OUR port
        # (taskkill /T can refuse cross-session processes; netstat+Stop-Process works).
        try {
            $conns = @(netstat -ano | Select-String -Pattern ":$([int]$port)\s")
            foreach ($c in $conns) {
                $listenPid = [int]((($c.ToString() -split '\s+') | Where-Object { $_ })[-1])
                if ($listenPid -gt 0) {
                    Stop-Process -Id $listenPid -Force -ErrorAction SilentlyContinue
                }
            }
        } catch { }
    }
    Remove-Item $tmpDb -Force -ErrorAction SilentlyContinue
    Remove-Item $fixture -Force -ErrorAction SilentlyContinue
    Remove-Item $jar -Force -ErrorAction SilentlyContinue
    Remove-Item $jar2 -Force -ErrorAction SilentlyContinue
    Remove-Item $serverLog -Force -ErrorAction SilentlyContinue
    Remove-Item $serverErr -Force -ErrorAction SilentlyContinue
    Remove-Item $workerLog -Force -ErrorAction SilentlyContinue
    Restore-Env
}

if ($failures -gt 0) {
    Write-Host "REAL HTTP SMOKE FAILED ($failures check(s) failed)"
    exit 1
}

Write-Host "REAL HTTP SMOKE PASSED"
exit 0
