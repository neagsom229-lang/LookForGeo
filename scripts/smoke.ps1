# scripts/smoke.ps1 -- run the local end-to-end smoke test.
# Prints PASS/FAIL and exits non-zero on failure.
# Parse-checks all scripts with the Windows PowerShell parser first.
#
# Usage:
#   scripts\smoke.ps1           # fake Gemini (Http::fake) -- deterministic, offline
#   scripts\smoke.ps1 -Live     # real Gemini via $env:GEMINI_API_KEY (prints latency)

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

if ($Live) {
    if ([string]::IsNullOrWhiteSpace($env:GEMINI_API_KEY)) {
        Write-Host "LIVE mode needs `$env:GEMINI_API_KEY in the environment."
        Write-Host "Set it, then re-run:  scripts\smoke.ps1 -Live"
        exit 2
    }
    Write-Host "Mode: LIVE (real Gemini API)"
    $filter = 'SmokeTest'
} else {
    Write-Host "Mode: FAKE (Http::fake, offline)"
    $filter = 'SmokeTest'
}

$output = & php artisan test --filter=$filter 2>&1

$text = ($output | Out-String)
Write-Host $text

if ($LASTEXITCODE -ne 0) {
    Write-Host "SMOKE TEST FAILED"
    exit 1
}

Write-Host "SMOKE TEST PASSED"
exit 0
