<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TraceGeo — Analysis Details</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    
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

        .detail-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            overflow: hidden;
        }

        .detail-card .image {
            height: 300px;
            background: var(--bg-input);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 64px;
            color: var(--text-muted);
            opacity: 0.3;
            overflow: hidden;
        }

        .detail-card .image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .detail-card .body {
            padding: 32px 40px;
        }

        .detail-card .body .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 4px 14px;
            border-radius: 20px;
            background: rgba(52,211,153,0.12);
            border: 1px solid rgba(52,211,153,0.15);
            color: var(--success);
            font-size: 12px;
            font-weight: 600;
        }

        .detail-card .body h1 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 28px;
            font-weight: 700;
            margin: 8px 0 4px;
        }

        .detail-card .body .coords {
            font-family: 'JetBrains Mono', monospace;
            font-size: 14px;
            color: var(--text-muted);
        }

        .detail-card .body .desc {
            margin-top: 16px;
            padding: 16px 20px;
            background: var(--bg-input);
            border-radius: var(--radius);
            border-left: 3px solid var(--accent);
            color: var(--text-secondary);
            line-height: 1.8;
        }

        .detail-card .body .tags {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 16px;
        }

        .detail-card .body .tags .tag {
            padding: 4px 14px;
            border-radius: 16px;
            background: var(--bg-input);
            border: 1px solid var(--border);
            font-size: 12px;
            color: var(--text-secondary);
        }

        #map {
            width: 100%;
            height: 400px;
            border-radius: var(--radius);
            margin-top: 20px;
            border: 1px solid var(--border);
        }

        .actions {
            display: flex;
            gap: 12px;
            margin-top: 20px;
            flex-wrap: wrap;
        }

        @media (max-width: 768px) {
            .navbar { padding: 12px 20px; flex-wrap: wrap; }
            .container { padding: 20px; }
            .detail-card .body { padding: 20px; }
            .detail-card .image { height: 200px; }
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
    <div class="detail-card">
        <div class="image">
            @if($analysis->image_path)
                <img src="{{ asset('storage/' . $analysis->image_path) }}" alt="{{ $analysis->landmark_name }}">
            @else
                <i class="fas fa-image"></i>
            @endif
        </div>
        <div class="body">
            <div class="badge">✅ {{ $analysis->confidence ?? 0 }}% Confidence</div>
            <h1>{{ $analysis->landmark_name ?? 'Unknown Location' }}</h1>
            <div class="coords">{{ $analysis->latitude ?? '—' }}, {{ $analysis->longitude ?? '—' }}</div>

            <div class="desc">
                {{ $analysis->description ?? 'No description available.' }}
            </div>

            @if($analysis->tags)
                <div class="tags">
                    @foreach($analysis->tags as $tag)
                        <span class="tag">{{ $tag }}</span>
                    @endforeach
                </div>
            @endif

            <div id="map"></div>

            <div class="actions">
                <a href="https://www.google.com/maps?q={{ $analysis->latitude }},{{ $analysis->longitude }}" target="_blank" class="btn btn-primary">
                    <i class="fas fa-map"></i> Open in Google Maps
                </a>
                <a href="https://earth.google.com/web/@{{ $analysis->latitude }},{{ $analysis->longitude }},0a,500d" target="_blank" class="btn btn-ghost" style="border:1px solid var(--border);">
                    <i class="fas fa-globe-americas"></i> 3D Globe
                </a>
                <button class="btn btn-ghost" onclick="window.history.back()" style="border:1px solid var(--border);">
                    <i class="fas fa-arrow-left"></i> Back
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    const lat = {{ $analysis->latitude ?? 10.4837 }};
    const lng = {{ $analysis->longitude ?? 104.2942 }};
    const name = "{{ $analysis->landmark_name ?? 'Location' }}";

    const map = L.map('map').setView([lat, lng], 15);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors',
        maxZoom: 19,
    }).addTo(map);

    const customIcon = L.divIcon({
        html: `<div style="
            background: linear-gradient(135deg, #8b5cf6, #6d28d9);
            border-radius: 50%;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 3px solid white;
            box-shadow: 0 4px 20px rgba(139,92,246,0.5);
            font-size: 16px;
        ">📍</div>`,
        className: '',
        iconSize: [36, 36],
        iconAnchor: [18, 18],
    });

    L.marker([lat, lng], { icon: customIcon })
        .addTo(map)
        .bindPopup(`<strong>${name}</strong><br>${lat.toFixed(6)}° N, ${lng.toFixed(6)}° E`)
        .openPopup();

    setTimeout(() => map.invalidateSize(), 500);
</script>

</body>
</html>