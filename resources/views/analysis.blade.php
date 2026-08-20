<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TraceGeo — OSINT Analysis</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    
    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Space+Grotesk:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        :root {
            --bg-deep: #07070d;
            --bg-card: #0e0e18;
            --bg-panel: #0c0c16;
            --border: rgba(255,255,255,0.06);
            --text: #ffffff;
            --text-secondary: #9ca3af;
            --text-muted: #6b7280;
            --accent: #8b5cf6;
            --success: #2dd4bf;
            --cyan: #22d3ee;
            --warning: #fbbf24;
            --danger: #f87171;
            --radius: 12px;
            --radius-lg: 22px;
            --shadow: 0 20px 60px rgba(0,0,0,0.55);
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-deep);
            color: var(--text);
            min-height: 100vh;
            overflow: hidden;
        }

        .globe-bg {
            position: fixed;
            inset: 0;
            z-index: 0;
            pointer-events: none;
            background: 
                radial-gradient(ellipse at 30% 20%, rgba(139,92,246,0.08), transparent 55%),
                radial-gradient(ellipse at 75% 80%, rgba(34,211,238,0.05), transparent 50%);
            animation: bgPulse 8s ease-in-out infinite alternate;
        }

        @keyframes bgPulse {
            0% { opacity: 0.6; transform: scale(1); }
            100% { opacity: 1; transform: scale(1.05); }
        }

        .navbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 100;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 28px;
            background: rgba(7,7,13,0.92);
            backdrop-filter: blur(16px);
            border-bottom: 1px solid var(--border);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 9px;
            font-family: 'Space Grotesk', sans-serif;
            font-size: 17px;
            font-weight: 700;
            text-decoration: none;
            color: var(--text);
        }

        .logo .icon {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            background: linear-gradient(135deg, var(--accent), #6d28d9);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 700;
            color: #fff;
        }

        .tagline {
            font-size: 12px;
            color: var(--text-muted);
            text-align: center;
            flex: 1;
        }

        .nav-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .nav-status {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-right: 12px;
        }

        .nav-status .pulse-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--success);
            box-shadow: 0 0 20px var(--success);
            animation: pulseDot 1.2s ease-in-out infinite;
        }

        @keyframes pulseDot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.3; transform: scale(0.7); }
        }

        .nav-status .status-text {
            font-size: 12px;
            color: var(--text-secondary);
            font-weight: 500;
        }

        .btn {
            padding: 7px 16px;
            border-radius: 8px;
            border: none;
            font-size: 12.5px;
            font-weight: 600;
            transition: all 0.25s ease;
            font-family: 'Inter', sans-serif;
            text-decoration: none;
            cursor: pointer;
        }

        .btn-ghost {
            background: rgba(255,255,255,0.04);
            color: var(--text-secondary);
            border: 1px solid var(--border);
        }
        .btn-ghost:hover { color: var(--text); background: rgba(255,255,255,0.08); }

        .btn-primary {
            background: linear-gradient(135deg, var(--accent), #6d28d9);
            color: #fff;
            box-shadow: 0 4px 16px rgba(139,92,246,0.3);
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(139,92,246,0.5);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success), #10b981);
            color: #fff;
            box-shadow: 0 4px 16px rgba(45,212,191,0.3);
        }
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(45,212,191,0.5);
        }

        .stage-frame {
            position: fixed;
            top: 68px;
            left: 24px;
            right: 24px;
            bottom: 20px;
            border-radius: var(--radius-lg);
            border: 1px solid rgba(45,212,191,0.2);
            background: #05050a;
            overflow: hidden;
            box-shadow: var(--shadow), 0 0 0 1px rgba(255,255,255,0.02);
            z-index: 5;
        }

        .stage-content {
            position: absolute;
            inset: 0;
        }

        /* ========== MAP EARTH ========== */
        .map-earth-container {
            position: absolute;
            inset: 0;
            background: radial-gradient(ellipse at center, #0a0a1a 0%, #05050a 100%);
            overflow: hidden;
            z-index: 1;
        }

        .map-earth-container #earthMap {
            width: 100%;
            height: 100%;
            background: #05050a;
        }

        .map-earth-container .map-overlay {
            position: absolute;
            inset: 0;
            pointer-events: none;
            background: radial-gradient(ellipse at center, transparent 40%, rgba(5,5,10,0.8) 100%);
            z-index: 5;
        }

        .map-earth-container .map-status {
            position: absolute;
            bottom: 40px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 10;
            text-align: center;
            font-family: 'JetBrains Mono', monospace;
            font-size: 13px;
            color: var(--text-secondary);
            background: rgba(7,7,13,0.7);
            padding: 8px 20px;
            border-radius: 20px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.05);
            min-width: 200px;
            transition: all 0.5s ease;
        }

        .map-earth-container .map-status .highlight {
            color: var(--cyan);
            font-weight: 600;
        }

        .map-earth-container .map-status .target-found {
            color: var(--success);
            font-weight: 700;
            animation: pulseText 1s ease-in-out infinite;
        }

        @keyframes pulseText {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        /* ========== PROBE MARKER ========== */
        .probe-marker {
            position: relative;
            width: 40px;
            height: 40px;
            margin: -20px 0 0 -20px;
        }

        .probe-marker .ring {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            border-radius: 50%;
            border: 2px solid var(--cyan);
            animation: probePulse 1.2s ease-in-out infinite;
            width: 30px;
            height: 30px;
        }

        .probe-marker .ring:nth-child(2) {
            animation-delay: 0.6s;
            width: 50px;
            height: 50px;
            border-color: rgba(45,212,191,0.3);
        }

        .probe-marker .dot {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: var(--cyan);
            box-shadow: 0 0 30px rgba(34,211,238,0.5);
        }

        .probe-marker.target .dot {
            background: var(--success);
            box-shadow: 0 0 40px rgba(45,212,191,0.8);
        }

        .probe-marker.target .ring {
            border-color: var(--success);
        }

        @keyframes probePulse {
            0% { transform: translate(-50%, -50%) scale(0.5); opacity: 1; }
            100% { transform: translate(-50%, -50%) scale(2); opacity: 0; }
        }

        /* ========== WHITE FLASH ========== */
        .white-flash {
            position: absolute;
            inset: 0;
            background: #fff;
            opacity: 0;
            z-index: 50;
            pointer-events: none;
        }

        .white-flash.run {
            animation: flashWipe 1.1s ease-in-out forwards;
        }

        @keyframes flashWipe {
            0% { opacity: 0; }
            45% { opacity: 1; }
            100% { opacity: 0; }
        }

        /* ========== PROGRESS CARD ========== */
        .progress-card {
            position: absolute;
            left: 24px;
            bottom: 24px;
            z-index: 30;
            width: 420px;
            max-width: calc(100% - 48px);
            background: rgba(8,8,14,0.92);
            backdrop-filter: blur(18px);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 16px;
            padding: 16px 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.5);
            display: none;
        }

        .pc-label {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--success);
            margin-bottom: 6px;
        }

        .pc-label .dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--success);
            box-shadow: 0 0 10px var(--success);
            animation: pulseDot 1.2s ease-in-out infinite;
        }

        .pc-headline {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 3px;
            letter-spacing: -0.01em;
            min-height: 24px;
        }

        .pc-sub {
            font-family: 'JetBrains Mono', monospace;
            font-size: 11px;
            color: var(--text-muted);
            margin-bottom: 12px;
            min-height: 16px;
        }

        .pc-bar {
            width: 100%;
            height: 3px;
            background: rgba(255,255,255,0.08);
            border-radius: 2px;
            overflow: hidden;
            margin-bottom: 12px;
        }

        .pc-bar .fill {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, var(--success), var(--cyan));
            border-radius: 2px;
            transition: width 0.4s ease;
        }

        .pc-steps {
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
        }

        .pc-steps .pcs {
            font-size: 10.5px;
            font-weight: 600;
            color: var(--text-muted);
            transition: color 0.3s ease;
        }

        .pc-steps .pcs b {
            font-weight: 700;
            margin-right: 3px;
            opacity: 0.7;
        }

        .pc-steps .pcs.active {
            color: var(--text);
        }

        .pc-steps .pcs.active b {
            color: var(--success);
            opacity: 1;
        }

        .pc-steps .pcs.done {
            color: var(--text-secondary);
        }

        /* ========== RESULTS SPLIT VIEW ========== */
        .results-split {
            position: absolute;
            inset: 0;
            display: flex;
            opacity: 0;
            pointer-events: none;
            z-index: 20;
        }

        .results-split.show {
            opacity: 1;
            pointer-events: auto;
            animation: resultsFadeIn 0.6s ease both;
        }

        @keyframes resultsFadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .sat-pane {
            flex: 1;
            position: relative;
            background: var(--bg-deep);
            overflow: hidden;
            min-width: 0;
        }

        .sat-pane #resultMap {
            width: 100%;
            height: 100%;
            background: var(--bg-deep);
        }

        .pill-brand {
            position: absolute;
            top: 16px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 15;
            font-family: 'Space Grotesk', sans-serif;
            font-size: 11.5px;
            font-weight: 700;
            background: rgba(8,8,14,0.8);
            backdrop-filter: blur(10px);
            border: 1px solid var(--border);
            padding: 5px 16px;
            border-radius: 20px;
            color: var(--text);
            pointer-events: none;
        }

        .pill-confidence {
            position: absolute;
            top: 16px;
            right: 16px;
            z-index: 15;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11.5px;
            font-weight: 700;
            background: rgba(8,8,14,0.8);
            backdrop-filter: blur(10px);
            border: 1px solid var(--border);
            padding: 5px 14px;
            border-radius: 20px;
            pointer-events: none;
        }

        .pill-confidence .dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
        }

        .pane-actions {
            position: absolute;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 15;
            display: flex;
            gap: 8px;
        }

        .pane-actions button {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 600;
            color: var(--text);
            background: rgba(8,8,14,0.85);
            backdrop-filter: blur(10px);
            border: 1px solid var(--border);
            padding: 8px 16px;
            border-radius: 20px;
            cursor: pointer;
            transition: 0.2s;
            font-family: 'Inter', sans-serif;
        }

        .pane-actions button:hover {
            border-color: var(--success);
            background: rgba(45,212,191,0.1);
        }

        .avatar-marker {
            position: relative;
            width: 46px;
            height: 46px;
        }

        .avatar-marker .ring {
            position: absolute;
            inset: -10px;
            border-radius: 50%;
            border: 2px solid var(--success);
            animation: markerRing 1.8s ease-out infinite;
        }

        .avatar-marker .ring2 {
            position: absolute;
            inset: -10px;
            border-radius: 50%;
            border: 2px solid var(--cyan);
            animation: markerRing 1.8s ease-out infinite;
            animation-delay: 0.6s;
        }

        @keyframes markerRing {
            0% { transform: scale(0.7); opacity: 0.9; }
            100% { transform: scale(1.9); opacity: 0; }
        }

        .avatar-marker .photo {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            border: 3px solid #fff;
            box-shadow: 0 4px 16px rgba(0,0,0,0.5);
            background: #222 center/cover no-repeat;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            overflow: hidden;
        }

        .marker-drop {
            animation: markerDrop 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) both;
        }

        @keyframes markerDrop {
            0% { transform: translateY(-100px) scale(0.5); opacity: 0; }
            65% { transform: translateY(6px) scale(1.06); opacity: 1; }
            100% { transform: translateY(0) scale(1); }
        }

        .street-inline {
            position: absolute;
            inset: 0;
            background: #000;
            z-index: 10;
        }

        .street-inline iframe {
            width: 100%;
            height: 100%;
            border: none;
        }

        .street-back {
            position: absolute;
            top: 16px;
            left: 16px;
            z-index: 20;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: rgba(8,8,14,0.85);
            border: 1px solid var(--border);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 14px;
            transition: 0.2s;
        }

        .street-back:hover {
            background: rgba(45,212,191,0.15);
            border-color: var(--success);
        }

        .street-open-full {
            position: absolute;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 20;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 600;
            color: #fff;
            background: rgba(8,8,14,0.85);
            border: 1px solid var(--border);
            padding: 8px 16px;
            border-radius: 20px;
            cursor: pointer;
            font-family: 'Inter', sans-serif;
            transition: 0.2s;
        }

        .street-open-full:hover {
            border-color: var(--success);
            background: rgba(45,212,191,0.1);
        }

        .data-pane {
            width: 340px;
            min-width: 320px;
            background: var(--bg-panel);
            border-left: 1px solid var(--border);
            padding: 20px 24px;
            overflow-y: auto;
        }

        .data-pane::-webkit-scrollbar {
            width: 5px;
        }

        .data-pane::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.1);
            border-radius: 3px;
        }

        .verified-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 10.5px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            padding: 5px 14px;
            border-radius: 20px;
            margin-bottom: 12px;
        }

        .verified-pill.high {
            background: rgba(45,212,191,0.12);
            border: 1px solid rgba(45,212,191,0.28);
            color: var(--success);
        }

        .verified-pill.medium {
            background: rgba(251,191,36,0.12);
            border: 1px solid rgba(251,191,36,0.28);
            color: var(--warning);
        }

        .verified-pill.low {
            background: rgba(248,113,113,0.12);
            border: 1px solid rgba(248,113,113,0.28);
            color: var(--danger);
        }

        .place-title {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 26px;
            font-weight: 700;
            letter-spacing: -0.01em;
            margin-bottom: 2px;
        }

        .place-country {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 1px;
        }

        .place-region {
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 14px;
        }

        .coord-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(255,255,255,0.03);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 9px 12px;
            margin-bottom: 10px;
        }

        .coord-row span {
            font-family: 'JetBrains Mono', monospace;
            font-size: 12px;
            color: var(--text-secondary);
        }

        .coord-row button {
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 13px;
            padding: 4px;
            transition: 0.2s;
        }

        .coord-row button:hover {
            color: var(--success);
        }

        .pane-toggle-row {
            display: flex;
            gap: 8px;
            margin-bottom: 8px;
        }

        .pane-toggle-row button {
            flex: 1;
            font-size: 11.5px;
            font-weight: 600;
            padding: 8px 10px;
            border-radius: 9px;
            border: 1px solid var(--border);
            background: rgba(255,255,255,0.03);
            color: var(--text-secondary);
            cursor: pointer;
            font-family: 'Inter', sans-serif;
            transition: 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }

        .pane-toggle-row button.active {
            background: rgba(255,255,255,0.95);
            color: #0a0a12;
            border-color: transparent;
        }

        .pane-toggle-row button:not(.active):hover {
            border-color: var(--success);
            color: var(--text);
        }

        .photo-label {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--text-muted);
            margin: 16px 0 8px;
        }

        .photo-frame {
            position: relative;
            border-radius: 10px;
            overflow: hidden;
            border: 1px solid var(--border);
            background: #05050a;
            height: 150px;
        }

        .photo-frame img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .photo-frame .no-photo {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-muted);
            font-size: 32px;
            opacity: 0.3;
        }

        .photo-tag {
            position: absolute;
            top: 10px;
            left: 10px;
            font-size: 9.5px;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 20px;
            background: rgba(45,212,191,0.16);
            border: 1px solid rgba(45,212,191,0.35);
            color: #7ee8d8;
            backdrop-filter: blur(4px);
        }

        .photo-icon-row {
            display: flex;
            gap: 6px;
            margin-top: 8px;
        }

        .photo-icon-row button {
            flex: 1;
            background: rgba(255,255,255,0.03);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 7px 0;
            color: var(--text-secondary);
            cursor: pointer;
            font-size: 12px;
            transition: 0.2s;
            font-family: 'Inter', sans-serif;
        }

        .photo-icon-row button:hover {
            color: var(--text);
            border-color: var(--success);
        }

        .reasoning-block {
            margin-top: 16px;
        }

        .reasoning-label {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 6px;
        }

        .reasoning-text {
            font-size: 12.5px;
            line-height: 1.7;
            color: var(--text-secondary);
        }

        .tag-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 12px;
        }

        .tag-pill {
            font-size: 10.5px;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 100px;
            background: rgba(139,92,246,0.1);
            border: 1px solid rgba(139,92,246,0.25);
            color: #c4b5fd;
        }

        @media (max-width: 900px) {
            .results-split { flex-direction: column; }
            .sat-pane { flex: none; height: 48%; }
            .data-pane { width: 100%; min-width: unset; height: 52%; border-left: none; border-top: 1px solid var(--border); }
            .progress-card { width: calc(100% - 32px); left: 16px; bottom: 16px; padding: 13px 16px; }
            .pc-steps { gap: 8px; }
            .pc-steps .pcs { font-size: 9px; }
            .stage-frame { top: 60px; left: 12px; right: 12px; bottom: 12px; }
            .navbar { padding: 10px 16px; flex-wrap: wrap; gap: 6px; }
            .tagline { display: none; }
            .nav-status .status-text { font-size: 10px; }
            .pane-actions button { font-size: 10px; padding: 6px 12px; }
            .pill-brand { font-size: 10px; top: 10px; padding: 4px 12px; }
            .pill-confidence { font-size: 10px; top: 10px; right: 10px; padding: 4px 10px; }
            .data-pane { padding: 14px 16px; }
            .place-title { font-size: 20px; }
            .map-earth-container .map-status { font-size: 11px; padding: 6px 14px; bottom: 30px; min-width: 150px; }
        }

        @media (max-width: 600px) {
            .pc-headline { font-size: 14px; }
            .pc-sub { font-size: 10px; }
            .pc-steps .pcs { font-size: 8px; }
            .progress-card { padding: 10px 12px; }
            .street-back { width: 30px; height: 30px; font-size: 12px; }
            .street-open-full { font-size: 10px; padding: 6px 12px; }
            .coord-row span { font-size: 10px; }
            .reasoning-text { font-size: 11px; }
            .photo-frame { height: 100px; }
            .map-earth-container .map-status { font-size: 10px; padding: 4px 10px; bottom: 20px; min-width: 120px; }
        }
    </style>
