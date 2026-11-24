# scripts/probe-prod.ps1 -- read-only production checks for the auth-gates
# hotfix. Sends GET requests only. Never sends credentials or request bodies.
#
# Usage:
#   powershell -NoProfile -ExecutionPolicy Bypass -File scripts/probe-prod.ps1
#
# Optional:
#   -BaseUrl https://lookforgeo.onrender.com
#   -TimeoutSec 30

[CmdletBinding()]
param(
    [string]$BaseUrl = 'https://lookforgeo.onrender.com',
    [int]$TimeoutSec = 30,
    [switch]$AllowHttp
)

$ErrorActionPreference = 'Stop'

Add-Type -AssemblyName System.Net.Http

$base = $BaseUrl.TrimEnd('/')
$parsed = $null
if (-not [System.Uri]::TryCreate($base, [System.UriKind]::Absolute, [ref]$parsed)) {
    throw "BaseUrl is not an absolute URL: $BaseUrl"
}
if ($parsed.Scheme -ne 'https' -and -not ($AllowHttp -and $parsed.Scheme -eq 'http')) {
    throw "BaseUrl must use https (use -AllowHttp only for a local mock): $BaseUrl"
}

$script:failures = New-Object System.Collections.Generic.List[string]

function Add-Pass {
    param([string]$Message)
    Write-Host ("PASS  " + $Message) -ForegroundColor Green
}

function Add-Fail {
    param([string]$Message)
    $script:failures.Add($Message) | Out-Null
    Write-Host ("FAIL  " + $Message) -ForegroundColor Red
}

$handler = New-Object -TypeName System.Net.Http.HttpClientHandler
$handler.AllowAutoRedirect = $false
$handler.UseCookies = $false
$handler.AutomaticDecompression = [System.Net.DecompressionMethods]::GZip -bor [System.Net.DecompressionMethods]::Deflate

$client = New-Object -TypeName System.Net.Http.HttpClient -ArgumentList $handler
$client.Timeout = [System.TimeSpan]::FromSeconds($TimeoutSec)
$client.DefaultRequestHeaders.UserAgent.ParseAdd('TraceGeo-Probe/1.0')

function Invoke-Get {
    param([string]$Path)

    $uri = $base + $Path
    $request = New-Object -TypeName System.Net.Http.HttpRequestMessage -ArgumentList @([System.Net.Http.HttpMethod]::Get, $uri)
    $response = $null

    try {
        $response = $client.SendAsync($request).GetAwaiter().GetResult()
        $body = $response.Content.ReadAsStringAsync().GetAwaiter().GetResult()

        $csp = ''
        $cspValues = $null
        if ($response.Headers.TryGetValues('Content-Security-Policy', [ref]$cspValues)) {
            $csp = ($cspValues -join '; ')
        }

        $location = ''
        if ($null -ne $response.Headers.Location) {
            $location = $response.Headers.Location.ToString()
        }

        $contentType = ''
        if ($null -ne $response.Content.Headers.ContentType) {
            $contentType = $response.Content.Headers.ContentType.ToString()
        }

        return [pscustomobject]@{
            Path        = $Path
            Status      = [int]$response.StatusCode
            Body        = $body
            ContentType = $contentType
            Csp         = $csp
            Location    = $location
        }
    }
    catch {
        $message = $_.Exception.Message
        if ($_.Exception.InnerException) {
            $message = $_.Exception.InnerException.Message
        }
        throw ("GET " + $uri + " failed: " + $message)
    }
    finally {
        if ($null -ne $response) {
            $response.Dispose()
        }
        $request.Dispose()
    }
}

function Test-CommonBody {
    param(
        [string]$Path,
        [string]$Body
    )

    $sample = $Body
    if ($sample.Length -gt 262144) {
        $sample = $sample.Substring(0, 262144)
    }

    $secretPatterns = @(
        'AIza[0-9A-Za-z_\-]{20,}',
        'AQ\.[0-9A-Za-z_\-]{20,}',
        'sk-[A-Za-z0-9]{20,}',
        '-----BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY-----',
        'postgres(ql)?://[^"\s]+:[^"\s]+@',
        'mysql://[^"\s]+:[^"\s]+@',
        'APP_KEY=base64:'
    )

    foreach ($pattern in $secretPatterns) {
        if ($sample -match $pattern) {
            Add-Fail ($Path + " body contains a secret-looking value (redacted)")
            break
        }
    }

    $traceMarkers = @(
        'Whoops',
        'Stack trace',
        'vendor/laravel',
        'Illuminate\\',
        'SQLSTATE',
        'Fatal error'
    )

    foreach ($marker in $traceMarkers) {
        if ($sample -match [regex]::Escape($marker)) {
            Add-Fail ($Path + " body exposes a stack trace or framework path")
            break
        }
    }
}

