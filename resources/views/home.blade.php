<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TraceGeo — AI-Powered Geolocation Analysis</title>
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
            --accent-light: rgba(139,92,246,0.12);
            --success: #34d399;
            --cyan: #22d3ee;
            --radius: 12px;
            --radius-lg: 20px;
            --shadow: 0 8px 32px rgba(0,0,0,0.4);
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            line-height: 1.6;
            overflow-x: hidden;
        }

        a { text-decoration: none; color: inherit; }
        button { cursor: pointer; font-family: 'Inter', sans-serif; }

        .bg-glow {
            position: fixed;
            inset: 0;
            z-index: 0;
            pointer-events: none;
            background: 
                radial-gradient(ellipse at 20% 30%, rgba(139,92,246,0.08), transparent 60%),
                radial-gradient(ellipse at 80% 70%, rgba(34,211,238,0.04), transparent 50%),
                radial-gradient(ellipse at 50% 100%, rgba(139,92,246,0.05), transparent 40%);
            animation: glowPulse 8s ease-in-out infinite alternate;
        }

        @keyframes glowPulse {
            0% { opacity: 0.6; }
            100% { opacity: 1; }
        }

        /* ===== NAVBAR ===== */
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
            transition: transform 0.3s ease;
        }

        .navbar .logo:hover .icon { transform: rotate(-10deg) scale(1.05); }

        .navbar .nav-links {
            display: flex;
            gap: 28px;
            list-style: none;
        }

        .navbar .nav-links a {
            color: var(--text-secondary);
            font-size: 14px;
            font-weight: 500;
            transition: 0.3s;
            position: relative;
        }

        .navbar .nav-links a::after {
            content: '';
            position: absolute;
            bottom: -4px;
            left: 0;
            width: 0;
            height: 2px;
            background: var(--accent);
            transition: width 0.3s ease;
        }

        .navbar .nav-links a:hover::after { width: 100%; }
        .navbar .nav-links a:hover { color: var(--text); }

        .navbar .nav-actions {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .navbar .nav-user {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .navbar .nav-user .user-name {
            color: var(--text-secondary);
            font-size: 13px;
        }

        .navbar .nav-user .user-name i {
            margin-right: 6px;
            color: var(--accent);
        }

        .btn {
            padding: 8px 20px;
            border-radius: 8px;
            border: none;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-ghost {
            background: transparent;
            color: var(--text-secondary);
        }
        .btn-ghost:hover { color: var(--text); background: rgba(255,255,255,0.05); }

        .btn-primary {
            background: linear-gradient(135deg, var(--accent), #6d28d9);
            color: #fff;
            box-shadow: 0 4px 16px rgba(139,92,246,0.3);
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(139,92,246,0.5);
        }

        .btn-small {
            padding: 6px 14px;
            font-size: 12px;
        }

        .btn-outline {
            background: transparent;
            color: var(--text);
            border: 1px solid var(--border-light);
        }
        .btn-outline:hover {
            background: rgba(255,255,255,0.05);
            border-color: var(--accent);
        }

        /* ===== HERO ===== */
        .hero {
            max-width: 1200px;
            margin: 0 auto;
            padding: 60px 48px 40px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 48px;
            align-items: start;
            position: relative;
            z-index: 5;
        }

        .hero-left { animation: fadeInUp 0.8s ease 0.2s both; }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .hero-left .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 4px 14px 4px 4px;
            border-radius: 100px;
            background: var(--accent-light);
            border: 1px solid rgba(139,92,246,0.15);
            font-size: 12px;
            color: var(--accent);
            font-weight: 500;
            margin-bottom: 20px;
        }

        .hero-left .badge .dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--accent);
            animation: pulseDot 2s ease-in-out infinite;
        }

        @keyframes pulseDot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.4; transform: scale(0.8); }
        }

        .hero-left h1 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 48px;
            font-weight: 900;
            line-height: 1.1;
            letter-spacing: -0.02em;
            margin-bottom: 16px;
        }

        .hero-left h1 .highlight {
            background: linear-gradient(135deg, var(--accent), var(--cyan));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            animation: shimmer 3s ease-in-out infinite;
        }

        @keyframes shimmer {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }

        .hero-left p {
            font-size: 18px;
            color: var(--text-secondary);
            max-width: 440px;
            line-height: 1.7;
            margin-bottom: 24px;
        }

        .hero-stats {
            display: flex;
            gap: 48px;
            padding-top: 20px;
            border-top: 1px solid var(--border);
        }

        .hero-stats .stat { text-align: center; }

        .hero-stats .stat .number {
            font-size: 28px;
            font-weight: 700;
            font-family: 'Space Grotesk', sans-serif;
            background: linear-gradient(135deg, var(--accent), var(--cyan));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            transition: transform 0.3s ease;
        }

        .hero-stats .stat:hover .number { transform: scale(1.1); }

        .hero-stats .stat .label {
            font-size: 13px;
            color: var(--text-muted);
        }

        /* ===== UPLOAD CARD ===== */
        .upload-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 32px;
            animation: fadeInUp 0.8s ease 0.4s both;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
        }

        .upload-card:hover {
            border-color: var(--accent);
            box-shadow: 0 8px 40px rgba(139,92,246,0.1);
        }

        .upload-card .label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--text-muted);
            font-weight: 600;
        }

        .upload-card .title {
            font-size: 20px;
            font-weight: 700;
            margin-top: 4px;
            font-family: 'Space Grotesk', sans-serif;
        }

        .upload-card .sub {
            font-size: 14px;
            color: var(--text-secondary);
            margin-bottom: 20px;
        }

        /* ===== UPLOAD ZONE ===== */
        .upload-zone {
            border: 2px dashed var(--border);
            border-radius: var(--radius);
            padding: 36px 24px;
            text-align: center;
            background: var(--bg-input);
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
        }

        .upload-zone::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(139,92,246,0.05), rgba(34,211,238,0.05));
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .upload-zone:hover::before { opacity: 1; }
        .upload-zone:hover {
            border-color: var(--accent);
            transform: translateY(-2px);
        }

        .upload-zone.dragover {
            border-color: var(--accent);
            background: rgba(139,92,246,0.08);
            transform: scale(1.01);
        }

        .upload-zone .icon {
            font-size: 40px;
            display: block;
            margin-bottom: 8px;
            opacity: 0.5;
            transition: transform 0.3s ease;
        }

        .upload-zone:hover .icon { transform: scale(1.1) rotate(-5deg); }

        .upload-zone h4 {
            font-size: 16px;
            font-weight: 600;
        }

        .upload-zone .hint {
            font-size: 13px;
            color: var(--text-secondary);
            margin-top: 2px;
        }

        .upload-zone .hint span {
            color: var(--accent);
            font-weight: 500;
            cursor: pointer;
        }

        .upload-zone .hint span:hover {
            text-decoration: underline;
        }

        .upload-zone .divider {
            color: var(--text-muted);
            font-size: 13px;
            margin: 14px 0;
            position: relative;
        }

        .upload-zone .divider::before,
        .upload-zone .divider::after {
            content: '';
            position: absolute;
            top: 50%;
            width: 30%;
            height: 1px;
            background: var(--border);
        }

        .upload-zone .divider::before { left: 0; }
        .upload-zone .divider::after { right: 0; }

        .choose-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 10px 24px;
            border-radius: 8px;
            border: 1px solid var(--border-light);
            background: transparent;
            color: var(--text-secondary);
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .choose-btn:hover {
            background: rgba(255,255,255,0.05);
            border-color: var(--accent);
            color: var(--text);
            transform: translateY(-2px);
        }

        .choose-btn i {
            color: var(--accent);
            font-size: 16px;
        }

        .url-row {
            display: flex;
            gap: 8px;
            max-width: 440px;
            margin: 0 auto;
            align-items: center;
        }

        .url-row input {
            flex: 1;
            padding: 10px 14px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: var(--bg);
            color: var(--text);
            font-size: 13px;
            outline: none;
            font-family: 'Inter', sans-serif;
            transition: border-color 0.3s ease;
            min-width: 0;
        }

        .url-row input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(139,92,246,0.1);
        }

        .url-row input::placeholder {
            color: var(--text-muted);
        }

        .url-row .analyze-btn {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            background: linear-gradient(135deg, var(--accent), #6d28d9);
            color: #fff;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.3s ease;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            flex-shrink: 0;
        }

        .url-row .analyze-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(139,92,246,0.4);
        }

        .url-row .analyze-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .context-hint {
            margin-top: 16px;
            padding: 10px 14px;
            border-radius: 8px;
            background: var(--bg);
            border: 1px solid var(--border);
            color: var(--text-muted);
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
            text-align: left;
            transition: border-color 0.3s ease;
        }

        .context-hint:hover {
            border-color: var(--accent);
        }

        .context-hint i {
            color: var(--accent);
        }

        #imageInput {
            display: none;
        }

        /* ===== UPLOAD DROPDOWN ===== */
        .upload-dropdown {
            display: none;
            position: absolute;
            bottom: calc(100% + 10px);
            left: 50%;
            transform: translateX(-50%);
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 8px;
            min-width: 260px;
            box-shadow: var(--shadow);
            z-index: 50;
            animation: slideUp 0.25s ease;
        }

        .upload-dropdown.show {
            display: block;
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateX(-50%) translateY(10px); }
            to { opacity: 1; transform: translateX(-50%) translateY(0); }
        }

        .upload-dropdown .option {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
            color: var(--text-secondary);
            font-size: 13px;
            font-weight: 500;
        }

        .upload-dropdown .option:hover {
            background: var(--accent-light);
            color: var(--text);
        }

        .upload-dropdown .option .icon {
            font-size: 18px;
            width: 28px;
            text-align: center;
            flex-shrink: 0;
        }

        .upload-dropdown .option .label {
            flex: 1;
        }

        .upload-dropdown .option .shortcut {
            font-size: 11px;
            color: var(--text-muted);
            background: var(--bg);
            padding: 2px 8px;
            border-radius: 4px;
        }

        .upload-dropdown .divider-line {
            height: 1px;
            background: var(--border);
            margin: 4px 8px;
        }

        /* ===== UPLOAD PROGRESS ===== */
        .upload-progress {
            display: none;
            margin-top: 16px;
            padding: 16px;
            background: var(--bg-input);
            border-radius: var(--radius);
            border: 1px solid var(--border);
        }

        .upload-progress .progress-bar {
            width: 100%;
            height: 4px;
            background: var(--border);
            border-radius: 2px;
            overflow: hidden;
            margin-top: 8px;
        }

        .upload-progress .progress-bar .fill {
            height: 100%;
            background: linear-gradient(90deg, var(--accent), var(--cyan));
            border-radius: 2px;
            transition: width 0.5s ease;
            width: 0%;
        }

        .upload-progress .status {
            font-size: 13px;
            color: var(--text-secondary);
            display: flex;
            justify-content: space-between;
        }

        .upload-progress .status .file-name {
            color: var(--text-muted);
            font-size: 12px;
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* ===== TOAST ===== */
        .toast {
            position: fixed;
            bottom: 30px;
            right: 30px;
            z-index: 1000;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 14px 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: var(--shadow);
            transform: translateY(120%);
            transition: transform 0.5s cubic-bezier(0.16, 1, 0.3, 1);
            max-width: 420px;
        }

        .toast.show {
            transform: translateY(0);
        }

        .toast .icon {
            font-size: 20px;
            flex-shrink: 0;
        }

        .toast .icon.success { color: var(--success); }
        .toast .icon.error { color: #f87171; }
        .toast .icon.info { color: var(--cyan); }

        .toast .message {
            font-size: 14px;
            color: var(--text-secondary);
            flex: 1;
        }

        .toast .close-toast {
            background: none;
            border: none;
            color: var(--text-muted);
            font-size: 16px;
            cursor: pointer;
            padding: 0 4px;
        }

        /* ===== CONTENT ===== */
        .content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px 48px 40px;
            position: relative;
            z-index: 5;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 48px;
        }

        .feature-box {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 24px 28px;
            margin-bottom: 16px;
            transition: all 0.3s ease;
        }

        .feature-box:hover {
            border-color: var(--accent);
            transform: translateY(-2px);
        }

        .feature-box .icon {
            font-size: 28px;
            display: block;
            margin-bottom: 8px;
        }

        .feature-box h3 {
            font-size: 16px;
            font-weight: 600;
            font-family: 'Space Grotesk', sans-serif;
        }

        .feature-box p {
            color: var(--text-secondary);
            font-size: 14px;
            line-height: 1.6;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .section-header h2 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 20px;
            font-weight: 700;
        }

        .section-header a {
            color: var(--accent);
            font-size: 14px;
            font-weight: 500;
            transition: color 0.3s ease;
        }

        .section-header a:hover {
            color: var(--success);
        }

        .recent-item {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 14px;
            transition: all 0.3s ease;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
        }

        .recent-item:hover {
            border-color: var(--accent);
            transform: translateY(-2px);
        }

        .recent-item .thumb {
            height: 90px;
            border-radius: 8px;
            background: var(--bg-input);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            color: var(--text-muted);
            margin-bottom: 8px;
            overflow: hidden;
        }

        .recent-item .thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .recent-item .name {
            font-size: 14px;
            font-weight: 600;
        }

        .recent-item .loc {
            font-size: 12px;
            color: var(--text-secondary);
        }

        .recent-item .conf {
            font-size: 11px;
            color: var(--success);
            font-weight: 600;
            margin-top: 4px;
        }

        .popular-item {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: all 0.3s ease;
            text-decoration: none;
            color: inherit;
        }

        .popular-item:hover {
            border-color: var(--accent);
            transform: translateX(4px);
        }

        .popular-item .rank {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-muted);
            min-width: 24px;
            font-family: 'Space Grotesk', sans-serif;
        }

        .popular-item .info .name {
            font-size: 14px;
            font-weight: 500;
        }

        .popular-item .info .country {
            font-size: 12px;
            color: var(--text-secondary);
        }

        .popular-item .count {
            font-size: 12px;
            color: var(--text-muted);
            margin-left: auto;
            background: var(--accent-light);
            padding: 2px 10px;
            border-radius: 12px;
            font-weight: 600;
        }

        #recentGrid, #popularGrid {
            display: grid;
            gap: 12px;
        }

        #recentGrid {
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
        }

        #popularGrid {
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
        }

        /* ===== FOOTER ===== */
        .footer {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px 48px 32px;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            position: relative;
            z-index: 5;
        }

        .footer p {
            font-size: 13px;
            color: var(--text-muted);
        }

        .footer .links {
            display: flex;
            gap: 24px;
        }

        .footer .links a {
            color: var(--text-secondary);
            font-size: 13px;
            transition: color 0.3s ease;
        }

        .footer .links a:hover {
            color: var(--text);
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 1024px) {
            .hero {
                grid-template-columns: 1fr;
                gap: 32px;
            }
            .content {
                grid-template-columns: 1fr;
                gap: 32px;
            }
        }

        @media (max-width: 768px) {
            .navbar {
                padding: 12px 20px;
                flex-wrap: wrap;
            }
            .navbar .nav-links {
                display: none;
            }
            .hero {
                padding: 40px 20px 30px;
            }
            .hero-left h1 {
                font-size: 32px;
            }
            .hero-stats {
                gap: 24px;
                flex-wrap: wrap;
            }
            .hero-stats .stat .number {
                font-size: 22px;
            }
            .upload-card {
                padding: 20px;
            }
            .upload-zone {
                padding: 24px 16px;
            }
            .url-row {
                flex-direction: column;
                max-width: 100%;
            }
            .url-row .analyze-btn {
                width: 100%;
                justify-content: center;
            }
            .upload-dropdown {
                min-width: 220px;
                bottom: calc(100% + 5px);
            }
            .content {
                padding: 20px;
            }
            .footer {
                flex-direction: column;
                text-align: center;
                padding: 16px 20px 24px;
            }
            .footer .links {
                justify-content: center;
                flex-wrap: wrap;
            }
        }

        @media (max-width: 480px) {
            .hero-left h1 {
                font-size: 28px;
            }
            .upload-zone .icon {
                font-size: 32px;
            }
            .upload-zone h4 {
                font-size: 14px;
            }
            .context-hint {
                font-size: 12px;
                flex-wrap: wrap;
            }
            .choose-btn {
                font-size: 13px;
                padding: 8px 18px;
            }
            .upload-dropdown {
                min-width: 200px;
                padding: 6px;
            }
            .upload-dropdown .option {
                font-size: 12px;
                padding: 8px 12px;
            }
            #recentGrid {
                grid-template-columns: 1fr 1fr;
            }
            #popularGrid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<div class="bg-glow"></div>