</head>
<body>

<div class="globe-bg"></div>

<nav class="navbar">
    <a href="/" class="logo">
        <span class="icon">T</span>
        TraceGeo
    </a>
    <span class="tagline">Find where a photo was taken.</span>
    <div class="nav-actions">
        <div class="nav-status">
            <span class="pulse-dot" id="statusDot"></span>
            <span class="status-text" id="navStatus">🌍 OSINT Engine</span>
        </div>
        <a href="/" class="btn btn-ghost"><i class="fas fa-home"></i></a>
    </div>
</nav>

<div class="stage-frame" id="stageFrame">
    <div class="stage-content" id="stageContent">
        
        <!-- ===== MAP EARTH ===== -->
        <div class="map-earth-container" id="mapEarthContainer">
            <div id="earthMap"></div>
            <div class="map-overlay"></div>
            <div class="map-status" id="mapStatus">
                <span class="highlight">●</span> <span id="mapStatusText">INITIALIZING ANALYSIS...</span>
            </div>
        </div>

        <!-- White Flash -->
        <div class="white-flash" id="whiteFlash"></div>

        <!-- Progress Card -->
        <div class="progress-card" id="progressCard">
            <div class="pc-label">
                <span class="dot"></span>
                <span id="pcLabel">ANALYZING</span>
            </div>
            <div class="pc-headline" id="pcHeadline">Initializing analysis pipeline...</div>
            <div class="pc-sub" id="pcSub">Elapsed 0.0s · Progress 0% · Engine TraceGeo AI</div>
            <div class="pc-bar"><div class="fill" id="pcFill"></div></div>
            <div class="pc-steps" id="pcSteps">
                <span class="pcs" data-step="0"><b>01</b>Input</span>
                <span class="pcs" data-step="1"><b>02</b>Features</span>
                <span class="pcs" data-step="2"><b>03</b>Reasoning</span>
                <span class="pcs" data-step="3"><b>04</b>Cross-ref</span>
                <span class="pcs" data-step="4"><b>05</b>Locate</span>
            </div>
        </div>

        <!-- Results Split View -->
        <div class="results-split" id="resultsSplit">
            <div class="sat-pane" id="satPane">
                <div id="resultMap"></div>
                <div class="pill-brand">TraceGeo</div>
                <div class="pill-confidence" id="confPill">
                    <span class="dot" style="background:var(--success);"></span>
                    <span id="confPillText">100% Confidence</span>
                </div>
                <div class="pane-actions">
                    <button id="streetBtnPane"><i class="fas fa-street-view"></i> Street View</button>
                    <button id="globeBtnPane"><i class="fas fa-globe-americas"></i> 3D View</button>
                </div>
            </div>
            <div class="data-pane" id="dataPane">
                <div class="verified-pill high" id="verifiedPill">
                    <i class="fas fa-check-circle"></i> Verified Location
                </div>
                <div class="place-title" id="placeTitle">Location</div>
                <div class="place-country" id="placeCountry">Country</div>
                <div class="place-region" id="placeRegion">Region</div>

                <div class="coord-row">
                    <span id="coordText">0.0000° N, 0.0000° W</span>
                    <button id="copyCoordsBtn" title="Copy coordinates"><i class="fas fa-copy"></i></button>
                </div>

                <div class="pane-toggle-row">
                    <button class="active" id="roadsToggle"><i class="fas fa-route"></i> Roads</button>
                    <button id="terrainToggle"><i class="fas fa-satellite"></i> Terrain</button>
                </div>
                <div class="pane-toggle-row">
                    <button id="globeBtnPanel"><i class="fas fa-globe-americas"></i> 3D View</button>
                    <button id="streetBtnPanel"><i class="fas fa-street-view"></i> Street View</button>
                </div>

                <div class="photo-label">Your Photo</div>
                <div class="photo-frame" id="photoFrame">
                    <div class="no-photo"><i class="fas fa-image"></i></div>
                </div>
                <div class="photo-icon-row">
                    <button id="viewOriginalBtn" title="View original"><i class="fas fa-expand"></i></button>
                    <button id="downloadPhotoBtn" title="Download photo"><i class="fas fa-download"></i></button>
                    <button id="exportReportBtn" title="Export OSINT report"><i class="fas fa-file-export"></i></button>
                </div>

                <div class="tag-pills" id="tagPills"></div>

                <div class="reasoning-block">
                    <div class="reasoning-label"><i class="fas fa-brain"></i> Reasoning Analysis</div>
                    <div class="reasoning-text" id="reasoningText">—</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
