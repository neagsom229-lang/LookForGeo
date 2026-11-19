# scripts/smoke.ps1 -- run the local end-to-end smoke test.
# Prints PASS/FAIL and exits non-zero on failure.
# Parse-checks all scripts with the Windows PowerShell parser first.
#
# Usage:
#   scripts\smoke.ps1           # fake Gemini (Http::fake) -- deterministic, offline
#   scripts\smoke.ps1 -Live     # real Gemini via $env:GEMINI_API_KEY (prints latency)
#
# Stage A (offline): PHPUnit SmokeTest with Http::fake -- exits non-zero on failure.
# Stage B (real HTTP): artisan serve on a free port + curl.exe cookie jar.
#                      Fails if any check fails.

param([switch]$Live)

[Console]::OutputEncoding = [System.Text.UTF8Encoding]::new($false)

$root = (Get-Item $PSScriptRoot).Parent.FullName
Set-Location $root

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

# Pick a free port
$listener = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Loopback, 0)
$listener.Start()
$port = $listener.LocalEndpoint.Port
$listener.Stop()
Write-Host "Using port: $port"

$server = Start-Process -PassThru -NoNewWindow -FilePath "php" `
    -ArgumentList "artisan", "serve", "--host=127.0.0.1", "--port=$port"

$jar = [System.IO.Path]::GetTempFileName()
$base = "http://127.0.0.1:$port"
$email = "smoke+" + [guid]::NewGuid().ToString("N").Substring(0, 8) + "@example.com"

function Split-HttpResponse([string]$raw) {
    $m = [regex]::Match($raw, "`r?`n`r?`n")
    if ($m.Success) {
        return @($raw.Substring(0, $m.Index), $raw.Substring($m.Index + $m.Length))
    }
    return @($raw, "")
}

function Get-HeaderBlock([string]$raw) {
    return (Split-HttpResponse $raw)[0]
}

function Get-StatusCode([string]$raw) {
    $firstLine = (($raw -split "`r?`n", 2)[0])
    if ($firstLine -match '^HTTP/\d(\.\d)?\s+(\d{3})') {
        return [int]$Matches[2]
    }
    return 0
}

try {
    # Wait for the server to come up
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
        # B1. GET /register with a cookie jar
        $reg = (& curl.exe -s -i -c $jar -b $jar "$base/register") -join "`n"
        $regHead = Get-HeaderBlock $reg
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

            # B2. POST /register with the CSRF token -- expect 302
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

            # B3. login -- then GET /analysis must be 200
            $login = (& curl.exe -s -i -c $jar -b $jar "$base/login") -join "`n"
            $loginBody = (Split-HttpResponse $login)[1]
            $lm = [regex]::Match($loginBody, '<meta name="csrf-token" content="([^"]+)"')
            if (-not $lm.Success) {
                Write-Host "FAIL: could not extract CSRF token from /login page"
                $failures++
            } else {
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

                $analysis = (& curl.exe -s -i -c $jar -b $jar "$base/analysis") -join "`n"
                $analysisStatus = Get-StatusCode $analysis
                if ($analysisStatus -eq 200) {
                    Write-Host "PASS: GET /analysis -> 200"
                } else {
                    Write-Host "FAIL: GET /analysis -> $analysisStatus (expected 200)"
                    $failures++
                }
            }
        }
    }
}
finally {
    if ($server -and -not $server.HasExited) {
        Stop-Process -Id $server.Id -Force -ErrorAction SilentlyContinue
    }
    Remove-Item $jar -Force -ErrorAction SilentlyContinue
}

if ($failures -gt 0) {
    Write-Host "REAL HTTP SMOKE FAILED ($failures check(s) failed)"
    exit 1
}

Write-Host "REAL HTTP SMOKE PASSED"
exit 0