<!-- ===== TOAST ===== -->
<div class="toast" id="toast">
    <span class="icon" id="toastIcon">✅</span>
    <span class="message" id="toastMessage">Success!</span>
    <button class="close-toast" id="toastClose">✕</button>
</div>

<!-- ===== NAVBAR ===== -->
<nav class="navbar">
    <a href="/" class="logo">
        <span class="icon">T</span>
        TraceGeo
    </a>
    <ul class="nav-links">
        <li><a href="#">Product</a></li>
        <li><a href="#">How it works</a></li>
        <li><a href="#">Pricing</a></li>
        <li><a href="#">API</a></li>
    </ul>
    <div class="nav-actions">
@auth
    <div class="nav-user">
        <span class="user-name">
            <i class="fas fa-user"></i> {{ Auth::user()->name }}
        </span>
        <button class="btn btn-ghost btn-small" id="logoutBtn">
            <i class="fas fa-sign-out-alt"></i> Logout
        </button>
    </div>
@else
    <a href="/login" class="btn btn-ghost">Sign in</a>
    <a href="/register" class="btn btn-primary">
        <i class="fas fa-rocket"></i> Start Analysis
    </a>
@endauth
    </div>
</nav>

<!-- ===== HERO ===== -->
<section class="hero">
    <!-- LEFT -->
    <div class="hero-left">
        <div class="badge">
            <span class="dot"></span> IMAGE ANALYSIS
        </div>
        <h1>
            Find where a<br>
            <span class="highlight">photo was taken.</span>
        </h1>
        <p>
            @auth
                Upload an image and TraceGeo turns visual clues into a
                location, confidence score, and evidence trail.
            @else
                <strong>Please <a href="/login" style="color:var(--accent);">sign in</a> or 
                <a href="/register" style="color:var(--accent);">create an account</a></strong> 
                to start analyzing images.
            @endauth
        </p>
        @auth
        <div class="hero-stats">
            <div class="stat">
                <div class="number" id="totalAnalyses">0</div>
                <div class="label">Total Analyses</div>
            </div>
            <div class="stat">
                <div class="number" id="uniqueLocations">0</div>
                <div class="label">Unique Locations</div>
            </div>
            <div class="stat">
                <div class="number" id="avgConfidence">0%</div>
                <div class="label">Avg Confidence</div>
            </div>
        </div>
        @endauth
    </div>

    <!-- RIGHT: UPLOAD CARD -->
    <div class="upload-card">
        @auth
            <div class="label">IMAGE ANALYSIS</div>
            <div class="title">Upload an image</div>
            <div class="sub">or paste an image URL</div>

            <div class="upload-zone" id="uploadArea">
                <span class="icon">📸</span>
                <h4>Drop an image here</h4>
                <p class="hint">Click to <span id="uploadTrigger">see upload options</span></p>

                <!-- UPLOAD OPTIONS DROPDOWN -->
                <div class="upload-dropdown" id="uploadDropdown">
                    <div class="option" data-action="file">
                        <span class="icon">📁</span>
                        <span class="label">Upload File</span>
                        <span class="shortcut">⌘U</span>
                    </div>
                    <div class="option" data-action="url">
                        <span class="icon">🔗</span>
                        <span class="label">Paste URL</span>
                        <span class="shortcut">⌘P</span>
                    </div>
                    <div class="divider-line"></div>
                    <div class="option" data-action="drag">
                        <span class="icon">🔄</span>
                        <span class="label">Drag & Drop</span>
                        <span class="shortcut">↕</span>
                    </div>
                </div>

                <div class="divider">— or —</div>

                <button class="choose-btn" id="browseBtn">
                    <i class="fas fa-folder-open"></i> Choose image
                </button>
                <input type="file" id="imageInput" accept="image/*">

                <div class="divider">OR PASTE A URL</div>

                <div class="url-row">
                    <input type="text" id="imageUrlInput" placeholder="https://example.com/photo.jpg">
                    <button class="analyze-btn" id="urlAnalyzeBtn">
                        <i class="fas fa-arrow-right"></i> Analyze
                    </button>
                </div>

                <div class="context-hint">
                    <i class="fas fa-pen"></i>
                    Add any context you know.. (country, source, period)
                </div>
            </div>

            <!-- Upload Progress -->
            <div class="upload-progress" id="uploadProgress">
                <div class="status">
                    <span id="uploadStatusText">Uploading...</span>
                    <span id="uploadPercent">0%</span>
                </div>
                <div class="status" style="margin-top:4px;font-size:12px;">
                    <span class="file-name" id="fileNameDisplay"></span>
                </div>
                <div class="progress-bar">
                    <div class="fill" id="uploadProgressFill"></div>
                </div>
            </div>
        @else
            <div style="text-align:center;padding:40px 20px;">
                <div style="font-size:64px;margin-bottom:16px;">🔒</div>
                <h3 style="font-size:20px;font-weight:700;font-family:'Space Grotesk',sans-serif;margin-bottom:8px;">Login Required</h3>
                <p style="color:var(--text-secondary);font-size:14px;margin-bottom:20px;">
                    Please sign in or create an account to use TraceGeo's AI-powered geolocation analysis.
                </p>
                <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
                    <a href="/login" class="btn btn-primary">Sign In</a>
                    <a href="/register" class="btn btn-outline">Create Account</a>
                </div>
            </div>
        @endauth
    </div>