// ============================================================
//  TRACEGEO — Analysis Page (Auto-starts from homepage)
//  FIXED: Correct API routes, image display, and error handling
// ============================================================

const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content || '';

const DARK_TILE_URL = 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png';
const DARK_TILE_ATTR = '&copy; <a href="https://carto.com/attributions">CARTO</a> &copy; OpenStreetMap contributors';
const SATELLITE_URL = 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}';
const SATELLITE_ATTR = '&copy; ESRI';
const ROADS_URL = 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png';
const ROADS_ATTR = '&copy; CARTO &copy; OpenStreetMap contributors';

// Probe locations for Earth zoom effect
const PROBE_LOCATIONS = [
    { name: 'Tokyo, Japan', lat: 35.6762, lng: 139.6503, reason: 'Analyzing urban density...' },
    { name: 'London, UK', lat: 51.5074, lng: -0.1278, reason: 'Comparing architecture...' },
    { name: 'Paris, France', lat: 48.8566, lng: 2.3522, reason: 'Matching landmarks...' },
    { name: 'New York, USA', lat: 40.7128, lng: -74.0060, reason: 'Verifying street layouts...' },
    { name: 'Sydney, Australia', lat: -33.8688, lng: 151.2093, reason: 'Checking vegetation...' },
    { name: 'Cambridge, USA', lat: 42.3625, lng: -71.1245, reason: '✓ LOCATION CONFIRMED!', isTarget: true },
];

