#Requires -Version 5.1
$ErrorActionPreference = 'Stop'

Set-Location (Join-Path $PSScriptRoot '..')

Write-Host '==> Checking prerequisites'
if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'Docker is required and was not found.'
}

if (-not (Test-Path .env)) {
    Copy-Item .env.example .env
    Write-Host '==> Created .env from .env.example'
}

Write-Host '==> Building images'
docker compose build
if ($LASTEXITCODE -ne 0) { throw 'docker compose build failed' }

Write-Host '==> Starting postgres'
docker compose up -d postgres
if ($LASTEXITCODE -ne 0) { throw 'failed to start postgres' }

Write-Host '==> Waiting for postgres to become healthy'
$containerId = (docker compose ps -q postgres).Trim()
$healthy = $false
for ($i = 0; $i -lt 60; $i++) {
    $status = (docker inspect --format '{{.State.Health.Status}}' $containerId)
    if ($status -eq 'healthy') { $healthy = $true; break }
    Start-Sleep -Seconds 2
}
if (-not $healthy) {
    throw 'postgres did not become healthy. Check: docker compose logs postgres'
}

# NOTE: docker compose run executes the php-fpm image's ENTRYPOINT before the
# given command. That entrypoint (Task 17) already waits for postgres, clears
# bootstrap/cache, installs composer deps when vendor/ is missing or stale,
# runs `migrate --force`, and conditionally seeds via `app:seed-if-empty`. So
# by the time the explicit "composer install" below finishes, the database is
# already migrated and seeded. `run` (not `exec`) is still correct here: the
# php-fpm service is not started as a long-running container yet at this
# point in the script.
Write-Host '==> Installing backend dependencies'
docker compose run --rm php-fpm composer install --no-interaction
if ($LASTEXITCODE -ne 0) { throw 'composer install failed' }

$envContent = Get-Content .env -Raw
if ($envContent -notmatch '(?m)^APP_KEY=base64:') {
    Write-Host '==> Generating APP_KEY'
    # Extract ONLY the key -- the entrypoint runs first and its output lands here too.
    $key = (docker compose run --rm -T php-fpm php artisan key:generate --show) |
        Where-Object { $_ -match '^base64:[A-Za-z0-9+/=]+$' } | Select-Object -First 1
    if (-not $key) { throw 'could not extract an APP_KEY from key:generate output' }
    $updated = $envContent -replace '(?m)^APP_KEY=.*$', "APP_KEY=$key"
    Set-Content -Path .env -Value $updated -Encoding utf8 -NoNewline
}

# Deliberately NOT `migrate --force --seed`: the entrypoint invoked above
# already seeded an empty catalog via app:seed-if-empty. DatabaseSeeder
# creates rows via factories and is not idempotent, so `--seed` here would
# unconditionally append a second batch of categories/products on every run,
# breaking the idempotency this script promises. `migrate --force` plus the
# same seed-if-empty command the entrypoint uses keeps re-runs safe.
Write-Host '==> Running migrations and seeding'
docker compose run --rm php-fpm php artisan migrate --force
if ($LASTEXITCODE -ne 0) { throw 'migration failed' }
docker compose run --rm php-fpm php artisan app:seed-if-empty
if ($LASTEXITCODE -ne 0) { throw 'seeding failed' }

Write-Host '==> Installing frontend dependencies'
docker compose run --rm nuxt npm ci
if ($LASTEXITCODE -ne 0) { throw 'npm ci failed' }

Write-Host '==> Starting the full stack'
docker compose up -d

Write-Host ''
Write-Host 'Setup complete.'
Write-Host ''
Write-Host '  Application   http://localhost:8080/products'
Write-Host '  API health    http://localhost:8080/api/health'
Write-Host ''
Write-Host '  Run tests     .\scripts\test.ps1'
Write-Host '  Stop          docker compose down'
Write-Host '  Logs          docker compose logs -f'
Write-Host ''
