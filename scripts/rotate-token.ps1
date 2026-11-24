# Generates a new HEALTH_ADMIN_TOKEN (72 hex chars), copies it to the clipboard,
# and NEVER prints the token. Paste it into Render as HEALTH_ADMIN_TOKEN.
# Usage: powershell -NoProfile -ExecutionPolicy Bypass -File scripts/rotate-token.ps1

$ErrorActionPreference = 'Stop'

$bytes = New-Object byte[] 36
$rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
try {
    $rng.GetBytes($bytes)
}
finally {
    $rng.Dispose()
}

$token = -join ($bytes | ForEach-Object { $_.ToString('x2') })

if ($token.Length -ne 72) {
    throw "Unexpected token length: $($token.Length)"
}

Set-Clipboard -Value $token
Write-Host "HEALTH_ADMIN_TOKEN generated (72 hex chars) and copied to clipboard."
Write-Host "Paste it into Render Dashboard > Environment > HEALTH_ADMIN_TOKEN, then redeploy."
Write-Host "Old token will be rejected after deploy."
