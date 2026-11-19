# scripts/smoke.ps1 — run the local end-to-end smoke test.
# Prints PASS/FAIL and exits non-zero on failure.
#
# Usage:
#   scripts\smoke.ps1           # fake Gemini (Http::fake) — deterministic, offline
#   scripts\smoke.ps1 -Live     # real Gemini via $env:GEMINI_API_KEY (prints latency)

param([switch]$Live)

$root = (Get-Item $PSScriptRoot).Parent.FullName
Set-Location $root

Write-Host "=== TraceGeo smoke test ===" -ForegroundColor Cyan

if ($Live) {
    if ([string]::IsNullOrWhiteSpace($env:GEMINI_API_KEY)) {
        Write-Host "LIVE mode needs $env:GEMINI_API_KEY in the environment." -ForegroundColor Yellow
        Write-Host "Set it, then re-run:  scripts\smoke.ps1 -Live" -ForegroundColor Yellow
        exit 2
    }
    Write-Host "Mode: LIVE (real Gemini API)" -ForegroundColor Yellow
    $filter = 'SmokeTest'
} else {
    Write-Host "Mode: FAKE (Http::fake, offline)" -ForegroundColor Gray
    $filter = 'SmokeTest'
}

$env:PATH = "$env:PATH;$root\vendor\bin"
$output = & php artisan test --filter=$filter 2>&1

$text = ($output | Out-String)
Write-Host $text

if ($LASTEXITCODE -ne 0) {
    Write-Host "SMOKE TEST FAILED" -ForegroundColor Red
    exit 1
}

Write-Host "SMOKE TEST PASSED" -ForegroundColor Green
exit 0