</section>

<!-- ===== CONTENT ===== -->
<section class="content">
    <div>
        <h2 style="font-family:'Space Grotesk',sans-serif;font-size:20px;font-weight:700;margin-bottom:16px;">How TraceGeo Works</h2>
        <div class="feature-box">
            <span class="icon">🔍</span>
            <h3>Text Analyzer</h3>
            <p>Extract geolocation clues from text, metadata, and visual markers.</p>
        </div>
        <div class="feature-box">
            <span class="icon">📍</span>
            <h3>Unique Location</h3>
            <p>Pinpoint exact coordinates with confidence scoring and evidence.</p>
        </div>
        <div class="feature-box">
            <span class="icon">🧠</span>
            <h3>AI Reasoning</h3>
            <p>Advanced AI analyzes visual patterns, vegetation, architecture, and more.</p>
        </div>
    </div>
    <div>
        <div class="section-header">
            <h2>Recent Analyses</h2>
            <a href="#">View all →</a>
        </div>
        <div id="recentGrid">
            @auth
                <div style="grid-column:1/-1;text-align:center;padding:40px 20px;color:var(--text-muted);">
                    <i class="fas fa-inbox" style="font-size:36px;opacity:0.3;display:block;margin-bottom:8px;"></i>
                    <p>No recent analyses yet.<br>Upload your first photo to get started!</p>
                </div>
            @else
                <div style="grid-column:1/-1;text-align:center;padding:40px 20px;color:var(--text-muted);">
                    <i class="fas fa-lock" style="font-size:36px;opacity:0.3;display:block;margin-bottom:8px;"></i>
                    <p><a href="/login" style="color:var(--accent);">Sign in</a> to see your recent analyses</p>
                </div>
            @endauth
        </div>
        <div style="margin-top:32px;">
            <div class="section-header">
                <h2>Popular Landmarks</h2>
            </div>
            <div id="popularGrid">
                <div style="grid-column:1/-1;text-align:center;padding:20px;color:var(--text-muted);">
                    <p>No popular landmarks yet.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ===== FOOTER ===== -->