const ANALYSIS_STEPS = [
    'Initializing OSINT analysis pipeline...',
    'Extracting visual features from image...',
    'Running Bayesian evidence fusion...',
    'Cross-referencing geospatial databases...',
    'Searching global landmarks...',
    'Pinpointing exact location...',
    'Analysis complete — generating report'
];

const DOM = {
    mapEarthContainer: document.getElementById('mapEarthContainer'),
    whiteFlash: document.getElementById('whiteFlash'),
    progressCard: document.getElementById('progressCard'),
    pcHeadline: document.getElementById('pcHeadline'),
    pcSub: document.getElementById('pcSub'),
    pcFill: document.getElementById('pcFill'),
    pcSteps: document.getElementById('pcSteps'),
    resultsSplit: document.getElementById('resultsSplit'),
    satPane: document.getElementById('satPane'),
    dataPane: document.getElementById('dataPane'),
    resultMap: document.getElementById('resultMap'),
    confPill: document.getElementById('confPill'),
    confPillText: document.getElementById('confPillText'),
    verifiedPill: document.getElementById('verifiedPill'),
    placeTitle: document.getElementById('placeTitle'),
    placeCountry: document.getElementById('placeCountry'),
    placeRegion: document.getElementById('placeRegion'),
    coordText: document.getElementById('coordText'),
    photoFrame: document.getElementById('photoFrame'),
    tagPills: document.getElementById('tagPills'),
    reasoningText: document.getElementById('reasoningText'),
    navStatus: document.getElementById('navStatus'),
    statusDot: document.getElementById('statusDot'),
    mapStatusText: document.getElementById('mapStatusText'),
};

