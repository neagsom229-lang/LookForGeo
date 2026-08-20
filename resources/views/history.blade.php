<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TraceGeo — Analysis History</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --bg: #0a0a0f;
            --bg-card: #12121a;
            --bg-input: #1a1a28;
            --border: rgba(255,255,255,0.06);
            --border-light: rgba(255,255,255,0.1);
            --text: #ffffff;
            --text-secondary: #9ca3af;
            --text-muted: #6b7280;
            --accent: #8b5cf6;
            --accent-hover: #7c3aed;
            --success: #34d399;
            --radius: 12px;
            --radius-lg: 20px;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
        }

        .navbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 48px;
            border-bottom: 1px solid var(--border);
            background: rgba(10,10,15,0.85);
            backdrop-filter: blur(16px);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .navbar .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 20px;
            font-weight: 800;
            font-family: 'Space Grotesk', sans-serif;
            text-decoration: none;
            color: var(--text);
        }

        .navbar .logo .icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: linear-gradient(135deg, var(--accent), #6d28d9);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 700;
        }

        .btn {
            padding: 8px 20px;
            border-radius: 8px;
            border: none;
            font-size: 14px;
            font-weight: 600;
            transition: 0.3s;
            text-decoration: none;
            font-family: 'Inter', sans-serif;
            cursor: pointer;
        }

        .btn-ghost {
            background: transparent;
            color: var(--text-secondary);
        }
        .btn-ghost:hover { color: var(--text); }

        .btn-primary {
            background: linear-gradient(135deg, var(--accent), #6d28d9);
            color: #fff;
            box-shadow: 0 4px 16px rgba(139,92,246,0.3);
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(139,92,246,0.5);
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 40px 48px 60px;
        }

        .container h1 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .container .sub {
            color: var(--text-muted);
            font-size: 16px;
            margin-bottom: 32px;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
        }

        .card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
            transition: 0.3s;
            text-decoration: none;
            color: inherit;
        }

        .card:hover {
            border-color: var(--accent);
            transform: translateY(-4px);
            box-shadow: 0 8px 32px rgba(0,0,0,0.3);
        }

        .card .thumb {
            height: 160px;
            background: var(--bg-input);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            color: var(--text-muted);
            opacity: 0.3;
            overflow: hidden;
        }

        .card .thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .card .body {
            padding: 16px 20px 20px;
        }

        .card .body .name {
            font-size: 18px;
            font-weight: 600;
            font-family: 'Space Grotesk', sans-serif;
        }

        .card .body .location {
            font-size: 14px;
            color: var(--text-secondary);
        }

        .card .body .meta {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-top: 10px;
            font-size: 12px;
            color: var(--text-muted);
        }

        .card .body .meta .conf {
            padding: 2px 12px;
            border-radius: 12px;
            background: rgba(52,211,153,0.12);
            color: var(--success);
            font-weight: 600;
        }

        .empty {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
        }

        .empty i {
            font-size: 48px;
            opacity: 0.3;
            display: block;
            margin-bottom: 12px;
        }

        .pagination {
            display: flex;
            gap: 8px;
            justify-content: center;
            margin-top: 32px;
        }

        .pagination .page-item {
            padding: 8px 16px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: var(--bg-card);
            color: var(--text-secondary);
            transition: 0.3s;
            text-decoration: none;
        }

        .pagination .page-item:hover {
            border-color: var(--accent);
            color: var(--text);
        }

        .pagination .page-item.active {
            background: var(--accent);
            color: #fff;
            border-color: var(--accent);
        }

        @media (max-width: 768px) {
            .navbar { padding: 12px 20px; flex-wrap: wrap; }
            .container { padding: 20px; }
            .grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<nav class="navbar">
    <a href="/" class="logo">
        <span class="icon">T</span>
        TraceGeo
    </a>
    <div style="display:flex;gap:12px;">
        <a href="/" class="btn btn-ghost"><i class="fas fa-home"></i> Home</a>
        <a href="/analysis" class="btn btn-primary"><i class="fas fa-plus"></i> New Analysis</a>
    </div>
</nav>

<div class="container">
    <h1>📊 Analysis History</h1>
    <p class="sub">All your past geolocation analyses</p>

    <div class="grid">
        @forelse($analyses ?? [] as $analysis)
            <a href="/analysis/{{ $analysis->id }}" class="card">
                <div class="thumb">
                    @if($analysis->image_path)
                        <img src="{{ asset('storage/' . $analysis->image_path) }}" alt="{{ $analysis->landmark_name }}">
                    @else
                        <i class="fas fa-image"></i>
                    @endif
                </div>
                <div class="body">
                    <div class="name">{{ $analysis->landmark_name ?? 'Unknown' }}</div>
                    <div class="location">{{ $analysis->city ?? '' }}, {{ $analysis->country ?? '' }}</div>
                    <div class="meta">
                        <span class="conf">{{ $analysis->confidence ?? 0 }}%</span>
                        <span>{{ $analysis->created_at ? $analysis->created_at->diffForHumans() : '' }}</span>
                    </div>
                </div>
            </a>
        @empty
            <div class="empty">
                <i class="fas fa-inbox"></i>
                <p>No analyses found yet.<br>Upload a photo to get started!</p>
                <a href="/analysis" class="btn btn-primary" style="margin-top:16px;display:inline-block;">
                    <i class="fas fa-plus"></i> Start Analysis
                </a>
            </div>
        @endforelse
    </div>

    @if(isset($analyses) && method_exists($analyses, 'links'))
        <div class="pagination">
            {{ $analyses->links() }}
        </div>
    @endif
</div>

</body>
</html>