<footer class="footer">
    <p><i class="fas fa-globe-americas" style="color:var(--accent);"></i> AI-POWERED GEOLOCATION</p>
    <div class="links">
        <a href="#">Privacy</a>
        <a href="#">Terms</a>
        <a href="#">Documentation</a>
        @auth
            <a href="/analysis">Start Identifying</a>
        @else
            <a href="/login">Start Identifying</a>
        @endauth
    </div>
</footer>

<!-- ===== SCRIPTS ===== -->
<script>
// ============================================================
//  TRACEGEO - HOMEPAGE WITH DROPDOWN UPLOAD
// ============================================================

// ============================================
// CSRF TOKEN
// ============================================
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

// ============================================
// TOAST SYSTEM
// ============================================
function showToast(message, type = 'success') {
    const toast = document.getElementById('toast');
    const icon = document.getElementById('toastIcon');
    const msg = document.getElementById('toastMessage');

    const icons = { success: '✅', error: '❌', info: 'ℹ️', warning: '⚠️' };
    icon.textContent = icons[type] || '✅';
    msg.textContent = message;
    toast.classList.add('show');
    setTimeout(() => toast.classList.remove('show'), 4000);
}

document.getElementById('toastClose')?.addEventListener('click', () => {
    document.getElementById('toast')?.classList.remove('show');
});