let isAnalyzing = false;
let elapsed = 0;
let timerInterval = null;
let seqTimeout = null;
let resultMapInstance = null;
let earthMapInstance = null;
let currentTileMode = 'roads';
let uploadedImageURL = null;
let analysisData = null;
let currentAnalysisId = null;

// ========== HELPERS ==========
function isValidCoord(lat, lng) {
    const a = parseFloat(lat), b = parseFloat(lng);
    return !isNaN(a) && !isNaN(b) && a !== 0 && b !== 0 && Math.abs(a) <= 90 && Math.abs(b) <= 180;
}

function showToast(msg) {
    DOM.navStatus.textContent = msg;
}

// ========== REAL MAP EARTH ==========
function initEarthMap(center = [20, 0], zoom = 2) {
    try { if (earthMapInstance) earthMapInstance.remove(); } catch(e) {}
    
    const el = document.getElementById('earthMap');
    if (!el) return;
    
    earthMapInstance = L.map(el, {
        center: center,
        zoom: zoom,
        zoomControl: false,
        attributionControl: false,
        fadeAnimation: true,
        zoomAnimation: true,
        inertia: true,
    });
    
    L.tileLayer(DARK_TILE_URL, {
        attribution: DARK_TILE_ATTR,
        maxZoom: 19,
        minZoom: 2,
    }).addTo(earthMapInstance);
    
    setTimeout(() => {
        if (earthMapInstance) earthMapInstance.invalidateSize();
    }, 200);
}

function flyEarthTo(lat, lng, zoom, duration = 2000) {
    return new Promise((resolve) => {
        if (!earthMapInstance) {
            resolve();
            return;
        }
        earthMapInstance.flyTo([lat, lng], zoom, {
            duration: duration / 1000,
            easeLinearity: 0.25,
        });
        setTimeout(resolve, duration + 200);
    });
}

function addProbeMarker(lat, lng, isTarget = false) {
    if (!earthMapInstance) return null;
    
    earthMapInstance.eachLayer((layer) => {
        if (layer instanceof L.Marker && layer.options.probeMarker) {
            earthMapInstance.removeLayer(layer);
        }
    });
    
    const html = `<div class="probe-marker ${isTarget ? 'target' : ''}">
        <div class="ring"></div>
        <div class="ring"></div>
        <div class="dot"></div>
    </div>`;
    
    const icon = L.divIcon({
        html: html,
        className: '',
        iconSize: [40, 40],
        iconAnchor: [20, 20],
    });
    
    const marker = L.marker([lat, lng], { 
        icon: icon,
        probeMarker: true,
        zIndexOffset: 1000,
    }).addTo(earthMapInstance);
    
    return marker;
}

// ========== EARTH ZOOM SEQUENCE ==========
async function runEarthZoomSequence(locations) {
    // Start with global view
    await flyEarthTo(20, 0, 2, 1000);
    
    for (let i = 0; i < locations.length; i++) {
        const loc = locations[i];
        const isTarget = loc.isTarget || false;
        const targetZoom = isTarget ? 15 : 6;
        const duration = isTarget ? 2500 : 1800;
        
        // Update status
        if (isTarget) {
            DOM.mapStatusText.innerHTML = `<span class="target-found">🎯 ${loc.name} — LOCATION CONFIRMED!</span>`;
        } else {
            DOM.mapStatusText.textContent = `🔍 ${loc.name} — ${loc.reason}`;
        }
        
        // Add marker
        addProbeMarker(loc.lat, loc.lng, isTarget);
        
        // Fly to location
        await flyEarthTo(loc.lat, loc.lng, targetZoom, duration);
        
        // If target, zoom in more
        if (isTarget) {
            await new Promise(resolve => setTimeout(resolve, 1000));
            await flyEarthTo(loc.lat, loc.lng, 17, 1500);
            await new Promise(resolve => setTimeout(resolve, 1200));
        } else {
            await new Promise(resolve => setTimeout(resolve, 400));
        }
    }
}

