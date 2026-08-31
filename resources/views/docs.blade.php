<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TraceGeo - API Documentation</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <link
        href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;600&display=swap"
        rel="stylesheet">

    <style>
    :root {
        --bg: #0a0d12;
        --bg-card: #10141b;
        --bg-code: #11151c;
        --border: rgba(233, 238, 245, 0.08);
        --border-light: rgba(233, 238, 245, 0.15);
        --text: #edf1f6;
        --text-secondary: #93a0af;
        --text-muted: #5c6672;
        --accent: #c98a46;
        --accent-soft: rgba(201, 138, 70, 0.14);
        --success: #5fae82;
        --info: #5b9bd1;
        --danger: #d1685a;
        --warn: #d1a955;
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: 'Inter', sans-serif;
        background: var(--bg);
        color: var(--text);
        min-height: 100vh;
    }

    a {
        text-decoration: none;
        color: inherit;
    }

    button {
        cursor: pointer;
        font-family: 'Inter', sans-serif;
    }

    /* Navbar */
    .navbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 16px 48px;
        border-bottom: 1px solid var(--border);
        background: rgba(10, 13, 18, 0.85);
        backdrop-filter: blur(16px);
        position: sticky;
        top: 0;
        z-index: 100;
    }

    .logo {
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 19px;
        font-weight: 700;
        font-family: 'Fraunces', serif;
    }

    .logo .icon {
        width: 32px;
        height: 32px;
        border-radius: 7px;
        background: linear-gradient(155deg, var(--accent), #a86a2e);
        display: flex;
        align-items: center;
        justify-content: center;
        color: #14100a;
        font-family: 'JetBrains Mono', monospace;
    }

    .nav-links {
        display: flex;
        gap: 30px;
        list-style: none;
    }

    .nav-links a {
        color: var(--text-secondary);
        font-size: 13px;
        font-weight: 500;
        transition: 0.25s;
    }

    .nav-links a:hover {
        color: var(--text);
    }

    .nav-links a.active {
        color: var(--accent);
    }

    /* Layout */
    .container {
        display: flex;
        max-width: 1400px;
        margin: 0 auto;
        padding: 20px 48px 60px;
        gap: 40px;
    }

    /* Sidebar */
    .sidebar {
        width: 260px;
        flex-shrink: 0;
        position: sticky;
        top: 100px;
        height: fit-content;
    }

    .sidebar h3 {
        font-size: 11px;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.08em;
        margin: 24px 0 12px;
        font-weight: 600;
    }

    .sidebar a {
        display: flex;
        align-items: center;
        gap: 10px;
        color: var(--text-secondary);
        padding: 8px 12px;
        border-radius: 8px;
        font-size: 14px;
        margin-bottom: 2px;
        transition: 0.2s;
    }

    .sidebar a:hover {
        background: rgba(255, 255, 255, 0.05);
        color: var(--text);
    }

    .sidebar a.active {
        background: var(--accent-soft);
        color: var(--accent);
    }

    .sidebar a i {
        width: 18px;
        text-align: center;
        font-size: 13px;
    }

    .badge {
        font-family: 'JetBrains Mono', monospace;
        font-size: 10px;
        padding: 2px 6px;
        border-radius: 4px;
        font-weight: 600;
        margin-left: auto;
    }

    .badge.post {
        background: rgba(95, 174, 130, 0.2);
        color: var(--success);
    }

    .badge.get {
        background: rgba(91, 155, 209, 0.2);
        color: var(--info);
    }

    /* Main Content */
    .main-content {
        flex: 1;
        min-width: 0;
    }

    .section {
        margin-bottom: 60px;
        scroll-margin-top: 120px;
    }

    h1 {
        font-family: 'Fraunces', serif;
        font-size: 36px;
        margin-bottom: 16px;
    }

    h2 {
        font-family: 'Fraunces', serif;
        font-size: 24px;
        margin-bottom: 16px;
        border-bottom: 1px solid var(--border);
        padding-bottom: 12px;
    }

    p {
        color: var(--text-secondary);
        line-height: 1.7;
        margin-bottom: 16px;
    }

    /* Code Block */
    .code-block {
        background: var(--bg-code);
        border: 1px solid var(--border);
        border-radius: 8px;
        padding: 16px 20px;
        font-family: 'JetBrains Mono', monospace;
        font-size: 13px;
        position: relative;
        margin-bottom: 20px;
        overflow-x: auto;
    }

    .code-block .copy-btn {
        position: absolute;
        top: 10px;
        right: 10px;
        background: rgba(255, 255, 255, 0.05);
        border: 1px solid var(--border);
        color: var(--text-secondary);
        border-radius: 4px;
        padding: 4px 8px;
        font-size: 12px;
        transition: 0.2s;
    }

    .code-block .copy-btn:hover {
        color: var(--text);
        border-color: var(--accent);
    }

    .info-card {
        background: var(--bg-card);
        border: 1px solid var(--border);
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 16px;
    }

    .info-card h4 {
        font-size: 15px;
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .info-card h4 i {
        color: var(--accent);
    }

    .info-card ul {
        margin-left: 20px;
        color: var(--text-secondary);
        font-size: 14px;
        line-height: 1.8;
    }

    /* Responsive */
    @media (max-width: 900px) {
        .navbar {
            padding: 12px 20px;
        }

        .nav-links {
            display: none;
        }

        .container {
            flex-direction: column;
            padding: 20px;
        }

        .sidebar {
            width: 100%;
            position: static;
            display: flex;
            overflow-x: auto;
            gap: 10px;
        }

        .sidebar h3,
        .sidebar a span {
            display: none;
        }

        .sidebar a {
            white-space: nowrap;
        }

        .main-content {
            width: 100%;
        }
    }
    </style>
</head>

<body>

    <nav class="navbar">
        <a href="/" class="logo">
            <span class="icon">T</span> TraceGeo
        </a>
        <ul class="nav-links">
            <li><a href="/" class="active">Home</a></li>
            <li><a href="/docs">Docs</a></li>
            <li><a href="/history">History</a></li>
        </ul>
        <div class="nav-actions">
            @auth
            <span style="color:var(--text-secondary); font-size:14px; margin-right:16px;">
                <i class="fas fa-user"></i> {{ Auth::user()->name }}
            </span>
            <form method="POST" action="/web-logout" style="display:inline;">
                @csrf
                <button type="submit"
                    style="background: transparent; border:none; color:var(--text-secondary); font-size:14px; cursor:pointer;">
                    Logout
                </button>
            </form>
            @else
            <a href="/login" style="margin-right:16px; color:var(--text-secondary); font-size:14px;">Log In</a>
            <a href="/register"
                style="background: var(--accent); color: #14100a; padding: 9px 20px; border-radius: 7px; font-weight: 600; font-size: 14px;">Sign
                Up</a>
            @endauth
        </div>
    </nav>

    <div class="container">
        <!-- SIDEBAR -->
        <aside class="sidebar">
            <h3>GETTING STARTED</h3>
            <a href="#overview"><i class="fas fa-info-circle"></i><span>Overview</span></a>
            <a href="#authentication" class="active"><i class="fas fa-lock"></i><span>Authentication</span></a>
            <a href="#api-keys"><i class="fas fa-key"></i><span>API Keys</span></a>

            <h3>ENDPOINTS</h3>
            <a href="#analyze"><i class="fas fa-crosshairs"></i><span>Analyze</span><span
                    class="badge post">POST</span></a>
            <a href="#history"><i class="fas fa-history"></i><span>History</span><span class="badge get">GET</span></a>
            <a href="#health"><i class="fas fa-heartbeat"></i><span>Health</span><span class="badge get">GET</span></a>

            <h3>REFERENCE</h3>
            <a href="#errors"><i class="fas fa-exclamation-triangle"></i><span>Error Codes</span></a>
            <a href="#rate"><i class="fas fa-tachometer-alt"></i><span>Rate Limits</span></a>
        </aside>

        <!-- MAIN CONTENT -->
        <main class="main-content">

            <!-- Overview -->
            <div id="overview" class="section">
                <h1>API Documentation</h1>
                <p>The TraceGeo API allows you to programmatically analyze images and retrieve geolocation data. This
                    RESTful API returns standard JSON responses and uses HTTP status codes for error handling.</p>
            </div>

            <!-- Authentication -->
            <div id="authentication" class="section">
                <h2>Authentication</h2>
                <p>Most endpoints require authentication via Bearer token. Include your API key in the Authorization
                    header.</p>

                <div class="code-block">
                    <button class="copy-btn" onclick="copyCode(this)"><i class="fas fa-copy"></i></button>
                    <strong>HTTP HEADER</strong><br>
                    Authorization: Bearer YOUR_API_KEY
                </div>

                <div class="info-card">
                    <h4><i class="fas fa-circle-info"></i> Auth Methods</h4>
                    <ul>
                        <li><strong>Bearer Token</strong> — Use your API key in the <code>Authorization</code> header.
                            Works for all endpoints.</li>
                        <li><strong>Session Cookie</strong> — Browser sessions from the web UI are also accepted for all
                            endpoints.</li>
                    </ul>
                </div>
            </div>

            <!-- API Keys -->
            <div id="api-keys" class="section">
                <h2>API Keys</h2>
                <p>Create and manage your API keys for programmatic access. To generate a key locally, run the following
                    command in your terminal:</p>
                <div class="code-block">
                    <button class="copy-btn" onclick="copyCode(this)"><i class="fas fa-copy"></i></button>
                    php artisan tinker<br>
                    > \App\Models\User::first()->createToken('My API Key')->plainTextToken;
                </div>
            </div>

            <!-- Analyze Endpoint -->
            <div id="analyze" class="section">
                <h2><span class="badge post" style="margin-left:0;">POST</span> /api/analyze</h2>
                <p>Uploads an image and starts the geolocation analysis. This is an asynchronous endpoint. It returns an
                    <code>analysis_id</code> which you can poll to retrieve the final result.
                </p>
                <p><strong>Request Body:</strong> <code>multipart/form-data</code> with an <code>image</code> file
                    field.</p>
                <div class="code-block">
                    <button class="copy-btn" onclick="copyCode(this)"><i class="fas fa-copy"></i></button>
                    curl -X POST https://lookforgeo.onrender.com/api/analyze \<br>
                    &nbsp;&nbsp;-H "Authorization: Bearer YOUR_API_KEY" \<br>
                    &nbsp;&nbsp;-F "image=@/path/to/photo.jpg"
                </div>
                <p><strong>Success Response (202 Accepted):</strong></p>
                <div class="code-block">
                    { "success": true, "message": "Analysis started. Poll this ID to get results.", "analysis_id": 123 }
                </div>
            </div>

            <!-- History Endpoint -->
            <div id="history" class="section">
                <h2><span class="badge get" style="margin-left:0;">GET</span> /api/history</h2>
                <p>Returns a paginated list of all completed analyses for the authenticated user.</p>
                <p><strong>Response:</strong></p>
                <div class="code-block">
                    { "success": true, "data": [ { "id": 1, "landmark": "Eiffel Tower", "confidence": 95, "coords": {
                    "lat": 48.8584, "lng": 2.2945 } } ], "pagination": { "current_page": 1, "last_page": 1 } }
                </div>
            </div>

            <!-- Health Endpoint -->
            <div id="health" class="section">
                <h2><span class="badge get" style="margin-left:0;">GET</span> /api/health</h2>
                <p>Checks if the API is operational. No authentication required.</p>
                <div class="code-block">
                    { "success": true, "status": "operational", "version": "1.0.0", "timestamp":
                    "2026-08-31T12:00:00.000000Z" }
                </div>
            </div>

            <!-- Errors -->
            <div id="errors" class="section">
                <h2>Error Codes</h2>
                <p>The API uses standard HTTP status codes.</p>
                <div class="info-card">
                    <ul>
                        <li><strong>401 Unauthorized</strong> — Missing or invalid API key.</li>
                        <li><strong>422 Unprocessable Entity</strong> — Validation failed (e.g., file is too large or
                            not an image).</li>
                        <li><strong>429 Too Many Requests</strong> — Rate limit exceeded.</li>
                    </ul>
                </div>
            </div>

            <!-- Rate Limits -->
            <div id="rate" class="section">
                <h2>Rate Limits</h2>
                <p>To ensure fair usage and server stability, API requests are throttled.</p>
                <div class="info-card">
                    <ul>
                        <li><strong>60 requests per minute</strong> per API key.</li>
                    </ul>
                </div>
            </div>

        </main>
    </div>

    <script>
    // Copy button function
    function copyCode(btn) {
        const block = btn.parentElement;
        const code = block.innerText.replace('Copy', '').trim();
        navigator.clipboard.writeText(code).then(() => {
            btn.innerHTML = '<i class="fas fa-check"></i>';
            setTimeout(() => btn.innerHTML = '<i class="fas fa-copy"></i>', 2000);
        });
    }

    // Sidebar Active State on Scroll
    window.addEventListener('scroll', () => {
        const sections = document.querySelectorAll('.section');
        const navLinks = document.querySelectorAll('.sidebar a');
        let current = 'overview';

        sections.forEach(section => {
            const top = section.offsetTop - 120;
            if (window.scrollY >= top) {
                current = section.getAttribute('id');
            }
        });

        navLinks.forEach(link => {
            link.classList.remove('active');
            if (link.getAttribute('href') === '#' + current) {
                link.classList.add('active');
            }
        });
    });
    </script>
</body>

</html>