#Requires -Version 5.1
$ErrorActionPreference = 'Stop'

Set-Location (Join-Path $PSScriptRoot '..')

# docker compose exec (unlike run) bypasses ENTRYPOINT and requires the
# target service to already be running, so fail loudly here instead of
# letting `exec` produce a cryptic "service is not running" error.
Write-Host '==> Checking that the dev stack is running'
# `2>$null` on a native command under $ErrorActionPreference = 'Stop' turns
# stderr into a terminating error (proven on this host) rather than just
# suppressing it -- so the single most likely reason to run this guard
# (Docker Desktop not running) would abort with a raw Docker exception
# instead of ever reaching the friendly throw below. Scope
# ErrorActionPreference down to SilentlyContinue for just this call so it
# degrades to an empty $running, like the bash twin's `2>/dev/null || true`.
$prevEap = $ErrorActionPreference
$ErrorActionPreference = 'SilentlyContinue'
$running = (docker compose ps --status running --services 2>$null)
$ErrorActionPreference = $prevEap
foreach ($svc in @('php-fpm', 'nuxt')) {
    if (-not ($running -contains $svc)) {
        throw "The '$svc' service is not running. Start the stack first: .\scripts\setup.ps1 or docker compose up -d"
    }
}

Write-Host '==> Backend (Pest)'
docker compose exec -T php-fpm ./vendor/bin/pest
if ($LASTEXITCODE -ne 0) { throw 'backend suite failed' }

Write-Host '==> Frontend (Vitest)'
docker compose exec -T nuxt npm run test
if ($LASTEXITCODE -ne 0) { throw 'frontend suite failed' }

Write-Host '==> All suites passed'