// ============================================
// DOM REFS
// ============================================
const uploadArea = document.getElementById('uploadArea');
const uploadTrigger = document.getElementById('uploadTrigger');
const uploadDropdown = document.getElementById('uploadDropdown');
const imageInput = document.getElementById('imageInput');
const browseBtn = document.getElementById('browseBtn');
const urlInput = document.getElementById('imageUrlInput');
const urlAnalyzeBtn = document.getElementById('urlAnalyzeBtn');
const uploadProgress = document.getElementById('uploadProgress');
const uploadProgressFill = document.getElementById('uploadProgressFill');
const uploadStatusText = document.getElementById('uploadStatusText');
const uploadPercent = document.getElementById('uploadPercent');
const fileNameDisplay = document.getElementById('fileNameDisplay');

let dropdownOpen = false;

// ============================================
// TOGGLE DROPDOWN - FIXED with null checks
// ============================================
function toggleDropdown(e) {
    if (e) e.stopPropagation();
    if (!uploadDropdown) return;
    
    dropdownOpen = !dropdownOpen;
    uploadDropdown.classList.toggle('show', dropdownOpen);
}

function closeDropdown() {
    if (!uploadDropdown) return;
    dropdownOpen = false;
    uploadDropdown.classList.remove('show');
}

// ============================================
// EVENT LISTENERS - Only if elements exist
// ============================================

// Click on "see upload options"
if (uploadTrigger) {
    uploadTrigger.addEventListener('click', toggleDropdown);
}

// Click on upload zone icon
if (uploadArea) {
    uploadArea.querySelector('.icon')?.addEventListener('click', toggleDropdown);
}

