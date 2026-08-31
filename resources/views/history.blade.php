<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TraceGeo — Analysis History</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Space+Grotesk:wght@400;500;600;700&display=swap"
        rel="stylesheet">

    <style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    :root {
        --bg: #0a0a0f;
        --bg-card: #12121a;
        --bg-input: #1a1a28;
        --border: rgba(255, 255, 255, 0.06);
        --border-light: rgba(255, 255, 255, 0.1);
        --text: #ffffff;
        --text-secondary: #9ca3af;
        --text-muted: #6b7280;
        --accent: #8b5cf6;
        --accent-soft: rgba(139, 92, 246, 0.12);
        --success: #34d399;
        --danger: #f87171;
        --warning: #fbbf24;
        --radius: 12px;
        --radius-lg: 20px;
    }

    body {
        font-family: 'Inter', sans-serif;
        background: var(--bg);
        color: var(--text);
        min-height: 100vh;
    }

    /* ===== NAVBAR ===== */
    .navbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 14px 48px;
        border-bottom: 1px solid var(--border);
        background: rgba(10, 10, 15, 0.85);
        backdrop-filter: blur(16px);
        position: sticky;
        top: 0;
        z-index: 100;
        animation: slideDown 0.5s cubic-bezier(0.16, 1, 0.3, 1) both;
    }

    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateY(-20px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
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
        transition: transform 0.3s ease;
    }

    .navbar .logo:hover {
        transform: scale(1.02);
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
        color: #fff;
    }

    .navbar .nav-actions {
        display: flex;
        gap: 12px;
        align-items: center;
    }

    .btn {
        padding: 8px 20px;
        border-radius: 8px;
        border: none;
        font-size: 14px;
        font-weight: 600;
        transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        text-decoration: none;
        font-family: 'Inter', sans-serif;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .btn-ghost {
        background: transparent;
        color: var(--text-secondary);
    }

    .btn-ghost:hover {
        color: var(--text);
        background: rgba(255, 255, 255, 0.05);
    }

    .btn-primary {
        background: linear-gradient(135deg, var(--accent), #6d28d9);
        color: #fff;
        box-shadow: 0 4px 16px rgba(139, 92, 246, 0.3);
    }

    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 30px rgba(139, 92, 246, 0.5);
    }

    /* ===== CONTAINER ===== */
    .container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 40px 48px 60px;
        animation: fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) 0.1s both;
    }

    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(24px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 32px;
        flex-wrap: wrap;
        gap: 16px;
    }

    .header h1 {
        font-family: 'Space Grotesk', sans-serif;
        font-size: 32px;
        font-weight: 700;
        background: linear-gradient(135deg, #fff, var(--accent));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    .header .sub {
        color: var(--text-muted);
        font-size: 16px;
        margin-top: 4px;
        -webkit-text-fill-color: var(--text-muted);
    }

    .header-actions {
        display: flex;
        gap: 12px;
        align-items: center;
        flex-wrap: wrap;
    }

    .filter-group {
        display: flex;
        gap: 8px;
        background: var(--bg-card);
        padding: 4px;
        border-radius: var(--radius);
        border: 1px solid var(--border);
    }

    .filter-btn {
        padding: 6px 14px;
        border-radius: 8px;
        border: none;
        background: transparent;
        color: var(--text-muted);
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.25s ease;
        font-family: 'Inter', sans-serif;
    }

    .filter-btn:hover {
        color: var(--text);
    }

    .filter-btn.active {
        background: var(--accent);
        color: #fff;
        box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
    }

    /* ===== GRID ===== */
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
        transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        text-decoration: none;
        color: inherit;
        position: relative;
        animation: cardAppear 0.5s cubic-bezier(0.16, 1, 0.3, 1) both;
        cursor: pointer;
        display: flex;
        flex-direction: column;
    }

    .card:nth-child(1) {
        animation-delay: 0.05s;
    }

    .card:nth-child(2) {
        animation-delay: 0.10s;
    }

    .card:nth-child(3) {
        animation-delay: 0.15s;
    }

    .card:nth-child(4) {
        animation-delay: 0.20s;
    }

    .card:nth-child(5) {
        animation-delay: 0.25s;
    }

    .card:nth-child(6) {
        animation-delay: 0.30s;
    }

    .card:nth-child(7) {
        animation-delay: 0.35s;
    }

    .card:nth-child(8) {
        animation-delay: 0.40s;
    }

    .card:nth-child(9) {
        animation-delay: 0.45s;
    }

    .card:nth-child(10) {
        animation-delay: 0.50s;
    }

    @keyframes cardAppear {
        from {
            opacity: 0;
            transform: translateY(30px) scale(0.96);
        }

        to {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }

    .card:hover {
        border-color: var(--accent);
        transform: translateY(-6px) scale(1.01);
        box-shadow: 0 12px 40px rgba(0, 0, 0, 0.4);
    }

    .card .thumb {
        height: 180px;
        background: var(--bg-input);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 48px;
        color: var(--text-muted);
        overflow: hidden;
        position: relative;
    }

    .card .thumb img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.6s cubic-bezier(0.16, 1, 0.3, 1);
        /* Skeleton loading effect */
        background: linear-gradient(90deg, var(--bg-input) 25%, #222 50%, var(--bg-input) 75%);
        background-size: 200% 100%;
        animation: shimmer 1.5s infinite;
    }

    @keyframes shimmer {
        0% {
            background-position: 200% 0;
        }

        100% {
            background-position: -200% 0;
        }
    }

    .card:hover .thumb img {
        transform: scale(1.05);
    }

    .card .thumb .status-badge {
        position: absolute;
        top: 12px;
        right: 12px;
        padding: 4px 12px;
        border-radius: 12px;
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        backdrop-filter: blur(10px);
        z-index: 10;
    }

    .status-badge.completed {
        background: rgba(52, 211, 153, 0.2);
        color: var(--success);
    }

    .status-badge.processing {
        background: rgba(251, 191, 36, 0.2);
        color: var(--warning);
        animation: pulseBadge 1.5s ease-in-out infinite;
    }

    .status-badge.failed {
        background: rgba(248, 113, 113, 0.2);
        color: var(--danger);
    }

    @keyframes pulseBadge {

        0%,
        100% {
            opacity: 1;
        }

        50% {
            opacity: 0.5;
        }
    }

    .card .body {
        padding: 16px 20px 20px;
        flex-grow: 1;
    }

    .card .body .name {
        font-size: 18px;
        font-weight: 600;
        font-family: 'Space Grotesk', sans-serif;
        margin-bottom: 2px;
        color: var(--text);
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
        flex-wrap: wrap;
        justify-content: space-between;
    }

    .card .body .meta .conf {
        padding: 2px 12px;
        border-radius: 12px;
        background: rgba(52, 211, 153, 0.12);
        color: var(--success);
        font-weight: 600;
    }

    .card .body .meta .conf.low {
        background: rgba(248, 113, 113, 0.12);
        color: var(--danger);
    }

    .card .body .meta .conf.medium {
        background: rgba(251, 191, 36, 0.12);
        color: var(--warning);
    }

    .card .body .meta .time {
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .card .body .meta .time i {
        font-size: 11px;
        opacity: 0.5;
    }

    /* ===== EMPTY STATE ===== */
    .empty {
        text-align: center;
        padding: 80px 20px;
        color: var(--text-muted);
        grid-column: 1/-1;
    }

    .empty i {
        font-size: 56px;
        opacity: 0.2;
        display: block;
        margin-bottom: 16px;
        transition: transform 0.6s cubic-bezier(0.16, 1, 0.3, 1);
    }

    .empty:hover i {
        transform: scale(1.1) rotate(-4deg);
    }

    .empty p {
        font-size: 16px;
        max-width: 400px;
        margin: 0 auto 20px;
    }

    /* ===== PAGINATION (Laravel Default Styling Override) ===== */
    .pagination {
        display: flex;
        gap: 8px;
        justify-content: center;
        margin-top: 40px;
        flex-wrap: wrap;
    }

    .pagination .page-item {
        padding: 8px 16px;
        border-radius: 8px;
        border: 1px solid var(--border);
        background: var(--bg-card);
        color: var(--text-secondary);
        transition: all 0.25s ease;
        text-decoration: none;
        font-weight: 500;
        font-size: 14px;
    }

    .pagination .page-item:hover {
        border-color: var(--accent);
        color: var(--text);
        transform: translateY(-2px);
    }

    .pagination .page-item.active {
        background: var(--accent);
        color: #fff;
        border-color: var(--accent);
        box-shadow: 0 4px 16px rgba(139, 92, 246, 0.3);
    }

    .pagination .page-item.disabled {
        opacity: 0.4;
        cursor: not-allowed;
        transform: none !important;
    }

    /* ===== RESPONSIVE ===== */
    @media (max-width: 768px) {
        .navbar {
            padding: 12px 20px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .navbar .nav-actions {
            width: 100%;
            justify-content: flex-start;
        }

        .container {
            padding: 20px;
        }

        .grid {
            grid-template-columns: 1fr;
        }

        .header {
            flex-direction: column;
        }

        .header h1 {
            font-size: 26px;
        }

        .filter-group {
            width: 100%;
            overflow-x: auto;
        }

        .card .thumb {
            height: 140px;
        }
    }

    @media (max-width: 480px) {
        .container {
            padding: 16px;
        }

        .header h1 {
            font-size: 22px;
        }

        .card .thumb {
            height: 120px;
        }

        .btn {
            font-size: 12px;
            padding: 6px 14px;
        }
    }
    </style>
</head>

<body>

    <nav class="navbar">
        <a href="/" class="logo">
            <span class="icon">T</span>
            TraceGeo
        </a>
        <div class="nav-actions">
            <a href="/" class="btn btn-ghost"><i class="fas fa-home"></i> Home</a>
            <a href="/analysis" class="btn btn-primary"><i class="fas fa-plus"></i> New Analysis</a>
        </div>
    </nav>

    <div class="container">
        <div class="header">
            <div>
                <h1>📊 Analysis History</h1>
                <p class="sub">All your past geolocation analyses</p>
            </div>
            <div class="header-actions">
                <div class="filter-group" id="filterGroup">
                    <button class="filter-btn active" data-filter="all">All</button>
                    <button class="filter-btn" data-filter="completed">Completed</button>
                    <button class="filter-btn" data-filter="processing">Processing</button>
                    <button class="filter-btn" data-filter="failed">Failed</button>
                </div>
            </div>
        </div>

        <div class="grid" id="historyGrid">
            @forelse($analyses ?? [] as $analysis)
            @php
            // ✅ Safely extract all data using Laravel's data_get helper
            $result = $analysis->result ?? null;
            if (is_string($result)) {
            $result = json_decode($result, true);
            }

            $landmark = data_get($result, 'landmark_name') ?? 'Unknown Location';
            $city = data_get($result, 'city') ?? '';
            $country = data_get($result, 'country') ?? '';
            $confidence = data_get($result, 'confidence') ?? 0;
            $status = $analysis->status ?? 'pending';
            // ✅ FIX: Use the actual image_url attribute (Cloudinary URL)
            $imageUrl = $analysis->image_url ?? null;
            $createdAt = $analysis->created_at ?? null;
            $id = $analysis->id ?? null;
            @endphp

            <a href="/analysis" class="card" data-status="{{ $status }}" data-id="{{ $id }}">
                <div class="thumb">
                    @if($imageUrl)
                    <img src="{{ $imageUrl }}" alt="{{ $landmark }}" loading="lazy"
                        onerror="this.parentElement.innerHTML='<i class=\'fas fa-image\'></i>';">
                    @else
                    <i class="fas fa-image"></i>
                    @endif
                    <span class="status-badge {{ $status }}">
                        @if($status === 'completed') ✅ Completed
                        @elseif($status === 'processing') ⏳ Processing
                        @elseif($status === 'failed') ❌ Failed
                        @else ⏳ Pending
                        @endif
                    </span>
                </div>
                <div class="body">
                    <div class="name">{{ $landmark }}</div>
                    <div class="location">
                        @if($city && $country)
                        {{ $city }}, {{ $country }}
                        @elseif($city)
                        {{ $city }}
                        @elseif($country)
                        {{ $country }}
                        @else
                        <span style="color:var(--text-muted);font-style:italic;">Location unknown</span>
                        @endif
                    </div>
                    <div class="meta">
                        <span class="conf @if($confidence < 40) low @elseif($confidence < 70) medium @endif">
                            {{ $confidence }}%
                        </span>
                        <span class="time">
                            <i class="far fa-clock"></i>
                            {{ $createdAt ? $createdAt->diffForHumans() : 'N/A' }}
                        </span>
                        @if($status === 'processing')
                        <span style="color: var(--warning);"><i class="fas fa-spinner fa-spin"></i></span>
                        @endif
                    </div>
                </div>
            </a>
            @empty
            <div class="empty">
                <i class="fas fa-inbox"></i>
                <p>No analyses found yet.<br>Upload your first photo to get started!</p>
                <a href="/" class="btn btn-primary" style="margin-top:16px;display:inline-block;">
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

    <script>
    // ============================================
    // FILTER FUNCTIONALITY
    // ============================================
    document.addEventListener('DOMContentLoaded', function() {
        const filterBtns = document.querySelectorAll('.filter-btn');
        const cards = document.querySelectorAll('.card');

        filterBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                // Update active state
                filterBtns.forEach(b => b.classList.remove('active'));
                this.classList.add('active');

                const filter = this.dataset.filter;

                cards.forEach(card => {
                    if (filter === 'all') {
                        card.style.display = 'flex';
                    } else {
                        const status = card.dataset.status;
                        card.style.display = status === filter ? 'flex' : 'none';
                    }
                });
            });
        });
    });

    // ============================================
    // CARD CLICK HANDLER (Professional approach)
    // ============================================
    document.addEventListener('DOMContentLoaded', function() {
        const cards = document.querySelectorAll('.card[data-id]');

        cards.forEach(card => {
            card.addEventListener('click', function(e) {
                e.preventDefault();
                const id = this.dataset.id;
                sessionStorage.setItem('analysisId', id);
                window.location.href = '/analysis';
            });
        });
    });

    // ============================================
    // KEYBOARD SHORTCUT
    // ============================================
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
            e.preventDefault();
            window.location.href = '/analysis';
        }
    });

    console.log('📊 History page loaded');
    console.log('⌨️  Press Ctrl+N to start a new analysis');
    </script>

</body>

</html>