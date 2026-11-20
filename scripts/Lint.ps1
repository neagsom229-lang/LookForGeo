# scripts/Lint.ps1 -- parse-check every .ps1 under scripts/ using the
# Windows PowerShell parser, and reject non-ASCII bytes so PowerShell 5.1
# does not misread comments or strings.
#
# Usage:  powershell -NoProfile -File scripts/Lint.ps1
# Exits non-zero when any script fails to parse or contains non-ASCII bytes.

$root = (Get-Item $PSScriptRoot).Parent.FullName
$fail = $false

foreach ($file in Get-ChildItem -Path (Join-Path $root 'scripts') -Filter '*.ps1' -Recurse) {
    $bytes = [System.IO.File]::ReadAllBytes($file.FullName)
    $nonAscii = $false

    foreach ($b in $bytes) {
        if ($b -gt 127) {
            $nonAscii = $true
            break
        }
    }

    if ($nonAscii) {
        $fail = $true
        Write-Host ("ASCII FAIL  {0}: contains non-ASCII bytes" -f $file.Name)
    }
    else {
        Write-Host ("ASCII OK    {0}" -f $file.Name)
    }

    $tokens = $null
    $errors = $null
    [void][System.Management.Automation.Language.Parser]::ParseFile(
        $file.FullName, [ref]$tokens, [ref]$errors)

    if ($errors -and $errors.Count -gt 0) {
        $fail = $true
        foreach ($e in $errors) {
            Write-Host ("PARSE FAIL  {0}: {1}" -f $file.Name, $e.Message)
        }
    }
    else {
        Write-Host ("PARSE OK    {0}" -f $file.Name)
    }
}

if ($fail) {
    Write-Host ''
    Write-Host 'One or more scripts failed validation.'
    exit 1
}

exit 0