// Click on upload zone (but not on buttons/inputs)
if (uploadArea) {
    uploadArea.addEventListener('click', function(e) {
        if (e.target.closest('button') || e.target.closest('input')) return;
        if (e.target === uploadTrigger || e.target.closest('.icon')) return;
        toggleDropdown(e);
    });
}

// Close dropdown on outside click
document.addEventListener('click', function(e) {
    if (!uploadArea?.contains(e.target)) {
        closeDropdown();
    }
});

// ============================================
// DROPDOWN OPTIONS
// ============================================
document.querySelector('.option[data-action="file"]')?.addEventListener('click', function(e) {
    e.stopPropagation();
    closeDropdown();
    setTimeout(() => imageInput?.click(), 100);
});

document.querySelector('.option[data-action="url"]')?.addEventListener('click', function(e) {
    e.stopPropagation();
    closeDropdown();
    setTimeout(() => urlInput?.focus(), 100);
});

document.querySelector('.option[data-action="drag"]')?.addEventListener('click', function(e) {
    e.stopPropagation();
    closeDropdown();
    showToast('🔄 Drag & drop an image file anywhere on the page!', 'info');
});

// ============================================
// DRAG & DROP
// ============================================
if (uploadArea) {
    uploadArea.addEventListener('dragover', (e) => {
        e.preventDefault();
        uploadArea.classList.add('dragover');
        closeDropdown();
    });

    uploadArea.addEventListener('dragleave', () => {
        uploadArea.classList.remove('dragover');
    });

    uploadArea.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadArea.classList.remove('dragover');
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            const file = files[0];
            if (file.type.startsWith('image/')) {
                handleFileUpload(file);
            } else {
                showToast('Please drop an image file.', 'error');
            }
        }
    });
}

// ============================================
// CHOOSE IMAGE BUTTON
// ============================================
browseBtn?.addEventListener('click', function(e) {
    e.stopPropagation();
    e.preventDefault();
    closeDropdown();
    imageInput?.click();
});

// ============================================
// FILE INPUT CHANGE
// ============================================
imageInput?.addEventListener('change', function(e) {
    if (this.files.length > 0) {
        const file = this.files[0];
        handleFileUpload(file);
    }
});

// ============================================
// URL ANALYZE
// ============================================
urlAnalyzeBtn?.addEventListener('click', function() {
    const url = urlInput?.value.trim();
    if (!url) {
        showToast('Please paste an image URL.', 'error');
        return;
    }
    closeDropdown();
    handleUrlUpload(url);
});

urlInput?.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        urlAnalyzeBtn?.click();
    }
});

// ============================================
// HANDLE FILE UPLOAD - FIXED
// ============================================
function handleFileUpload(file) {
    if (!file.type.startsWith('image/')) {
        showToast('Please upload an image file.', 'error');
        return;
    }
    if (file.size > 5 * 1024 * 1024) {
        showToast('Image must be under 5MB.', 'error');
        return;
    }

    if (uploadProgress) uploadProgress.style.display = 'block';
    if (uploadProgressFill) uploadProgressFill.style.width = '0%';
    if (uploadPercent) uploadPercent.textContent = '0%';
    if (uploadStatusText) uploadStatusText.textContent = '⏳ Preparing upload...';
    if (fileNameDisplay) fileNameDisplay.textContent = `📎 ${file.name} (${Math.round(file.size / 1024)} KB)`;

    const formData = new FormData();
    formData.append('image', file);

    if (urlAnalyzeBtn) {
        urlAnalyzeBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        urlAnalyzeBtn.disabled = true;
    }

    let progress = 0;
    const progressInterval = setInterval(() => {
        progress += Math.random() * 8;
        if (progress > 90) progress = 90;
        if (uploadProgressFill) uploadProgressFill.style.width = progress + '%';
        if (uploadPercent) uploadPercent.textContent = Math.round(progress) + '%';
        if (uploadStatusText) {
            if (progress > 30) uploadStatusText.textContent = '📤 Uploading...';
            if (progress > 60) uploadStatusText.textContent = '🤖 AI analyzing...';
        }
    }, 300);

    // ✅ Get CSRF token
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

    fetch('/api/analyze', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': csrfToken,
            'Accept': 'application/json',
        },
        body: formData,
    })
    .then(async res => {
        // ✅ Check if response is JSON
        const contentType = res.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            // If not JSON, it's likely a redirect to login or 419
            if (res.status === 419) {
                throw new Error('Session expired. Please refresh the page and try again.');
            } else if (res.status === 401 || res.status === 302) {
                throw new Error('Please login to upload images.');
            } else {
                throw new Error('Server error. Please try again.');
            }
        }
        return res.json();
    })
    .then(data => {
        clearInterval(progressInterval);
        if (uploadProgressFill) uploadProgressFill.style.width = '100%';
        if (uploadPercent) uploadPercent.textContent = '100%';
        if (uploadStatusText) uploadStatusText.textContent = '✅ Analysis complete! Redirecting...';

        if (data.success && data.data) {
            showToast('🌍 Analysis complete!', 'success');
            sessionStorage.setItem('analysisResult', JSON.stringify(data.data));
            sessionStorage.setItem('analysisId', data.analysis_id || '');
            sessionStorage.setItem('uploadedFileName', file.name);
            setTimeout(() => window.location.href = '/analysis', 800);
        } else {
            showToast(data.message || 'Analysis failed.', 'error');
            resetUploadUI();
        }
    })
    .catch(err => {
        clearInterval(progressInterval);
        console.error('Upload error:', err);
        showToast(err.message || 'Network error. Please try again.', 'error');
        resetUploadUI();
    });
}