try {
    Write-Host ("Probing " + $base + " (GET only)")

    $live = Invoke-Get '/up'
    if ($live.Status -eq 200) {
        Add-Pass 'GET /up -> 200'
    }
    else {
        Add-Fail ("GET /up -> " + $live.Status + ' (expected 200)')
    }

    $health = Invoke-Get '/api/health'
    if ($health.Status -eq 200 -or $health.Status -eq 503) {
        Add-Pass ("GET /api/health -> " + $health.Status)
    }
    else {
        Add-Fail ("GET /api/health -> " + $health.Status + ' (expected 200 or 503)')
    }

    if ($health.ContentType -match 'application/json') {
        Add-Pass 'GET /api/health returns JSON'
    }
    else {
        Add-Fail ("GET /api/health content type is '" + $health.ContentType + "' (expected application/json)")
    }

    $healthJson = $null
    try {
        $healthJson = $health.Body | ConvertFrom-Json
    }
    catch {
        Add-Fail 'GET /api/health body is not valid JSON'
    }

    if ($null -ne $healthJson -and ($healthJson.status -eq 'operational' -or $healthJson.status -eq 'degraded')) {
        Add-Pass ("GET /api/health reports status=" + $healthJson.status)
    }
    else {
        Add-Fail 'GET /api/health does not report status operational|degraded'
    }

    if ($health.Csp) {
        Add-Pass 'Content-Security-Policy header is present'
    }
    else {
        Add-Fail 'Content-Security-Policy header is missing'
    }

    $search = Invoke-Get '/api/search'
    if ($search.Status -eq 401) {
        Add-Pass 'GET /api/search (anonymous) -> 401'
    }
    else {
        Add-Fail ("GET /api/search (anonymous) -> " + $search.Status + ' (expected 401)')
    }

    if ($search.Location) {
        Add-Fail ("GET /api/search redirected to '" + $search.Location + "' (expected 401 JSON)")
    }
    else {
        Add-Pass 'GET /api/search does not redirect'
    }

    if ($search.ContentType -match 'application/json') {
        Add-Pass 'GET /api/search returns JSON'
    }
    else {
        Add-Fail ("GET /api/search content type is '" + $search.ContentType + "' (expected application/json)")
    }

    if ($search.Body -match '"message"\s*:\s*"Unauthenticated\."') {
        Add-Pass 'GET /api/search returns the Unauthenticated message'
    }
    else {
        Add-Fail 'GET /api/search does not return the expected Unauthenticated message'
    }

    # An unauthenticated 4xx JSON error must not leak debug structure.
    if ($search.Status -ge 400 -and $search.Status -lt 500 -and $search.ContentType -match 'application/json') {
        try {
            $errorJson = $search.Body | ConvertFrom-Json
            $leakyKeys = @('trace', 'exception', 'file') | Where-Object {
                $errorJson.PSObject.Properties.Name -contains $_
            }
            if ($leakyKeys.Count -eq 0) {
                Add-Pass 'GET /api/search 4xx JSON has no trace/exception/file keys'
            } else {
                Add-Fail ("GET /api/search 4xx JSON leaks keys: " + ($leakyKeys -join ', '))
            }
        } catch {
            Add-Fail 'GET /api/search 4xx body is not parseable JSON'
        }
    }

    $history = Invoke-Get '/api/history'
    if ($history.Status -eq 401) {
        Add-Pass 'GET /api/history (anonymous) -> 401'
    }
    else {
        Add-Fail ("GET /api/history (anonymous) -> " + $history.Status + ' (expected 401)')
    }

    $testApis = Invoke-Get '/api/test-apis'
    if ($testApis.Status -eq 404) {
        Add-Pass 'GET /api/test-apis -> 404'
    }
    else {
        Add-Fail ("GET /api/test-apis -> " + $testApis.Status + ' (expected 404)')
    }

    Test-CommonBody '/api/health' $health.Body
    Test-CommonBody '/api/search' $search.Body
    Test-CommonBody '/api/history' $history.Body
    Test-CommonBody '/api/test-apis' $testApis.Body
}
catch {
    Add-Fail $_.Exception.Message
}
finally {
    $client.Dispose()
    $handler.Dispose()
}

Write-Host ''
if ($script:failures.Count -gt 0) {
    Write-Host ("Probe FAILED: " + $script:failures.Count + ' check(s) failed.') -ForegroundColor Red
    exit 1
}

Write-Host 'Probe PASSED: all checks green.' -ForegroundColor Green
exit 0
