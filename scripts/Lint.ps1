# scripts/Lint.ps1 -- parse-check every .ps1 under scripts/ using the
# Windows PowerShell parser so 5.1 syntax problems surface before CI does.
# Exits non-zero when any script fails to parse.
#
# Usage:  powershell -NoProfile -File scripts/Lint.ps1

$root = (Get-Item $PSScriptRoot).Parent.FullName
$fail = $false

foreach ($file in Get-ChildItem -Path (Join-Path $root 'scripts') -Filter '*.ps1' -Recurse) {
    $tokens = $null
    $errors = $null
    [void][System.Management.Automation.Language.Parser]::ParseFile(
        $file.FullName, [ref]$tokens, [ref]$errors)

    if ($errors -and $errors.Count -gt 0) {
        $fail = $true
        foreach ($e in $errors) {
            Write-Host ("PARSE FAIL  {0}: {1}" -f $file.Name, $e.Message)
        }
    } else {
        Write-Host ("PARSE OK    {0}" -f $file.Name)
    }
}

if ($fail) {
    Write-Host ""
    Write-Host "One or more scripts failed to parse."
    exit 1
}

exit 0