// ============================================
// HANDLE URL UPLOAD - FIXED
// ============================================
function handleUrlUpload(url) {
    if (uploadProgress) uploadProgress.style.display = 'block';
    if (uploadProgressFill) uploadProgressFill.style.width = '0%';
    if (uploadPercent) uploadPercent.textContent = '0%';
    if (uploadStatusText) uploadStatusText.textContent = '📥 Fetching image from URL...';
    if (fileNameDisplay) fileNameDisplay.textContent = `🔗 ${url.substring(0, 50)}...`;

    if (urlAnalyzeBtn) {
        urlAnalyzeBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        urlAnalyzeBtn.disabled = true;
    }

    let progress = 0;
    const progressInterval = setInterval(() => {
        progress += Math.random() * 10;
        if (progress > 50) progress = 50;
        if (uploadProgressFill) uploadProgressFill.style.width = progress + '%';
        if (uploadPercent) uploadPercent.textContent = Math.round(progress) + '%';
    }, 200);

    // ✅ Get CSRF token
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

    fetch('/api/fetch-image?url=' + encodeURIComponent(url), {
        method: 'GET',
        headers: {
            'X-CSRF-TOKEN': csrfToken,
            'Accept': 'application/json',
        },
    })
    .then(async res => {
        // ✅ Check if response is JSON
        const contentType = res.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            if (res.status === 419) {
                throw new Error('Session expired. Please refresh and try again.');
            } else if (res.status === 401 || res.status === 302) {
                throw new Error('Please login to upload images.');
            } else {
                throw new Error('Server error. Please try again.');
            }
        }
        return res.json();
    })
    .then(data => {
        clearInterval(progressInterval);

        if (data.success && data.image_data) {
            if (uploadProgressFill) uploadProgressFill.style.width = '60%';
            if (uploadPercent) uploadPercent.textContent = '60%';
            if (uploadStatusText) uploadStatusText.textContent = '📸 Processing image...';

            const byteCharacters = atob(data.image_data);
            const byteNumbers = new Array(byteCharacters.length);
            for (let i = 0; i < byteCharacters.length; i++) {
                byteNumbers[i] = byteCharacters.charCodeAt(i);
            }
            const byteArray = new Uint8Array(byteNumbers);
            const blob = new Blob([byteArray], { type: data.mime_type || 'image/jpeg' });
            const fileName = data.filename || 'image.jpg';
            const file = new File([blob], fileName, { type: data.mime_type || 'image/jpeg' });

            if (uploadProgressFill) uploadProgressFill.style.width = '80%';
            if (uploadPercent) uploadPercent.textContent = '80%';
            if (uploadStatusText) uploadStatusText.textContent = '🤖 AI analyzing...';

            const formData = new FormData();
            formData.append('image', file);

            fetch('/api/analyze', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: formData,
            })
            .then(async res => {
                const contentType = res.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    if (res.status === 419) {
                        throw new Error('Session expired. Please refresh and try again.');
                    } else if (res.status === 401 || res.status === 302) {
                        throw new Error('Please login to upload images.');
                    } else {
                        throw new Error('Server error. Please try again.');
                    }
                }
                return res.json();
            })
            .then(analysisData => {
                if (uploadProgressFill) uploadProgressFill.style.width = '100%';
                if (uploadPercent) uploadPercent.textContent = '100%';
                if (uploadStatusText) uploadStatusText.textContent = '✅ Analysis complete! Redirecting...';

                if (analysisData.success && analysisData.data) {
                    showToast('🌍 Analysis complete!', 'success');
                    sessionStorage.setItem('analysisResult', JSON.stringify(analysisData.data));
                    sessionStorage.setItem('analysisId', analysisData.analysis_id || '');
                    sessionStorage.setItem('uploadedFileName', fileName);
                    setTimeout(() => window.location.href = '/analysis', 800);
                } else {
                    showToast(analysisData.message || 'Analysis failed.', 'error');
                    resetUploadUI();
                }
            })
            .catch(err => {
                console.error('Analysis error:', err);
                showToast(err.message || 'Analysis failed. Please try again.', 'error');
                resetUploadUI();
            });
        } else {
            showToast(data.message || 'Could not fetch image from URL.', 'error');
            resetUploadUI();
        }
    })
    .catch(err => {
        clearInterval(progressInterval);
        console.error('URL fetch error:', err);
        showToast(err.message || 'Could not fetch image from URL. Please check the URL.', 'error');
        resetUploadUI();
    });
}

// ============================================
// RESET UI
// ============================================
function resetUploadUI() {
    if (uploadProgress) uploadProgress.style.display = 'none';
    if (urlAnalyzeBtn) {
        urlAnalyzeBtn.innerHTML = '<i class="fas fa-arrow-right"></i> Analyze';
        urlAnalyzeBtn.disabled = false;
    }
    if (uploadProgressFill) uploadProgressFill.style.width = '0%';
    if (uploadPercent) uploadPercent.textContent = '0%';
}

