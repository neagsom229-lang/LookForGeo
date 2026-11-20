# scripts/dev.ps1 -- one command to run the whole local flow:
#   php artisan serve  +  queue worker  +  laravel.log tail
#
# Usage:  composer dev
# Stop with Ctrl+C.
#
# Refuses to start if another serve/worker is already running, reaps stuck
# analyses every 60s, and always kills the whole process tree on exit.

[Console]::OutputEncoding = [System.Text.UTF8Encoding]::new($false)
try { $OutputEncoding = [System.Text.UTF8Encoding]::new($false) } catch { }

$root = (Get-Item $PSScriptRoot).Parent.FullName
Set-Location $root

function Find-ExistingDevProcesses {
    $all = @(Get-CimInstance Win32_Process -Filter "Name = 'php.exe'" -ErrorAction SilentlyContinue)
    $found = @()
    foreach ($p in $all) {
        $line = "$($p.CommandLine)"
        if ($line -match 'artisan\s+serve' -or $line -match 'queue:work') {
            $found += $p
        }
    }
    return $found
}

$existing = @(Find-ExistingDevProcesses)
if ($existing.Count -gt 0) {
    Write-Host "FAILED: a dev server or queue worker is already running:" -ForegroundColor Red
    foreach ($p in $existing) {
        Write-Host ("  pid {0}: {1}" -f $p.ProcessId, $p.CommandLine)
    }
    Write-Host "Stop it first, then run composer dev again. Refusing to start a duplicate."
    exit 1
}

Write-Host "=== TraceGeo local dev ==="
Write-Host "Starting php artisan serve on http://127.0.0.1:8000 ..."
Write-Host "Starting queue worker (database, --tries=1 --timeout=180) ..."
Write-Host "Reaping stuck analyses every 60s."
Write-Host "Tailing storage\logs\laravel.log (Ctrl+C to stop everything)"
Write-Host ""

$server = Start-Process -PassThru -NoNewWindow -FilePath "php" -ArgumentList "artisan", "serve"
$worker = Start-Process -PassThru -NoNewWindow -FilePath "php" -ArgumentList "artisan", "queue:work", "--tries=1", "--timeout=180"

function Stop-ProcessTree($procId) {
    if (-not $procId) { return }
    # taskkill /T kills the whole tree (artisan serve spawns an inner
    # php -S listener; kill it by tree, not by a parent-pid lookup).
    & taskkill /F /T /PID $procId | Out-Null
    if ($LASTEXITCODE -ne 0) {
        Stop-Process -Id $procId -Force -ErrorAction SilentlyContinue
    }
}

if (-not $server -or -not $worker) {
    Write-Host "FAILED to start child processes."
    if ($server) { Stop-Process -Id $server.Id -Force -ErrorAction SilentlyContinue }
    if ($worker) { Stop-Process -Id $worker.Id -Force -ErrorAction SilentlyContinue }
    exit 1
}

$logPath = Join-Path $root 'storage\logs\laravel.log'

function Open-LogTail($path) {
    if (-not (Test-Path $path)) { return $null }
    try {
        $fs = [System.IO.File]::Open($path, [System.IO.FileMode]::Open,
            [System.IO.FileAccess]::Read, [System.IO.FileShare]::ReadWrite)
        $fs.Seek(0, [System.IO.SeekOrigin]::End) | Out-Null
        return New-Object System.IO.StreamReader($fs, [System.Text.UTF8Encoding]::new($false))
    } catch { return $null }
}

try {
    # Show the tail of any existing log, then follow it while ALSO watching
    # the child processes: if the server or the queue worker dies we must
    # scream, not sit there silently with a dead worker.
    if (Test-Path $logPath) {
        Get-Content -Path $logPath -Tail 20 -Encoding UTF8 | ForEach-Object { Write-Host $_ }
    }
    $logReader = Open-LogTail $logPath
    $failure = $null
    $nextReap = (Get-Date).AddSeconds(60)

    # PS 5.1 quirk: a force-killed child reports ExitCode as EMPTY (not a
    # number), and redirected parents also read empty -- only use it when
    # we actually got a value.
    function Get-ExitCodeLabel($proc) {
        try {
            $proc.WaitForExit()
            $ec = $proc.ExitCode
            if ("$ec" -ne '') { return "$ec" }
        } catch { }
        return 'unknown (killed externally?)'
    }

    while ($true) {
        if ($worker.HasExited) {
            $failure = "QUEUE WORKER DIED (exit code $(Get-ExitCodeLabel $worker)) -- analyses will stay 'pending' forever!`n" +
                       "  Restart with: composer dev"
            break
        }
        if ($server.HasExited) {
            $failure = "DEV SERVER DIED (exit code $(Get-ExitCodeLabel $server)) -- http://127.0.0.1:8000 is down!`n" +
                       "  Restart with: composer dev"
            break
        }

        if ((Get-Date) -ge $nextReap) {
            $reapOut = & php artisan analyses:reap-stuck --minutes=10 2>&1 | Out-String
            if ($LASTEXITCODE -ne 0) {
                Write-Host "WARNING: analyses:reap-stuck failed: $reapOut" -ForegroundColor Yellow
            } elseif ($reapOut.Trim()) {
                Write-Host "reaper: $($reapOut.Trim())"
            }
            $nextReap = (Get-Date).AddSeconds(60)
        }

        if ($logReader) {
            while (-not $logReader.EndOfStream) { Write-Host $logReader.ReadLine() }
            if ($logReader.Peek() -lt 0 -and -not (Test-Path $logPath)) {
                $logReader.Close(); $logReader = Open-LogTail $logPath
            }
        } else {
            $logReader = Open-LogTail $logPath
        }

        Start-Sleep -Milliseconds 500
    }

    if ($logReader) { $logReader.Close() }

    if ($failure) {
        Write-Host ""
        Write-Host "!!! $failure" -ForegroundColor Red
        exit 1
    }
}
finally {
    Write-Host ""
    Write-Host "Stopping dev server and queue worker..."
    Stop-ProcessTree $server.Id
    Stop-ProcessTree $worker.Id
}
