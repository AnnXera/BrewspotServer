# Builds the two zips to upload to Hostinger (Single plan, no SSH):
#   deploy\hostinger\dist\brewspot-api.zip  -> extract into /home/<user>/brewspot-api
#   deploy\hostinger\dist\public_html.zip   -> extract into the site's public_html
#
# Usage (from the server folder):  powershell -ExecutionPolicy Bypass -File deploy\hostinger\build.ps1

$ErrorActionPreference = 'Stop'

$server = (Resolve-Path "$PSScriptRoot\..\..").Path
$client = (Resolve-Path "$server\..\client").Path
$dist   = "$PSScriptRoot\dist"
$stage  = Join-Path $env:TEMP 'brewspot-deploy'

if (Test-Path $stage) { Remove-Item -Recurse -Force $stage }
New-Item -ItemType Directory -Force "$stage\api", "$stage\public_html", $dist | Out-Null

# --- Laravel: working tree files git doesn't ignore (no vendor, .env, logs, uploads) ---
Write-Host 'Copying Laravel files...'
$files = git -C $server ls-files --cached --others --exclude-standard | Where-Object { $_ -notlike 'deploy/*' -and $_ -notlike '.claude/*' }
foreach ($f in $files) {
    $src = Join-Path $server $f
    if (-not (Test-Path $src -PathType Leaf)) { continue }
    $dest = Join-Path "$stage\api" $f
    New-Item -ItemType Directory -Force (Split-Path $dest) | Out-Null
    Copy-Item $src $dest
}

Write-Host 'Installing production Composer dependencies...'
composer install --no-dev --optimize-autoloader --no-interaction --working-dir "$stage\api"
if ($LASTEXITCODE -ne 0) { throw 'composer install failed' }

# --- Nuxt: static SPA build pointing at /api ---
Write-Host 'Building Nuxt client...'
Push-Location $client
try {
    npm run generate:hostinger
    if ($LASTEXITCODE -ne 0) { throw 'nuxt generate failed' }
} finally {
    Pop-Location
}
Copy-Item -Recurse "$client\.output\public\*" "$stage\public_html"
New-Item -ItemType Directory -Force "$stage\public_html\backend" | Out-Null
Copy-Item "$PSScriptRoot\backend\index.php" "$stage\public_html\backend\index.php"

# --- Zip ---
Remove-Item -Force "$dist\*.zip" -ErrorAction SilentlyContinue
tar.exe -a -c -f "$dist\brewspot-api.zip" -C "$stage\api" .
tar.exe -a -c -f "$dist\public_html.zip" -C "$stage\public_html" .

Write-Host "Done:`n  $dist\brewspot-api.zip`n  $dist\public_html.zip"