// ============================================
// LOAD DASHBOARD DATA (Only if logged in)
// ============================================
@auth
async function loadDashboardData() {
    try {
        const response = await fetch('/api/dashboard-data');
        const data = await response.json();

        if (data.success) {
            animateNumber('totalAnalyses', data.stats.total_analyses || 0);
            animateNumber('uniqueLocations', data.stats.unique_locations || 0);
            animateNumber('avgConfidence', data.stats.avg_confidence || 0, '%');

            const recentGrid = document.getElementById('recentGrid');
            if (data.recent && data.recent.length > 0) {
                recentGrid.innerHTML = data.recent.map(item => `
                    <a href="/analysis/${item.id}" class="recent-item" style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);padding:14px;transition:all 0.3s ease;cursor:pointer;text-decoration:none;color:inherit;">
                        <div class="thumb" style="height:90px;border-radius:8px;background:var(--bg-input);display:flex;align-items:center;justify-content:center;font-size:28px;color:var(--text-muted);margin-bottom:8px;overflow:hidden;">
                            ${item.image_path ? `<img src="/storage/${item.image_path}" alt="${item.landmark_name}" style="width:100%;height:100%;object-fit:cover;">` : '<i class="fas fa-image"></i>'}
                        </div>
                        <div class="name" style="font-size:14px;font-weight:600;">${item.landmark_name || 'Unknown'}</div>
                        <div class="loc" style="font-size:12px;color:var(--text-secondary);">${item.city || ''}, ${item.country || ''}</div>
                        <div class="conf" style="font-size:11px;color:var(--success);font-weight:600;margin-top:4px;">${item.confidence || 0}%</div>
                    </a>
                `).join('');
            }

            const popularGrid = document.getElementById('popularGrid');
            if (data.popular && data.popular.length > 0) {
                popularGrid.innerHTML = data.popular.map((item, index) => `
                    <a href="#" class="popular-item" style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);padding:12px 16px;display:flex;align-items:center;gap:12px;transition:all 0.3s ease;text-decoration:none;color:inherit;">
                        <span class="rank" style="font-size:18px;font-weight:700;color:var(--text-muted);min-width:24px;font-family:'Space Grotesk',sans-serif;">#${index + 1}</span>
                        <div class="info">
                            <div class="name" style="font-size:14px;font-weight:500;">${item.landmark_name || 'Unknown'}</div>
                            <div class="country" style="font-size:12px;color:var(--text-secondary);">${item.country || ''}</div>
                        </div>
                        <span class="count" style="font-size:12px;color:var(--text-muted);margin-left:auto;background:var(--accent-light);padding:2px 10px;border-radius:12px;font-weight:600;">${item.count || 0}×</span>
                    </a>
                `).join('');
            }
        }
    } catch (error) {
        console.error('Failed to load dashboard data:', error);
    }
}

function animateNumber(elementId, target, suffix = '') {
    const el = document.getElementById(elementId);
    if (!el) return;
    let current = 0;
    const duration = 1000;
    const steps = 30;
    const increment = target / steps;
    const interval = setInterval(() => {
        current += increment;
        if (current >= target) {
            current = target;
            clearInterval(interval);
        }
        el.textContent = Math.round(current) + suffix;
    }, duration / steps);
}
@endauth

// ============================================
// CHECK FOR RETURNING FROM ANALYSIS
// ============================================
const result = sessionStorage.getItem('analysisResult');
if (result) {
    try {
        const data = JSON.parse(result);
        const fileName = sessionStorage.getItem('uploadedFileName') || 'image';
        showToast(`📍 Found: ${data.landmark_name || 'Location'} from ${fileName}`, 'success');
        setTimeout(() => sessionStorage.removeItem('analysisResult'), 5000);
    } catch(e) {}
}

// ============================================
// LOGOUT - FIXED
// ============================================
document.getElementById('logoutBtn')?.addEventListener('click', async function(e) {
    e.preventDefault();
    
    try {
        // ✅ Get CSRF token
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        
        // ✅ Use fetch with proper headers
        const response = await fetch('/logout', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
                'Content-Type': 'application/json',
            },
        });
        
        // ✅ Check if response is JSON
        const contentType = response.headers.get('content-type');
        if (contentType && contentType.includes('application/json')) {
            const result = await response.json();
            if (result.success) {
                showToast('Logged out successfully!', 'success');
                setTimeout(() => window.location.reload(), 500);
            }
        } else {
            // ✅ If not JSON, it's a redirect - reload page
            showToast('Logged out successfully!', 'success');
            setTimeout(() => window.location.reload(), 500);
        }
    } catch (error) {
        console.error('Logout error:', error);
        // ✅ Fallback: reload page anyway
        showToast('Logged out successfully!', 'success');
        setTimeout(() => window.location.reload(), 500);
    }
});

// ============================================
// KEYBOARD SHORTCUTS
// ============================================
document.addEventListener('keydown', function(e) {
    // Ctrl+U or Cmd+U - Open file picker
    if ((e.ctrlKey || e.metaKey) && e.key === 'u') {
        e.preventDefault();
        closeDropdown();
        imageInput?.click();
    }
    // Escape - Close dropdown
    if (e.key === 'Escape') {
        closeDropdown();
    }
});

// ============================================
// INIT
// ============================================
@auth
loadDashboardData();
console.log('✅ TraceGeo homepage loaded. User is logged in.');
@else
console.log('✅ TraceGeo homepage loaded. User is not logged in.');
console.log('📸 Please login or register to use the analysis features.');
@endauth

console.log('📸 Click "see upload options" for dropdown menu:');
console.log('   📁 Upload File - Opens file picker');
console.log('   🔗 Paste URL - Focus URL input');
console.log('   🔄 Drag & Drop - Shows info');
console.log('⌨️  Shortcut: Ctrl+U to upload, ESC to close dropdown');
</script>

</body>
</html>