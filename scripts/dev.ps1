# scripts/dev.ps1 — one command to run the whole local flow:
#   php artisan serve  +  queue worker  +  laravel.log tail
#
# Usage:  composer dev
# Stop with Ctrl+C.

$root = (Get-Item $PSScriptRoot).Parent.FullName
Set-Location $root

Write-Host "=== TraceGeo local dev ===" -ForegroundColor Cyan
Write-Host "Starting php artisan serve on http://127.0.0.1:8000 ..." -ForegroundColor Gray
Write-Host "Starting queue worker (database, --tries=1 --timeout=180) ..." -ForegroundColor Gray
Write-Host "Tailing storage\logs\laravel.log (Ctrl+C to stop everything)" -ForegroundColor Gray
Write-Host ""

$server = Start-Process -PassThru -NoNewWindow -FilePath "php" -ArgumentList "artisan", "serve"
$worker = Start-Process -PassThru -NoNewWindow -FilePath "php" -ArgumentList "artisan", "queue:work", "--tries=1", "--timeout=180"

if (-not $server -or -not $worker) {
    Write-Host "FAILED to start child processes." -ForegroundColor Red
    if ($server) { Stop-Process -Id $server.Id -Force -ErrorAction SilentlyContinue }
    exit 1
}

try {
    # Blocks until Ctrl+C; then finally{} cleans up the children.
    Get-Content -Path (Join-Path $root 'storage\logs\laravel.log') -Wait -Tail 20
}
finally {
    Write-Host ""
    Write-Host "Stopping dev server and queue worker..." -ForegroundColor Yellow
    Stop-Process -Id $server.Id, $worker.Id -Force -ErrorAction SilentlyContinue
}
