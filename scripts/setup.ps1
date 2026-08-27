#Requires -Version 5.1
$ErrorActionPreference = 'Stop'

Set-Location (Join-Path $PSScriptRoot '..')

Write-Host '==> Checking prerequisites'
if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'Docker is required and was not found.'
}
docker compose version | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Docker Compose v2+ is required.' }

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
$healthy = $false
for ($i = 0; $i -lt 60; $i++) {
    $containerId = (docker compose ps -q postgres)
    if ($containerId) { $containerId = $containerId.Trim() }

    $status = 'starting'
    if ($containerId) {
        # `2>$null` on a native command under $ErrorActionPreference = 'Stop' turns
        # docker inspect's stderr into a terminating error (proven on this host) --
        # not merely noisy output. Scope ErrorActionPreference down to
        # SilentlyContinue for just this call so a container that isn't ready yet
        # (or vanished) degrades to "starting" like the bash twin's
        # `2>/dev/null || echo starting`, instead of aborting the whole wait loop.
        $prevEap = $ErrorActionPreference
        $ErrorActionPreference = 'SilentlyContinue'
        $inspected = docker inspect --format '{{.State.Health.Status}}' $containerId 2>$null
        $ErrorActionPreference = $prevEap
        if ($inspected) { $status = $inspected }
    }

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

    # [^\r\n]* (not .*) so a CRLF .env keeps its trailing `\r` on this line instead
    # of the substitution swallowing it and leaving this one line LF while its
    # neighbours stay CRLF.
    $updated = $envContent -replace '(?m)^APP_KEY=[^\r\n]*', "APP_KEY=$key"

    # Set-Content -Encoding utf8 under PS 5.1 (no utf8NoBOM here) writes a UTF-8
    # BOM, silently changing .env's byte format -- the bash path leaves every byte
    # but the key line untouched. Write the exact bytes instead.
    [IO.File]::WriteAllText((Resolve-Path .env), $updated, (New-Object Text.UTF8Encoding $false))

    # -replace above silently no-ops if .env had no APP_KEY= line to match at all
    # (a hand-edited or truncated .env). Don't sail into migrate/seed and a
    # "Setup complete" banner while the app is actually running on the
    # entrypoint's ephemeral, restart-losing key.
    if (-not (Select-String -Path .env -Pattern '(?m)^APP_KEY=base64:' -Quiet)) {
        throw 'ERROR: failed to write APP_KEY into .env'
    }
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
if ($LASTEXITCODE -ne 0) { throw 'failed to start the full stack' }

# docker-compose.yml maps "${APP_PORT:-8080}:80" -- read the actual value out of
# .env rather than hardcoding 8080, so the summary doesn't print a URL that
# refuses connections when the host has overridden the port.
$portLine = Get-Content .env | Where-Object { $_ -match '^APP_PORT=' } | Select-Object -Last 1
$port = if ($portLine) { ($portLine -replace '^APP_PORT=', '').Trim() } else { '' }
if ([string]::IsNullOrWhiteSpace($port)) { $port = '8080' }

Write-Host ''
Write-Host 'Setup complete.'
Write-Host ''
Write-Host "  Application   http://localhost:$port/products"
Write-Host "  API health    http://localhost:$port/api/health"
Write-Host ''
Write-Host '  Run tests     .\scripts\test.ps1'
Write-Host '  Stop          docker compose down'
Write-Host '  Logs          docker compose logs -f'
Write-Host ''
