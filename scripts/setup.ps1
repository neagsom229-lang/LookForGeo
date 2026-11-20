# scripts/setup.ps1 -- bootstrap a fresh local clone for development.
# Safe to run twice. Creates nothing secret.
#
# Usage:  composer setup-local
$root = (Get-Item $PSScriptRoot).Parent.FullName
Set-Location $root

Write-Host "=== TraceGeo local setup ==="

# 0. Preflight: `php artisan` cannot boot without vendor/.
#    Dependencies are installed by `composer install`, not here: nesting a
#    composer install inside a composer script hits Composer's 300s
#    process-timeout on a cold cache (proven on a fresh clone).
if (-not (Test-Path (Join-Path $root 'vendor\autoload.php'))) {
    Write-Host "[setup] vendor/ is missing. Run this first:" -ForegroundColor Red
    Write-Host "    composer install" -ForegroundColor Yellow
    Write-Host "  then re-run: composer setup-local" -ForegroundColor Yellow
    exit 1
}

# 1. .env from example (never overwrites an existing .env)
if (-not (Test-Path (Join-Path $root '.env'))) {
    Copy-Item (Join-Path $root '.env.example') (Join-Path $root '.env')
    Write-Host "[setup] Copied .env.example -> .env"
} else {
    Write-Host "[setup] .env already exists -- leaving untouched"

    # Contamination guard: detect production-shaped settings by KEY NAME ONLY.
    # Values are never printed, echoed, or logged -- only the offending key names.
    $envContent = Get-Content (Join-Path $root '.env') -Raw
    $issues = @()

    $appUrl = [regex]::Match($envContent, '(?m)^APP_URL=(\S+)').Groups[1].Value
    if ($appUrl -and ($appUrl -notmatch '^(https?://)?(localhost|127\.0\.0\.1|0\.0\.0\.0)(:|/|$)')) {
        $issues += 'APP_URL (points somewhere other than localhost)'
    }
    if ($envContent -match '(?m)^SESSION_SECURE_COOKIE=true') {
        $issues += 'SESSION_SECURE_COOKIE=true (secure cookies are dropped over http://localhost)'
    }
    $sessionDomain = [regex]::Match($envContent, '(?m)^SESSION_DOMAIN=(\S+)').Groups[1].Value
    if ($sessionDomain -and ($sessionDomain -notmatch '^(null|localhost|127\.0\.0\.1|\.localhost)$')) {
        $issues += 'SESSION_DOMAIN is set (domain-scoped cookies break localhost CSRF/session)'
    }
    if ($envContent -match '(?m)^DB_CONNECTION=pgsql') {
        $issues += 'DB_CONNECTION=pgsql (local dev must use sqlite)'
    }
    if ($envContent -match '(?m)^DATABASE_URL=') {
        $issues += 'DATABASE_URL is set (production wiring must live in the Render dashboard only)'
    }

    # Duplicate keys (names only) -- e.g. a leftover pgsql line shadowing the sqlite one
    $keyCounts = @{}
    foreach ($line in (Get-Content (Join-Path $root '.env'))) {
        if ($line -match '^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=') {
            $k = $Matches[1]
            if ($keyCounts.ContainsKey($k)) { $keyCounts[$k]++ } else { $keyCounts[$k] = 1 }
        }
    }
    foreach ($k in $keyCounts.Keys) {
        if ($keyCounts[$k] -gt 1) {
            $issues += "duplicate key in .env: $k"
        }
    }

    # Any value containing onrender.com (key names only, never the values)
    foreach ($line in (Get-Content (Join-Path $root '.env'))) {
        if ($line -match '^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=.*onrender\.com') {
            $issues += "value contains onrender.com: $($Matches[1])"
        }
    }

    if ($issues.Count -gt 0) {
        Write-Host ""
        Write-Host "WARNING: .env looks contaminated with production settings (key names only):" -ForegroundColor Red
        foreach ($i in $issues) { Write-Host ("  - " + $i) -ForegroundColor Red }
        Write-Host ""
        Write-Host "Stop here. Fix the keys above manually to local values" -ForegroundColor Red
        Write-Host "(APP_URL=http://localhost:8000, SESSION_SECURE_COOKIE=false," -ForegroundColor Red
        Write-Host " SESSION_DOMAIN=null, DB_CONNECTION=sqlite, no DATABASE_URL)." -ForegroundColor Red
        Write-Host "Values were NOT printed; only key names are shown." -ForegroundColor Red
        exit 1
    }
    Write-Host "[setup] .env contamination check OK" -ForegroundColor Green
}