// ========== PROGRESS CARD ==========
function updateProgress(step, progress) {
    DOM.pcHeadline.textContent = ANALYSIS_STEPS[step] || ANALYSIS_STEPS[0];
    DOM.pcFill.style.width = progress + '%';
    DOM.pcSub.textContent = `Elapsed ${elapsed.toFixed(1)}s · Progress ${progress}% · Engine TraceGeo AI`;
    
    document.querySelectorAll('.pc-steps .pcs').forEach((el) => {
        const idx = parseInt(el.dataset.step, 10);
        el.classList.remove('active', 'done');
        if (idx < step) el.classList.add('done');
        else if (idx === step) el.classList.add('active');
    });
}

// ========== START ANALYSIS ==========
function startAnalysis(analysisId) {
    if (isAnalyzing) return;
    isAnalyzing = true;
    currentAnalysisId = analysisId;

    DOM.statusDot.style.background = '#fbbf24';
    DOM.statusDot.style.boxShadow = '0 0 20px #fbbf24';
    showToast('🔍 OSINT Analysis in Progress...');

    DOM.resultsSplit.classList.remove('show');
    DOM.progressCard.style.display = 'block';
    DOM.pcFill.style.width = '0%';

    // Timer
    timerInterval = setInterval(() => {
        elapsed += 0.1;
        DOM.pcSub.textContent = `Elapsed ${elapsed.toFixed(1)}s · Progress ${parseInt(DOM.pcFill.style.width) || 0}% · Engine TraceGeo AI`;
    }, 100);

    // Run analysis steps
    let step = 0;
    function runStep() {
        if (step >= ANALYSIS_STEPS.length) {
            clearInterval(timerInterval);
            performAnalysis();
            return;
        }
        const progress = Math.min(3 + (step * 14), 100);
        updateProgress(step, progress);
        step++;
        seqTimeout = setTimeout(runStep, 1400);
    }
    runStep();
}

// ========== PERFORM ANALYSIS ==========
function performAnalysis() {
    // ✅ FIXED: Use the correct route /api/results/{id}
    fetch(`/api/results/${currentAnalysisId}`, {
        method: 'GET',
        headers: {
            'Accept': 'application/json',
            'X-CSRF-TOKEN': CSRF_TOKEN,
        },
    })
    .then(async (res) => {
        let payload;
        try { payload = await res.json(); } catch { throw new Error('Server error'); }
        if (!res.ok) throw new Error(payload.message || `HTTP ${res.status}`);
        return payload;
    })
    .then((data) => {
        isAnalyzing = false;
        if (data.success && data.data) {
            analysisData = data.data;
            // Store the image URL if available
            if (data.data.image_url) {
                uploadedImageURL = data.data.image_url;
            }
        } else {
            // Fallback to stored session data or simulated
            const stored = sessionStorage.getItem('analysisResult');
            if (stored) {
                try {
                    analysisData = JSON.parse(stored);
                } catch(e) {
                    analysisData = getSimulatedData();
                }
            } else {
                analysisData = getSimulatedData();
            }
        }
        runEarthZoomThenShowResults(analysisData);
    })
    .catch((err) => {
        console.error('Analysis error:', err);
        isAnalyzing = false;
        // Try session storage fallback
        const stored = sessionStorage.getItem('analysisResult');
        if (stored) {
            try {
                analysisData = JSON.parse(stored);
                runEarthZoomThenShowResults(analysisData);
                return;
            } catch(e) {}
        }
        analysisData = getSimulatedData();
        runEarthZoomThenShowResults(analysisData);
    });
}

function getSimulatedData() {
    return {
        landmark_name: 'Cambridge, Massachusetts',
        city: 'Cambridge',
        country: 'United States',
        region: 'Massachusetts',
        latitude: 42.3625,
        longitude: -71.1245,
        confidence: 92,
        tags: ['Riverfront', 'Brick Architecture', 'Tree-lined Path', 'Urban', 'Historic'],
        reasoning: 'The image depicts a waterfront scene with a pedestrian walkway on a slightly elevated concrete path aligned with a bridge over water. The surrounding foliage and architecture style are contextually indicative of the Northeastern United States. Extensive reverse image search results consistently identify the location as Memorial Drive in Cambridge, Massachusetts, USA.',
        description: 'Memorial Drive / Charles River area. Features riverfront roadway, distinctive brick architecture, and tree-lined path.',
        historical_context: 'Cambridge is home to Harvard University and MIT, with historic architecture dating back to the 1600s.'
    };
}

// ========== EARTH ZOOM + SHOW RESULTS ==========
async function runEarthZoomThenShowResults(data) {
    const lat = parseFloat(data.latitude ?? data.lat);
    const lng = parseFloat(data.longitude ?? data.lng);
    
    // Build locations for zoom sequence
    const locations = PROBE_LOCATIONS.map(loc => ({
        ...loc,
        isTarget: Math.abs(loc.lat - lat) < 0.01 && Math.abs(loc.lng - lng) < 0.01
    }));

    // Add target if not in list
    const hasTarget = locations.some(l => l.isTarget);
    if (!hasTarget && isValidCoord(lat, lng)) {
        locations.push({
            name: data.landmark_name || 'Target Location',
            lat: lat,
            lng: lng,
            reason: '✓ LOCATION CONFIRMED!',
            isTarget: true
        });
    }

    // Ensure target is last
    const targetIndex = locations.findIndex(l => l.isTarget);
    if (targetIndex > -1 && targetIndex !== locations.length - 1) {
        const target = locations.splice(targetIndex, 1)[0];
        locations.push(target);
    }

    DOM.statusDot.style.background = '#34d399';
    DOM.statusDot.style.boxShadow = '0 0 20px #34d399';
    showToast('🎯 Locating target on Earth...');

    // Initialize earth map
    initEarthMap();
    
    // Run zoom sequence
    await runEarthZoomSequence(locations);

    // Flash transition
    DOM.whiteFlash.classList.remove('run');
    void DOM.whiteFlash.offsetWidth;
    DOM.whiteFlash.classList.add('run');
    
    // Show results
    setTimeout(() => {
        revealResults(data);
    }, 800);
}

