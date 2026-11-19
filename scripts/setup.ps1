# scripts/setup.ps1 -- bootstrap a fresh local clone for development.
# Safe to run twice. Creates nothing secret.
#
# Usage:  composer setup-local
$root = (Get-Item $PSScriptRoot).Parent.FullName
Set-Location $root

Write-Host "=== TraceGeo local setup ==="

# 1. .env from example (never overwrites an existing .env)
if (-not (Test-Path (Join-Path $root '.env'))) {
    Copy-Item (Join-Path $root '.env.example') (Join-Path $root '.env')
    Write-Host "[setup] Copied .env.example -> .env"
} else {
    Write-Host "[setup] .env already exists -- leaving untouched"
}

# 2. Application key (only if not already set)
$envContent = Get-Content (Join-Path $root '.env') -Raw
$keyExists = ($envContent -match '(?m)^APP_KEY=.+')
if (-not $keyExists) {
    php artisan key:generate
    Write-Host "[setup] Generated APP_KEY"
} else {
    Write-Host "[setup] APP_KEY already set -- keeping it"
}

# 3. Local SQLite database
$sqlite = Join-Path $root 'database\database.sqlite'
if (-not (Test-Path $sqlite)) {
    New-Item -ItemType File $sqlite | Out-Null
    Write-Host "[setup] Created database\database.sqlite"
} else {
    Write-Host "[setup] database.sqlite already exists"
}

# 4. storage:link (idempotent)
if (-not (Test-Path (Join-Path $root 'public\storage'))) {
    php artisan storage:link
    Write-Host "[setup] Created public/storage symlink"
} else {
    Write-Host "[setup] public/storage already linked"
}

# 5. Migrations
php artisan migrate --force
Write-Host "[setup] Migrations applied"

# 6. Demo user (idempotent)
php artisan tinker --execute="if (!\App\Models\User::where('email', 'demo@example.com')->exists()) { \App\Models\User::create(['name' => 'Demo User', 'email' => 'demo@example.com', 'password' => bcrypt('password')]); echo 'demo user created (demo@example.com / password)'; } else { echo 'demo user exists (demo@example.com / password)'; }"

Write-Host ""
Write-Host "Done. Next steps:"
Write-Host "  1. Add your GEMINI_API_KEY to .env (never commit it)"
Write-Host "  2. composer dev"
Write-Host "  3. Open http://127.0.0.1:8000  -- register/login (demo@example.com / password)"