# 2. Runtime directories. Git cannot track empty dirs, so older clones may lack
#    these; without them Laravel fails with "bootstrap/cache directory must be
#    present and writable" -- and artisan can report that error while exiting 0.
$runtimeDirs = @(
    'bootstrap\cache',
    'storage\framework\cache\data',
    'storage\framework\sessions',
    'storage\framework\testing',
    'storage\framework\views',
    'storage\logs'
)
$createdDirs = 0
foreach ($dir in $runtimeDirs) {
    $full = Join-Path $root $dir
    if (-not (Test-Path $full)) {
        New-Item -ItemType Directory -Path $full -Force | Out-Null
        $createdDirs++
    }
}
if ($createdDirs -gt 0) {
    Write-Host "[setup] Created $createdDirs runtime director$(if ($createdDirs -eq 1) {'y'} else {'ies'})"
} else {
    Write-Host "[setup] Runtime directories present"
}

# 3. Application key (only if not already set)
$envContent = Get-Content (Join-Path $root '.env') -Raw
$keyExists = ($envContent -match '(?m)^APP_KEY=.+')
if (-not $keyExists) {
    $keyOut = & php artisan key:generate 2>&1 | Out-String
    if ($keyOut.Trim()) { Write-Host $keyOut.Trim() }

    # Never trust the exit code alone: key:generate exits 0 even when the app
    # cannot boot (proven with a missing bootstrap/cache). Verify the file.
    $envAfter = Get-Content (Join-Path $root '.env') -Raw
    if ($envAfter -notmatch '(?m)^APP_KEY=\S+') {
        Write-Host "[setup] key:generate FAILED -- APP_KEY is still empty. See output above." -ForegroundColor Red
        exit 1
    }
    Write-Host "[setup] Generated APP_KEY"
} else {
    Write-Host "[setup] APP_KEY already set -- keeping it"
}

# 4. Local SQLite database
$sqlite = Join-Path $root 'database\database.sqlite'
if (-not (Test-Path $sqlite)) {
    New-Item -ItemType File $sqlite | Out-Null
    Write-Host "[setup] Created database\database.sqlite"
} else {
    Write-Host "[setup] database.sqlite already exists"
}

# 5. storage:link (idempotent)
if (-not (Test-Path (Join-Path $root 'public\storage'))) {
    php artisan storage:link
    if (-not (Test-Path (Join-Path $root 'public\storage'))) {
        Write-Host "[setup] storage:link FAILED -- public/storage was not created." -ForegroundColor Red
        exit 1
    }
    Write-Host "[setup] Created public/storage symlink"
} else {
    Write-Host "[setup] public/storage already linked"
}

# 6. Migrations
php artisan migrate --force
if ($LASTEXITCODE -ne 0) {
    Write-Host "[setup] Migrations FAILED -- see output above." -ForegroundColor Red
    exit 1
}
Write-Host "[setup] Migrations applied"

# 6b. Boot check: the application must load without PHP notices/deprecations.
$bootOut = & php artisan db:show 2>&1 | Out-String
if ($bootOut -match 'Deprecated:|Warning:|Notice:') {
    Write-Host ""
    Write-Host "[setup] BOOT CHECK FAILED -- PHP emitted notices during app boot:" -ForegroundColor Red
    foreach ($line in ($bootOut -split "`n")) {
        if ($line -match 'Deprecated:|Warning:|Notice:') {
            Write-Host ("  " + $line.Trim()) -ForegroundColor Red
        }
    }
    Write-Host "[setup] Fix the boot notices above (config, dependencies, or PHP version) and re-run." -ForegroundColor Red
    exit 1
}
Write-Host "[setup] Boot check OK -- app boots without notices" -ForegroundColor Green

# 7. Demo user (idempotent)
php artisan tinker --execute="if (!\App\Models\User::where('email', 'demo@example.com')->exists()) { \App\Models\User::create(['name' => 'Demo User', 'email' => 'demo@example.com', 'password' => bcrypt('password')]); echo 'demo user created (demo@example.com / password)'; } else { echo 'demo user exists (demo@example.com / password)'; }"

Write-Host ""
Write-Host "Done. Next steps:"
Write-Host "  1. Add your GEMINI_API_KEY to .env (never commit it)"
Write-Host "  2. composer dev"
Write-Host "  3. Open http://127.0.0.1:8000  -- register/login (demo@example.com / password)"