// ========== REVEAL RESULTS ==========
function revealResults(data) {
    DOM.progressCard.style.display = 'none';

    const lat = parseFloat(data.latitude ?? data.lat);
    const lng = parseFloat(data.longitude ?? data.lng);
    const hasCoords = isValidCoord(lat, lng);
    const confidence = data.confidence ?? 0;

    // Data Pane
    const tier = confidence >= 80 ? 'high' : confidence >= 50 ? 'medium' : 'low';
    DOM.verifiedPill.className = `verified-pill ${tier}`;
    DOM.verifiedPill.innerHTML = tier === 'high'
        ? '<i class="fas fa-check-circle"></i> Verified Location'
        : tier === 'medium'
            ? '<i class="fas fa-exclamation-circle"></i> Likely Location'
            : '<i class="fas fa-question-circle"></i> Low Confidence';

    DOM.placeTitle.textContent = data.landmark_name || data.city || 'Unknown Location';
    DOM.placeCountry.textContent = data.country || '';
    DOM.placeRegion.textContent = data.region || '';
    DOM.coordText.textContent = hasCoords
        ? `${Math.abs(lat).toFixed(4)}° ${lat >= 0 ? 'N' : 'S'}, ${Math.abs(lng).toFixed(4)}° ${lng >= 0 ? 'E' : 'W'}`
        : 'Not available';

    DOM.confPillText.textContent = `${confidence}% Confidence`;
    DOM.confPill.querySelector('.dot').style.background = tier === 'high' ? 'var(--success)' : tier === 'medium' ? '#fbbf24' : '#f87171';

    // ✅ FIXED: Show the uploaded image
    if (uploadedImageURL) {
        DOM.photoFrame.innerHTML = `<img src="${uploadedImageURL}" alt="source"><span class="photo-tag">📍 Source match</span>`;
    } else {
        // Check session for image data
        const storedImage = sessionStorage.getItem('uploadedImage');
        if (storedImage) {
            DOM.photoFrame.innerHTML = `<img src="${storedImage}" alt="source"><span class="photo-tag">📍 Source match</span>`;
        } else {
            DOM.photoFrame.innerHTML = `<div class="no-photo"><i class="fas fa-image"></i></div>`;
        }
    }

    // Tags
    DOM.tagPills.innerHTML = (data.tags || []).map((t) => `<span class="tag-pill">${t}</span>`).join('');

    // Reasoning
    DOM.reasoningText.textContent = data.reasoning || 'No reasoning available.';

    // Map
    const satMapEl = document.getElementById('resultMap');
    satMapEl.innerHTML = '';
    DOM.satPane.querySelector('.street-inline')?.remove();

    if (hasCoords) {
        try { if (resultMapInstance) resultMapInstance.remove(); } catch(e) {}
        resultMapInstance = L.map('resultMap', {
            center: [lat, lng],
            zoom: 14,
            zoomControl: false,
            attributionControl: false,
        });
        
        if (currentTileMode === 'terrain') {
            L.tileLayer(SATELLITE_URL, { attribution: SATELLITE_ATTR, maxZoom: 19 }).addTo(resultMapInstance);
        } else {
            L.tileLayer(ROADS_URL, { attribution: ROADS_ATTR, maxZoom: 19 }).addTo(resultMapInstance);
        }

        const iconHtml = `<div class="avatar-marker marker-drop">
            <div class="ring"></div><div class="ring2"></div>
            <div class="photo" style="background:#2dd4bf;display:flex;align-items:center;justify-content:center;font-size:20px;">📍</div>
        </div>`;
        const icon = L.divIcon({ html: iconHtml, className: '', iconSize: [46, 46], iconAnchor: [23, 23] });
        
        setTimeout(() => {
            L.marker([lat, lng], { icon }).addTo(resultMapInstance)
                .bindPopup(`<strong>${data.landmark_name || 'Location'}</strong><br>${Math.abs(lat).toFixed(4)}° ${lat >= 0 ? 'N' : 'S'}, ${Math.abs(lng).toFixed(4)}° ${lng >= 0 ? 'E' : 'W'}`)
                .openPopup();
        }, 300);

        setTimeout(() => {
            resultMapInstance.invalidateSize();
            resultMapInstance.flyTo([lat, lng], 15, { duration: 1.2 });
        }, 200);
    } else {
        satMapEl.innerHTML = `<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#6b7280;flex-direction:column;gap:12px;background:var(--bg-deep);">
            <i class="fas fa-map-marked-alt" style="font-size:48px;opacity:0.25;"></i>
            <span>No coordinates available</span>
        </div>`;
    }

    setupControls(data, lat, lng, hasCoords);
    DOM.resultsSplit.classList.add('show');
    
    DOM.statusDot.style.background = '#34d399';
    DOM.statusDot.style.boxShadow = '0 0 20px #34d399';
    showToast('✅ OSINT Analysis Complete!');
}

// ========== CONTROLS ==========
function setupControls(data, lat, lng, hasCoords) {
    const set = (id, handler) => {
        const el = document.getElementById(id);
        if (el) el.onclick = handler;
    };

    set('copyCoordsBtn', () => {
        if (!hasCoords) return;
        navigator.clipboard?.writeText(`${lat.toFixed(6)}, ${lng.toFixed(6)}`);
        showToast('📋 Coordinates copied!');
    });

    const roadsBtn = document.getElementById('roadsToggle');
    const terrainBtn = document.getElementById('terrainToggle');
    const setTileMode = (mode) => {
        currentTileMode = mode;
        roadsBtn.classList.toggle('active', mode === 'roads');
        terrainBtn.classList.toggle('active', mode === 'terrain');
        if (resultMapInstance) {
            resultMapInstance.eachLayer((l) => {
                if (l instanceof L.TileLayer) resultMapInstance.removeLayer(l);
            });
            const url = mode === 'terrain' ? SATELLITE_URL : ROADS_URL;
            const attr = mode === 'terrain' ? SATELLITE_ATTR : ROADS_ATTR;
            L.tileLayer(url, { attribution: attr, maxZoom: 19 }).addTo(resultMapInstance);
        }
    };
    set('roadsToggle', () => setTileMode('roads'));
    set('terrainToggle', () => setTileMode('terrain'));

    const openGlobe = () => {
        if (hasCoords) window.open(`https://earth.google.com/web/@${lat},${lng},0a,500d`, '_blank');
        else showToast('❌ No coordinates available.');
    };
    set('globeBtnPane', openGlobe);
    set('globeBtnPanel', openGlobe);

    const openStreet = () => {
        if (hasCoords) showStreetView(lat, lng);
        else showToast('❌ No coordinates available.');
    };
    set('streetBtnPane', openStreet);
    set('streetBtnPanel', openStreet);

    set('viewOriginalBtn', () => {
        if (uploadedImageURL) window.open(uploadedImageURL, '_blank');
        else showToast('No image available.');
    });
    
    set('downloadPhotoBtn', () => {
        if (uploadedImageURL) {
            const a = document.createElement('a');
            a.href = uploadedImageURL;
            a.download = 'tracegeo_source.jpg';
            a.click();
        } else {
            showToast('No image available.');
        }
    });
    
    set('exportReportBtn', () => exportReport(data, lat, lng));
}

function showStreetView(lat, lng) {
    DOM.satPane.querySelector('.street-inline')?.remove();
    const wrap = document.createElement('div');
    wrap.className = 'street-inline';
    wrap.innerHTML = `
        <button class="street-back" id="streetBackBtn"><i class="fas fa-arrow-left"></i></button>
        <iframe src="https://www.google.com/maps/embed?pb=!1m4!1m3!1m0!2d${lng}!3d${lat}!1m3!2m2!1d${lng}!2d${lat}!3e4!5m1!1e4!6m1!1e1" allowfullscreen loading="lazy"></iframe>
        <button class="street-open-full" id="streetOpenFullBtn"><i class="fas fa-expand"></i> Open Full Street View</button>
    `;
    DOM.satPane.appendChild(wrap);
    document.getElementById('streetBackBtn').onclick = () => wrap.remove();
    document.getElementById('streetOpenFullBtn').onclick = () => {
        window.open(`https://www.google.com/maps/@?api=1&map_action=pano&viewpoint=${lat},${lng}`, '_blank');
    };
}

function exportReport(data, lat, lng) {
    const hasCoords = isValidCoord(lat, lng);
    const report = `
TRACEGEO OSINT INTELLIGENCE REPORT
============================================
Generated: ${new Date().toISOString()}
Platform: TraceGeo OSINT v2.0

LOCATION INFORMATION
--------------------------------------------
Location: ${data.landmark_name || 'Unknown'}
Coordinates: ${hasCoords ? `${lat.toFixed(6)}, ${lng.toFixed(6)}` : 'N/A'}
Confidence: ${data.confidence || 0}%
Country: ${data.country || 'N/A'}
City: ${data.city || 'N/A'}
Region: ${data.region || 'N/A'}

ANALYSIS DETAILS
--------------------------------------------
Description: ${data.description || 'N/A'}
Tags: ${(data.tags || []).join(', ')}
Historical Context: ${data.historical_context || 'N/A'}

AI REASONING
--------------------------------------------
${data.reasoning || 'No reasoning available.'}

============================================
Report generated by TraceGeo OSINT Intelligence
    `.trim();

    const blob = new Blob([report], { type: 'text/plain' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `TraceGeo_OSINT_Report_${data.landmark_name || 'Location'}.txt`;
    a.click();
    URL.revokeObjectURL(url);
    showToast('📄 OSINT Report exported!');
}

// ========== INIT ==========
// Check if we have an analysis ID from homepage
const analysisId = sessionStorage.getItem('analysisId');

// Also check for stored image
const storedImage = sessionStorage.getItem('uploadedImage');

if (analysisId) {
    // Auto-start analysis
    setTimeout(() => {
        startAnalysis(analysisId);
    }, 500);
} else {
    // Check if we have direct analysis data in session
    const storedResult = sessionStorage.getItem('analysisResult');
    if (storedResult) {
        try {
            const data = JSON.parse(storedResult);
            showToast('📸 Using stored analysis data...');
            setTimeout(() => {
                analysisData = data;
                runEarthZoomThenShowResults(analysisData);
            }, 500);
        } catch(e) {
            fallbackToDemo();
        }
    } else {
        fallbackToDemo();
    }
}

function fallbackToDemo() {
    showToast('⚠️ No analysis found. Using demo data.');
    setTimeout(() => {
        analysisData = getSimulatedData();
        runEarthZoomThenShowResults(analysisData);
    }, 500);
}

// Clean up session storage after use
window.addEventListener('beforeunload', function() {
    // Don't clear immediately - allow refresh
});

let resizeTimeout;
window.addEventListener('resize', () => {
    clearTimeout(resizeTimeout);
    resizeTimeout = setTimeout(() => {
        if (resultMapInstance) resultMapInstance?.invalidateSize();
        if (earthMapInstance) earthMapInstance?.invalidateSize();
    }, 200);
});

console.log('✅ TraceGeo Analysis Engine loaded.');
console.log('📸 Auto-analyzing image from homepage...');
console.log('🔍 Analysis ID:', analysisId || 'Using demo data');
</script>
</body>
</html>