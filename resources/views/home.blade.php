<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TraceGeo — Locate a Photo's Origin</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <link
        href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700;9..144,800&family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap"
        rel="stylesheet">

    <style>
    /* ============================================
       DESIGN TOKENS
       ============================================ */
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    :root {
        --bg: #0a0d12;
        --bg-card: #10141b;
        --bg-input: #151a22;
        --border: rgba(233, 238, 245, 0.08);
        --border-light: rgba(233, 238, 245, 0.15);
        --text: #edf1f6;
        --text-secondary: #93a0af;
        --text-muted: #5c6672;
        --accent: #c98a46;
        --accent-deep: #a86a2e;
        --accent-soft: rgba(201, 138, 70, 0.14);
        --chart: #5b9bd1;
        --success: #5fae82;
        --danger: #d1685a;
        --warn: #d1a955;
        --radius: 10px;
        --radius-lg: 20px;
        --shadow: 0 20px 60px rgba(0, 0, 0, 0.45);
        --font-display: 'Fraunces', Georgia, serif;
        --font-body: 'Inter', sans-serif;
        --font-mono: 'JetBrains Mono', ui-monospace, monospace;
    }

    @media (prefers-reduced-motion: reduce) {

        *,
        *::before,
        *::after {
            animation-duration: 0.001ms !important;
            animation-iteration-count: 1 !important;
            transition-duration: 0.001ms !important;
            scroll-behavior: auto !important;
        }
    }

    html {
        scroll-behavior: smooth;
    }

    body {
        font-family: var(--font-body);
        background: var(--bg);
        color: var(--text);
        min-height: 100vh;
        line-height: 1.6;
        overflow-x: hidden;
    }

    a {
        text-decoration: none;
        color: inherit;
    }

    button {
        cursor: pointer;
        font-family: var(--font-body);
    }

    /* Topographic contour + survey-grid backdrop */
    .bg-glow {
        position: fixed;
        inset: 0;
        z-index: 0;
        pointer-events: none;
        background:
            repeating-radial-gradient(circle at 14% 20%, transparent 0, transparent 38px, rgba(201, 138, 70, 0.05) 39px, rgba(201, 138, 70, 0.05) 40px, transparent 41px),
            repeating-radial-gradient(circle at 88% 78%, transparent 0, transparent 54px, rgba(91, 155, 209, 0.045) 55px, rgba(91, 155, 209, 0.045) 56px, transparent 57px),
            linear-gradient(rgba(233, 238, 245, 0.025) 1px, transparent 1px),
            linear-gradient(90deg, rgba(233, 238, 245, 0.025) 1px, transparent 1px);
        background-size: auto, auto, 64px 64px, 64px 64px;
        animation: driftContours 26s ease-in-out infinite alternate;
    }

    @keyframes driftContours {
        0% {
            background-position: 0 0, 0 0, 0 0, 0 0;
            opacity: 0.75;
        }

        100% {
            background-position: 12px -10px, -14px 10px, 0 0, 0 0;
            opacity: 1;
        }
    }

    ::selection {
        background: var(--accent-soft);
        color: var(--text);
    }

    /* ============================================
       NAVBAR
       ============================================ */
    .navbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 16px 48px;
        border-bottom: 1px solid var(--border);
        background: rgba(10, 13, 18, 0.82);
        backdrop-filter: blur(16px);
        position: sticky;
        top: 0;
        z-index: 100;
        transition: background 0.3s ease;
    }

    .navbar .logo {
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 19px;
        font-weight: 700;
        font-family: var(--font-display);
        letter-spacing: 0.01em;
    }

    .navbar .logo .icon {
        width: 32px;
        height: 32px;
        border-radius: 7px;
        background: linear-gradient(155deg, var(--accent), var(--accent-deep));
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 14px;
        font-weight: 700;
        color: #14100a;
        font-family: var(--font-mono);
        transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1);
    }

    .navbar .logo:hover .icon {
        transform: rotate(-8deg) scale(1.06);
    }

    .navbar .nav-links {
        display: flex;
        gap: 30px;
        list-style: none;
    }

    .navbar .nav-links a {
        color: var(--text-secondary);
        font-size: 13px;
        font-weight: 500;
        letter-spacing: 0.02em;
        text-transform: uppercase;
        transition: 0.25s;
        position: relative;
    }

    .navbar .nav-links a::after {
        content: '';
        position: absolute;
        bottom: -5px;
        left: 0;
        width: 0;
        height: 1px;
        background: var(--accent);
        transition: width 0.3s ease;
    }

    .navbar .nav-links a:hover::after {
        width: 100%;
    }

    .navbar .nav-links a:hover {
        color: var(--text);
    }

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
        font-family: var(--font-mono);
    }

    .navbar .nav-user .user-name i {
        margin-right: 6px;
        color: var(--accent);
    }

    /* ============================================
       BUTTONS
       ============================================ */
    .btn {
        padding: 9px 20px;
        border-radius: 7px;
        border: none;
        font-size: 14px;
        font-weight: 600;
        transition: all 0.25s ease;
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
        background: rgba(233, 238, 245, 0.05);
    }

    .btn-primary {
        background: linear-gradient(155deg, var(--accent), var(--accent-deep));
        color: #16110a;
        box-shadow: 0 4px 18px rgba(201, 138, 70, 0.25);
    }

    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 30px rgba(201, 138, 70, 0.4);
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
        background: rgba(233, 238, 245, 0.05);
        border-color: var(--accent);
    }

    .btn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
        transform: none !important;
    }

    /* ============================================
       HERO SECTION
       ============================================ */
    .hero {
        max-width: 1200px;
        margin: 0 auto;
        padding: clamp(40px, 6vw, 68px) 48px 40px;
        display: grid;
        grid-template-columns: 1.05fr 1fr;
        gap: 48px;
        align-items: start;
        position: relative;
        z-index: 5;
    }

    .hero-left {
        animation: fadeInUp 0.7s cubic-bezier(0.16, 1, 0.3, 1) 0.1s both;
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

    .hero-left .badge {
        display: inline-flex;
        align-items: center;
        gap: 9px;
        padding: 6px 14px;
        border-radius: 100px;
        background: var(--accent-soft);
        border: 1px solid rgba(201, 138, 70, 0.25);
        font-family: var(--font-mono);
        font-size: 11.5px;
        letter-spacing: 0.06em;
        color: var(--accent);
        font-weight: 500;
        margin-bottom: 22px;
    }

    .hero-left .badge .dot {
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: var(--accent);
        box-shadow: 0 0 0 3px var(--accent-soft);
        animation: pulseDot 2.2s ease-in-out infinite;
    }

    @keyframes pulseDot {

        0%,
        100% {
            opacity: 1;
            transform: scale(1);
        }

        50% {
            opacity: 0.45;
            transform: scale(0.75);
        }
    }

    .hero-left .badge #coordReadout {
        color: var(--text-muted);
        border-left: 1px solid rgba(201, 138, 70, 0.25);
        padding-left: 9px;
        font-variant-numeric: tabular-nums;
    }

    .hero-left h1 {
        font-family: var(--font-display);
        font-size: clamp(34px, 4.4vw, 52px);
        font-weight: 600;
        line-height: 1.08;
        letter-spacing: -0.01em;
        margin-bottom: 18px;
    }

    .hero-left h1 .highlight {
        font-style: italic;
        font-weight: 500;
        color: var(--accent);
        position: relative;
    }

    .hero-left p {
        font-size: 17px;
        color: var(--text-secondary);
        max-width: 460px;
        line-height: 1.75;
        margin-bottom: 26px;
    }

    .hero-left p a {
        color: var(--accent);
        font-weight: 600;
    }

    .hero-left p a:hover {
        text-decoration: underline;
    }

    .hero-stats {
        display: flex;
        gap: 44px;
        padding-top: 22px;
        border-top: 1px solid var(--border);
    }

    .hero-stats .stat {
        text-align: left;
    }

    .hero-stats .stat .number {
        font-family: var(--font-mono);
        font-size: 26px;
        font-weight: 600;
        color: var(--text);
        transition: transform 0.3s ease;
    }

    .hero-stats .stat:hover .number {
        transform: translateY(-2px);
        color: var(--accent);
    }

    .hero-stats .stat .label {
        font-size: 12px;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-top: 2px;
    }

    /* ============================================
       UPLOAD CARD
       ============================================ */
    .upload-card {
        background: var(--bg-card);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        padding: 30px;
        animation: fadeInUp 0.7s cubic-bezier(0.16, 1, 0.3, 1) 0.25s both;
        box-shadow: var(--shadow);
        transition: border-color 0.3s ease, box-shadow 0.3s ease;
    }

    .upload-card:hover {
        border-color: rgba(201, 138, 70, 0.35);
    }

    .upload-card .label {
        font-family: var(--font-mono);
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: var(--text-muted);
        font-weight: 500;
    }

    .upload-card .title {
        font-size: 19px;
        font-weight: 700;
        margin-top: 4px;
        font-family: var(--font-display);
    }

    .upload-card .sub {
        font-size: 13.5px;
        color: var(--text-secondary);
        margin-bottom: 20px;
    }

    /* ============================================
       UPLOAD ZONE — viewfinder motif
       ============================================ */
    .upload-zone {
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 34px 22px;
        text-align: center;
        background: var(--bg-input);
        transition: all 0.3s ease;
        cursor: pointer;
        position: relative;
    }

    /* corner brackets */
    .upload-zone::before,
    .upload-zone::after,
    .upload-zone .bracket-tr,
    .upload-zone .bracket-bl {
        content: '';
        position: absolute;
        width: 16px;
        height: 16px;
        border: 2px solid rgba(201, 138, 70, 0.35);
        transition: all 0.3s ease;
        opacity: 0.7;
    }

    .upload-zone::before {
        top: 10px;
        left: 10px;
        border-right: none;
        border-bottom: none;
    }

    .upload-zone::after {
        bottom: 10px;
        right: 10px;
        border-left: none;
        border-top: none;
    }

    .upload-zone .bracket-tr {
        top: 10px;
        right: 10px;
        border-left: none;
        border-bottom: none;
    }

    .upload-zone .bracket-bl {
        bottom: 10px;
        left: 10px;
        border-right: none;
        border-top: none;
    }

    .upload-zone:hover {
        border-color: rgba(201, 138, 70, 0.3);
        background: #171c25;
    }

    .upload-zone:hover::before,
    .upload-zone:hover::after,
    .upload-zone:hover .bracket-tr,
    .upload-zone:hover .bracket-bl {
        border-color: var(--accent);
        opacity: 1;
        width: 20px;
        height: 20px;
    }

    .upload-zone.dragover {
        border-color: var(--accent);
        background: rgba(201, 138, 70, 0.06);
    }

    .upload-zone.dragover::before,
    .upload-zone.dragover::after,
    .upload-zone.dragover .bracket-tr,
    .upload-zone.dragover .bracket-bl {
        border-color: var(--accent);
        width: 24px;
        height: 24px;
    }

    .upload-zone .icon {
        font-size: 30px;
        display: block;
        margin-bottom: 10px;
        color: var(--accent);
        opacity: 0.85;
        transition: transform 0.3s ease;
    }

    .upload-zone:hover .icon {
        transform: scale(1.08);
    }

    .upload-zone h4 {
        font-size: 15.5px;
        font-weight: 600;
        font-family: var(--font-body);
    }

    .upload-zone .hint {
        font-size: 13px;
        color: var(--text-secondary);
        margin-top: 3px;
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
        font-family: var(--font-mono);
        font-size: 11px;
        letter-spacing: 0.05em;
        margin: 16px 0;
        position: relative;
        display: flex;
        align-items: center;
        gap: 16px;
    }

    .upload-zone .divider::before,
    .upload-zone .divider::after {
        content: '';
        flex: 1;
        height: 1px;
        background: var(--border);
    }

    .choose-btn {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        padding: 10px 24px;
        border-radius: 7px;
        border: 1px solid var(--border-light);
        background: transparent;
        color: var(--text-secondary);
        font-size: 13.5px;
        font-weight: 500;
        transition: all 0.25s ease;
        cursor: pointer;
    }

    .choose-btn:hover {
        background: rgba(233, 238, 245, 0.05);
        border-color: var(--accent);
        color: var(--text);
        transform: translateY(-2px);
    }

    .choose-btn i {
        color: var(--accent);
        font-size: 15px;
    }

    .url-row {
        display: flex;
        gap: 8px;
        max-width: 100%;
        margin: 0 auto;
        align-items: center;
    }

    .url-row input {
        flex: 1;
        padding: 10px 14px;
        border-radius: 7px;
        border: 1px solid var(--border);
        background: var(--bg);
        color: var(--text);
        font-size: 13px;
        font-family: var(--font-mono);
        outline: none;
        transition: border-color 0.25s ease;
        min-width: 0;
    }

    .url-row input:focus {
        border-color: var(--accent);
        box-shadow: 0 0 0 3px var(--accent-soft);
    }

    .url-row input::placeholder {
        color: var(--text-muted);
        font-family: var(--font-body);
    }

    .url-row .analyze-btn {
        padding: 10px 20px;
        border-radius: 7px;
        border: none;
        background: linear-gradient(155deg, var(--accent), var(--accent-deep));
        color: #16110a;
        font-weight: 600;
        font-size: 13px;
        transition: all 0.25s ease;
        white-space: nowrap;
        display: flex;
        align-items: center;
        gap: 6px;
        cursor: pointer;
        flex-shrink: 0;
    }

    .url-row .analyze-btn:hover:not(:disabled) {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(201, 138, 70, 0.35);
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
        font-size: 12.5px;
        display: flex;
        align-items: center;
        gap: 8px;
        text-align: left;
        transition: border-color 0.25s ease;
    }

    .context-hint:hover {
        border-color: rgba(201, 138, 70, 0.3);
    }

    .context-hint i {
        color: var(--accent);
    }

    #imageInput {
        display: none;
    }

    /* ============================================
       UPLOAD DROPDOWN
       ============================================ */
    .upload-dropdown {
        display: none;
        position: absolute;
        bottom: calc(100% + 10px);
        left: 50%;
        transform: translateX(-50%);
        background: var(--bg-card);
        border: 1px solid var(--border-light);
        border-radius: var(--radius);
        padding: 8px;
        min-width: 260px;
        box-shadow: var(--shadow);
        z-index: 50;
        animation: slideUp 0.22s cubic-bezier(0.16, 1, 0.3, 1);
    }

    .upload-dropdown.show {
        display: block;
    }

    @keyframes slideUp {
        from {
            opacity: 0;
            transform: translateX(-50%) translateY(8px);
        }

        to {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }
    }

    .upload-dropdown .option {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px 14px;
        border-radius: 7px;
        cursor: pointer;
        transition: all 0.18s ease;
        color: var(--text-secondary);
        font-size: 13px;
        font-weight: 500;
    }

    .upload-dropdown .option:hover {
        background: var(--accent-soft);
        color: var(--text);
    }

    .upload-dropdown .option .icon {
        font-size: 15px;
        width: 26px;
        text-align: center;
        flex-shrink: 0;
        color: var(--accent);
        opacity: 1;
        margin: 0;
    }

    .upload-dropdown .option .label {
        flex: 1;
    }

    .upload-dropdown .option .shortcut {
        font-family: var(--font-mono);
        font-size: 10.5px;
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

    /* ============================================
       UPLOAD PROGRESS
       ============================================ */
    .upload-progress {
        display: none;
        margin-top: 16px;
        padding: 16px;
        background: var(--bg-input);
        border-radius: var(--radius);
        border: 1px solid var(--border);
        transition: all 0.3s ease;
    }

    .upload-progress .status {
        font-size: 13px;
        color: var(--text-secondary);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .upload-progress .status .file-name {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        color: var(--text-muted);
        font-family: var(--font-mono);
        font-size: 11.5px;
        max-width: 260px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .upload-progress .status .file-name i {
        color: var(--accent);
        flex-shrink: 0;
    }

    .upload-progress .status .progress-text {
        font-family: var(--font-mono);
        font-weight: 600;
        color: var(--accent);
    }

    .upload-progress .progress-bar {
        width: 100%;
        height: 3px;
        background: var(--border);
        border-radius: 2px;
        overflow: hidden;
        margin-top: 10px;
    }

    .upload-progress .progress-bar .fill {
        height: 100%;
        background: linear-gradient(90deg, var(--accent-deep), var(--accent));
        border-radius: 2px;
        transition: width 0.5s ease;
        width: 0%;
    }

    /* ============================================
       TOAST
       ============================================ */
    .toast {
        position: fixed;
        bottom: 30px;
        right: 30px;
        z-index: 1000;
        background: var(--bg-card);
        border: 1px solid var(--border-light);
        border-radius: var(--radius);
        padding: 14px 20px;
        display: flex;
        align-items: center;
        gap: 12px;
        box-shadow: var(--shadow);
        transform: translateY(140%);
        transition: transform 0.5s cubic-bezier(0.16, 1, 0.3, 1);
        max-width: 420px;
        min-width: 240px;
    }

    .toast.show {
        transform: translateY(0);
    }

    .toast .icon {
        font-size: 17px;
        flex-shrink: 0;
    }

    .toast .icon.success {
        color: var(--success);
    }

    .toast .icon.error {
        color: var(--danger);
    }

    .toast .icon.info {
        color: var(--chart);
    }

    .toast .icon.warning {
        color: var(--warn);
    }

    .toast .message {
        font-size: 13.5px;
        color: var(--text-secondary);
        flex: 1;
    }

    .toast .close-toast {
        background: none;
        border: none;
        color: var(--text-muted);
        font-size: 15px;
        cursor: pointer;
        padding: 0 4px;
    }

    /* ============================================
       CONTENT SECTION
       ============================================ */
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

    .reveal {
        opacity: 0;
        transform: translateY(16px);
        transition: opacity 0.6s cubic-bezier(0.16, 1, 0.3, 1), transform 0.6s cubic-bezier(0.16, 1, 0.3, 1);
    }

    .reveal.in-view {
        opacity: 1;
        transform: translateY(0);
    }

    .feature-box {
        background: var(--bg-card);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 22px 26px;
        margin-bottom: 14px;
        transition: all 0.3s ease;
    }

    .feature-box:hover {
        border-color: rgba(201, 138, 70, 0.3);
        transform: translateY(-2px);
    }

    .feature-box .icon {
        font-size: 18px;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 34px;
        height: 34px;
        border-radius: 8px;
        background: var(--accent-soft);
        color: var(--accent);
        margin-bottom: 12px;
    }

    .feature-box h3 {
        font-size: 15.5px;
        font-weight: 600;
        font-family: var(--font-display);
        margin-bottom: 4px;
    }

    .feature-box p {
        color: var(--text-secondary);
        font-size: 13.5px;
        line-height: 1.6;
    }

    .section-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 16px;
    }

    .section-header h2 {
        font-family: var(--font-display);
        font-size: 19px;
        font-weight: 600;
    }

    .section-header a {
        color: var(--accent);
        font-size: 13px;
        font-weight: 500;
        transition: color 0.25s ease;
    }

    .section-header a:hover {
        color: var(--text);
    }

    #recentGrid,
    #popularGrid {
        display: grid;
        gap: 12px;
    }

    #recentGrid {
        grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    }

    #popularGrid {
        grid-template-columns: 1fr;
    }

    .recent-item {
        background: var(--bg-card);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 12px;
        transition: all 0.25s ease;
        cursor: pointer;
        text-decoration: none;
        color: inherit;
    }

    .recent-item:hover {
        border-color: rgba(201, 138, 70, 0.3);
        transform: translateY(-2px);
    }

    .recent-item .thumb {
        height: 86px;
        border-radius: 6px;
        background: var(--bg-input);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 22px;
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
        font-size: 13.5px;
        font-weight: 600;
    }

    .recent-item .loc {
        font-size: 11.5px;
        color: var(--text-secondary);
    }

    .recent-item .conf {
        font-family: var(--font-mono);
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
        transition: all 0.25s ease;
    }

    .popular-item:hover {
        border-color: rgba(201, 138, 70, 0.3);
        transform: translateX(4px);
    }

    .popular-item .rank {
        font-family: var(--font-mono);
        font-size: 15px;
        font-weight: 600;
        color: var(--text-muted);
        min-width: 24px;
    }

    .popular-item .info .name {
        font-size: 13.5px;
        font-weight: 500;
    }

    .popular-item .info .country {
        font-size: 11.5px;
        color: var(--text-secondary);
    }

    .popular-item .count {
        font-family: var(--font-mono);
        font-size: 11.5px;
        color: var(--text-secondary);
        margin-left: auto;
        background: var(--accent-soft);
        color: var(--accent);
        padding: 2px 10px;
        border-radius: 12px;
        font-weight: 600;
    }

    .empty-state {
        grid-column: 1/-1;
        text-align: center;
        padding: 40px 20px;
        color: var(--text-muted);
    }

    .empty-state i {
        font-size: 30px;
        opacity: 0.3;
        display: block;
        margin-bottom: 10px;
    }

    .footer {
        max-width: 1200px;
        margin: 0 auto;
        padding: 22px 48px 34px;
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
        font-family: var(--font-mono);
        font-size: 12px;
        letter-spacing: 0.03em;
        color: var(--text-muted);
    }

    .footer .links {
        display: flex;
        gap: 24px;
    }

    .footer .links a {
        color: var(--text-secondary);
        font-size: 13px;
        transition: color 0.25s ease;
    }

    .footer .links a:hover {
        color: var(--text);
    }

    /* ============================================
       ACCESSIBILITY
       ============================================ */
    a:focus-visible,
    button:focus-visible,
    input:focus-visible,
    [tabindex]:focus-visible {
        outline: 2px solid var(--accent);
        outline-offset: 2px;
    }

    /* ============================================
       RESPONSIVE
       ============================================ */
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
            gap: 10px;
        }

        .navbar .nav-links {
            display: none;
        }

        .hero {
            padding: 32px 20px 26px;
        }

        .hero-stats {
            gap: 22px;
            flex-wrap: wrap;
        }

        .hero-stats .stat .number {
            font-size: 21px;
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
        .upload-zone .icon {
            font-size: 26px;
        }

        .upload-zone h4 {
            font-size: 14px;
        }

        .context-hint {
            font-size: 11.5px;
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

        .hero-stats {
            gap: 18px;
        }
    }
    </style>
</head>

<body>

    <div class="bg-glow"></div>

    <!-- TOAST -->
    <div class="toast" id="toast">
        <span class="icon" id="toastIcon"><i class="fas fa-circle-check"></i></span>
        <span class="message" id="toastMessage">Success!</span>
        <button class="close-toast" id="toastClose" aria-label="Dismiss notification">✕</button>
    </div>

    <!-- NAVBAR -->
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
                <i class="fas fa-location-crosshairs"></i> Start Analysis
            </a>
            @endauth
        </div>
    </nav>

    <!-- HERO -->
    <section class="hero">
        <!-- LEFT -->
        <div class="hero-left">
            <div class="badge">
                <span class="dot"></span> PHOTO → COORDINATES
                <span id="coordReadout">00.0000, 00.0000</span>
            </div>
            <h1>
                Find where a<br>
                <span class="highlight">photo was taken.</span>
            </h1>
            <p>
                @auth
                Upload an image and TraceGeo reads the visual evidence — architecture,
                vegetation, signage, light — and returns a location, a confidence
                score, and the reasoning behind it.
                @else
                <strong>Please <a href="/login">sign in</a> or
                    <a href="/register">create an account</a></strong>
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
                <span class="bracket-tr"></span>
                <span class="bracket-bl"></span>
                <span class="icon"><i class="fas fa-crosshairs"></i></span>
                <h4>Drop an image here</h4>
                <p class="hint">Click to <span id="uploadTrigger">see upload options</span></p>

                <!-- UPLOAD OPTIONS DROPDOWN -->
                <div class="upload-dropdown" id="uploadDropdown">
                    <div class="option" data-action="file">
                        <span class="icon"><i class="fas fa-folder-open"></i></span>
                        <span class="label">Upload File</span>
                        <span class="shortcut">⌘U</span>
                    </div>
                    <div class="option" data-action="url">
                        <span class="icon"><i class="fas fa-link"></i></span>
                        <span class="label">Paste URL</span>
                        <span class="shortcut">⌘P</span>
                    </div>
                    <div class="divider-line"></div>
                    <div class="option" data-action="drag">
                        <span class="icon"><i class="fas fa-arrows-rotate"></i></span>
                        <span class="label">Drag & Drop</span>
                        <span class="shortcut">↕</span>
                    </div>
                </div>

                <div class="divider">OR</div>

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
                    Add any context you know (country, source, period)
                </div>
            </div>

            <!-- Upload Progress -->
            <div class="upload-progress" id="uploadProgress">
                <div class="status">
                    <span id="uploadStatusText">Uploading...</span>
                    <span class="progress-text" id="uploadPercent">0%</span>
                </div>
                <div class="status" style="margin-top:6px;font-size:12px;">
                    <span class="file-name"><i class="fas fa-paperclip"></i><span id="fileNameText"></span></span>
                </div>
                <div class="progress-bar">
                    <div class="fill" id="uploadProgressFill"></div>
                </div>
            </div>
            @else
            <div style="text-align:center;padding:40px 20px;">
                <div style="font-size:40px;margin-bottom:16px;color:var(--accent);"><i class="fas fa-lock"></i></div>
                <h3 style="font-size:19px;font-weight:600;font-family:var(--font-display);margin-bottom:8px;">Sign in
                    required</h3>
                <p style="color:var(--text-secondary);font-size:13.5px;margin-bottom:20px;">
                    Sign in or create an account to run TraceGeo's image geolocation analysis.
                </p>
                <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
                    <a href="/login" class="btn btn-primary">Sign In</a>
                    <a href="/register" class="btn btn-outline">Create Account</a>
                </div>
            </div>
            @endauth
        </div>
    </section>

    <!-- CONTENT -->
    <section class="content">
        <div>
            <h2 style="font-family:var(--font-display);font-size:19px;font-weight:600;margin-bottom:16px;">How TraceGeo
                Works</h2>
            <div class="feature-box reveal">
                <span class="icon"><i class="fas fa-magnifying-glass"></i></span>
                <h3>Visual Evidence Scan</h3>
                <p>Extract geolocation clues from imagery, metadata, and visual markers.</p>
            </div>
            <div class="feature-box reveal">
                <span class="icon"><i class="fas fa-location-dot"></i></span>
                <h3>Coordinate Recovery</h3>
                <p>Pinpoint exact coordinates with confidence scoring and evidence.</p>
            </div>
            <div class="feature-box reveal">
                <span class="icon"><i class="fas fa-brain"></i></span>
                <h3>AI Reasoning</h3>
                <p>Analyzes architecture, vegetation, signage, and light to explain its call.</p>
            </div>
        </div>
        <div>
            <div class="section-header">
                <h2>Recent Analyses</h2>
                <a href="/history">View all →</a>
            </div>
            <div id="recentGrid">
                @auth
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>No recent analyses yet.<br>Upload your first photo to get started!</p>
                </div>
                @else
                <div class="empty-state">
                    <i class="fas fa-lock"></i>
                    <p><a href="/login" style="color:var(--accent);">Sign in</a> to see your recent analyses</p>
                </div>
                @endauth
            </div>
            <div style="margin-top:32px;">
                <div class="section-header">
                    <h2>Popular Landmarks</h2>
                </div>
                <div id="popularGrid">
                    <div class="empty-state">
                        <i class="fas fa-globe"></i>
                        <p>No popular landmarks yet.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- FOOTER -->
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

    <script>
    // ============================================================
    //  TRACEGEO - ENHANCED HOMEPAGE (async upload)
    // ============================================================

    const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    console.log('🚀 TraceGeo Homepage loaded');

    // ============================================
    // TOAST SYSTEM
    // ============================================
    function showToast(message, type = 'success') {
        const toast = document.getElementById('toast');
        const icon = document.getElementById('toastIcon');
        const msg = document.getElementById('toastMessage');

        const icons = {
            success: 'fa-circle-check',
            error: 'fa-circle-exclamation',
            info: 'fa-circle-info',
            warning: 'fa-triangle-exclamation'
        };
        icon.innerHTML = `<i class="fas ${icons[type] || icons.success}"></i>`;
        icon.className = `icon ${type}`;
        msg.textContent = message;
        toast.classList.add('show');

        clearTimeout(toast._hideTimeout);
        toast._hideTimeout = setTimeout(() => {
            toast.classList.remove('show');
        }, 4000);
    }

    document.getElementById('toastClose')?.addEventListener('click', () => {
        document.getElementById('toast')?.classList.remove('show');
    });

    // ============================================
    // DOM REFS
    // ============================================
    const DOM = {
        uploadArea: document.getElementById('uploadArea'),
        uploadTrigger: document.getElementById('uploadTrigger'),
        uploadDropdown: document.getElementById('uploadDropdown'),
        imageInput: document.getElementById('imageInput'),
        browseBtn: document.getElementById('browseBtn'),
        urlInput: document.getElementById('imageUrlInput'),
        urlAnalyzeBtn: document.getElementById('urlAnalyzeBtn'),
        uploadProgress: document.getElementById('uploadProgress'),
        uploadProgressFill: document.getElementById('uploadProgressFill'),
        uploadStatusText: document.getElementById('uploadStatusText'),
        uploadPercent: document.getElementById('uploadPercent'),
        fileNameText: document.getElementById('fileNameText'),
    };

    let dropdownOpen = false;

    // ============================================
    // DROPDOWN TOGGLE
    // ============================================
    function toggleDropdown(e) {
        if (e) e.stopPropagation();
        if (!DOM.uploadDropdown) return;
        dropdownOpen = !dropdownOpen;
        DOM.uploadDropdown.classList.toggle('show', dropdownOpen);
    }

    function closeDropdown() {
        if (!DOM.uploadDropdown) return;
        dropdownOpen = false;
        DOM.uploadDropdown.classList.remove('show');
    }

    // ============================================
    // DROPDOWN EVENTS
    // ============================================
    if (DOM.uploadTrigger) {
        DOM.uploadTrigger.addEventListener('click', toggleDropdown);
    }

    if (DOM.uploadArea) {
        DOM.uploadArea.querySelector('.icon')?.addEventListener('click', toggleDropdown);
    }

    document.addEventListener('click', function(e) {
        if (!DOM.uploadArea?.contains(e.target)) {
            closeDropdown();
        }
    });

    // ============================================
    // DROPDOWN OPTIONS
    // ============================================
    document.querySelector('.option[data-action="file"]')?.addEventListener('click', function(e) {
        e.stopPropagation();
        closeDropdown();
        setTimeout(() => DOM.imageInput?.click(), 100);
    });

    document.querySelector('.option[data-action="url"]')?.addEventListener('click', function(e) {
        e.stopPropagation();
        closeDropdown();
        setTimeout(() => DOM.urlInput?.focus(), 100);
    });

    document.querySelector('.option[data-action="drag"]')?.addEventListener('click', function(e) {
        e.stopPropagation();
        closeDropdown();
        showToast('Drag & drop an image file anywhere on the page.', 'info');
    });

    // ============================================
    // DRAG & DROP
    // ============================================
    if (DOM.uploadArea) {
        DOM.uploadArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            DOM.uploadArea.classList.add('dragover');
            closeDropdown();
        });

        DOM.uploadArea.addEventListener('dragleave', () => {
            DOM.uploadArea.classList.remove('dragover');
        });

        DOM.uploadArea.addEventListener('drop', (e) => {
            e.preventDefault();
            DOM.uploadArea.classList.remove('dragover');
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
    DOM.browseBtn?.addEventListener('click', function(e) {
        e.stopPropagation();
        e.preventDefault();
        closeDropdown();
        DOM.imageInput?.click();
    });

    // ============================================
    // FILE INPUT CHANGE
    // ============================================
    DOM.imageInput?.addEventListener('change', function(e) {
        if (this.files.length > 0) {
            const file = this.files[0];
            handleFileUpload(file);
        }
        this.value = '';
    });

    // ============================================
    // URL ANALYZE
    // ============================================
    DOM.urlAnalyzeBtn?.addEventListener('click', function() {
        const url = DOM.urlInput?.value.trim();
        if (!url) {
            showToast('Please paste an image URL.', 'error');
            return;
        }
        closeDropdown();
        handleUrlUpload(url);
    });

    DOM.urlInput?.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            DOM.urlAnalyzeBtn?.click();
        }
    });

    // ============================================
    // ✅ HANDLE FILE UPLOAD (ASYNC)
    // ============================================
    function handleFileUpload(file) {
        if (!file.type.startsWith('image/')) {
            showToast('Please upload an image file.', 'error');
            return;
        }
        if (file.size > 20 * 1024 * 1024) {
            showToast('Image must be under 20MB.', 'error');
            return;
        }

        console.log('📸 Uploading file:', file.name, (file.size / 1024).toFixed(0), 'KB');

        // Show progress
        if (DOM.uploadProgress) DOM.uploadProgress.style.display = 'block';
        if (DOM.uploadProgressFill) DOM.uploadProgressFill.style.width = '0%';
        if (DOM.uploadPercent) DOM.uploadPercent.textContent = '0%';
        if (DOM.uploadStatusText) DOM.uploadStatusText.textContent = 'Preparing upload...';
        if (DOM.fileNameText) DOM.fileNameText.textContent = `${file.name} (${(file.size / 1024).toFixed(0)} KB)`;

        const formData = new FormData();
        formData.append('image', file);

        if (DOM.urlAnalyzeBtn) {
            DOM.urlAnalyzeBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            DOM.urlAnalyzeBtn.disabled = true;
        }

        let progress = 0;
        const progressInterval = setInterval(() => {
            progress += Math.random() * 8;
            if (progress > 90) progress = 90;
            if (DOM.uploadProgressFill) DOM.uploadProgressFill.style.width = progress + '%';
            if (DOM.uploadPercent) DOM.uploadPercent.textContent = Math.round(progress) + '%';
            if (DOM.uploadStatusText) {
                if (progress > 30) DOM.uploadStatusText.textContent = 'Uploading...';
                if (progress > 60) DOM.uploadStatusText.textContent = 'AI analyzing...';
            }
        }, 300);

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

        // ✅ ASYNC endpoint – returns immediately with ID
        fetch('/api/analyze/store', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken || '',
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
            .then(data => {
                clearInterval(progressInterval);
                console.log('📦 Upload response:', data);

                if (DOM.uploadProgressFill) DOM.uploadProgressFill.style.width = '100%';
                if (DOM.uploadPercent) DOM.uploadPercent.textContent = '100%';
                if (DOM.uploadStatusText) DOM.uploadStatusText.textContent = 'Starting analysis...';

                if (data.success && data.id) {
                    sessionStorage.setItem('analysisId', data.id);
                    sessionStorage.setItem('uploadedFileName', file.name);

                    if (data.data?.image_url) {
                        sessionStorage.setItem('uploadedImage', data.data.image_url);
                    }

                    showToast('Analysis started — redirecting...', 'success');

                    setTimeout(() => {
                        window.location.href = '/analysis';
                    }, 1000);
                } else {
                    showToast(data.message || 'Analysis failed. Please try again.', 'error');
                    resetUploadUI();
                }
            })
            .catch(err => {
                clearInterval(progressInterval);
                console.error('❌ Upload error:', err);
                showToast(err.message || 'Network error. Please try again.', 'error');
                resetUploadUI();
            });
    }

    // ============================================
    // ✅ HANDLE URL UPLOAD (ASYNC)
    // ============================================
    function handleUrlUpload(url) {
        console.log('🔗 Fetching URL:', url);

        if (DOM.uploadProgress) DOM.uploadProgress.style.display = 'block';
        if (DOM.uploadProgressFill) DOM.uploadProgressFill.style.width = '0%';
        if (DOM.uploadPercent) DOM.uploadPercent.textContent = '0%';
        if (DOM.uploadStatusText) DOM.uploadStatusText.textContent = 'Fetching image from URL...';
        if (DOM.fileNameText) DOM.fileNameText.textContent = `${url.substring(0, 50)}...`;

        if (DOM.urlAnalyzeBtn) {
            DOM.urlAnalyzeBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            DOM.urlAnalyzeBtn.disabled = true;
        }

        let progress = 0;
        const progressInterval = setInterval(() => {
            progress += Math.random() * 10;
            if (progress > 50) progress = 50;
            if (DOM.uploadProgressFill) DOM.uploadProgressFill.style.width = progress + '%';
            if (DOM.uploadPercent) DOM.uploadPercent.textContent = Math.round(progress) + '%';
        }, 200);

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

        fetch('/api/fetch-image?url=' + encodeURIComponent(url), {
                method: 'GET',
                headers: {
                    'X-CSRF-TOKEN': csrfToken || '',
                    'Accept': 'application/json',
                },
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
            .then(data => {
                clearInterval(progressInterval);
                console.log('📦 Fetch response:', data);

                if (data.success && data.image_data) {
                    if (DOM.uploadProgressFill) DOM.uploadProgressFill.style.width = '60%';
                    if (DOM.uploadPercent) DOM.uploadPercent.textContent = '60%';
                    if (DOM.uploadStatusText) DOM.uploadStatusText.textContent = 'Processing image...';

                    const byteCharacters = atob(data.image_data);
                    const byteNumbers = new Array(byteCharacters.length);
                    for (let i = 0; i < byteCharacters.length; i++) {
                        byteNumbers[i] = byteCharacters.charCodeAt(i);
                    }
                    const byteArray = new Uint8Array(byteNumbers);
                    const blob = new Blob([byteArray], {
                        type: data.mime_type || 'image/jpeg'
                    });
                    const fileName = data.filename || 'image.jpg';
                    const file = new File([blob], fileName, {
                        type: data.mime_type || 'image/jpeg'
                    });

                    if (DOM.uploadProgressFill) DOM.uploadProgressFill.style.width = '80%';
                    if (DOM.uploadPercent) DOM.uploadPercent.textContent = '80%';
                    if (DOM.uploadStatusText) DOM.uploadStatusText.textContent = 'AI analyzing...';

                    const formData = new FormData();
                    formData.append('image', file);

                    fetch('/api/analyze/store', {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': csrfToken || '',
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
                            console.log('📦 Analysis response:', analysisData);

                            if (DOM.uploadProgressFill) DOM.uploadProgressFill.style.width = '100%';
                            if (DOM.uploadPercent) DOM.uploadPercent.textContent = '100%';
                            if (DOM.uploadStatusText) DOM.uploadStatusText.textContent = 'Starting analysis...';

                            if (analysisData.success && analysisData.id) {
                                sessionStorage.setItem('analysisId', analysisData.id);
                                sessionStorage.setItem('uploadedFileName', fileName);

                                if (analysisData.data?.image_url) {
                                    sessionStorage.setItem('uploadedImage', analysisData.data.image_url);
                                }

                                showToast('Analysis started — redirecting...', 'success');

                                setTimeout(() => {
                                    window.location.href = '/analysis';
                                }, 1000);
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
        if (DOM.uploadProgress) DOM.uploadProgress.style.display = 'none';
        if (DOM.urlAnalyzeBtn) {
            DOM.urlAnalyzeBtn.innerHTML = '<i class="fas fa-arrow-right"></i> Analyze';
            DOM.urlAnalyzeBtn.disabled = false;
        }
        if (DOM.uploadProgressFill) DOM.uploadProgressFill.style.width = '0%';
        if (DOM.uploadPercent) DOM.uploadPercent.textContent = '0%';
    }

    // ============================================
    // LOAD DASHBOARD DATA
    // ============================================
    @auth

    async function loadDashboardData() {
        try {
            const response = await fetch('/api/dashboard-data');
            const data = await response.json();

            if (data.success) {
                animateNumber('totalAnalyses', data.stats?.total_analyses || 0);
                animateNumber('uniqueLocations', data.stats?.unique_locations || 0);
                animateNumber('avgConfidence', data.stats?.avg_confidence || 0, '%');

                const recentGrid = document.getElementById('recentGrid');
                if (data.recent && data.recent.length > 0) {
                    recentGrid.innerHTML = data.recent.map(item => {
                        let imageUrl = null;
                        // Prefer image_url (full URL from Cloudinary or asset)
                        if (item.image_url) {
                            imageUrl = item.image_url;
                        } else if (item.image_path) {
                            // If image_path is a full URL, use it directly; otherwise assume it's relative
                            if (item.image_path.startsWith('http://') || item.image_path.startsWith(
                                    'https://')) {
                                imageUrl = item.image_path;
                            } else {
                                imageUrl = '/storage/' + item.image_path;
                            }
                        }

                        return `
                            <a href="/analysis" onclick="sessionStorage.setItem('analysisId', '${item.id}')" class="recent-item">
                                <div class="thumb">
                                    ${imageUrl ? `<img src="${imageUrl}" alt="${item.landmark_name}">` : '<i class="fas fa-image"></i>'}
                                </div>
                                <div class="name">${item.landmark_name || 'Unknown'}</div>
                                <div class="loc">${item.city || ''}${item.city && item.country ? ', ' : ''}${item.country || ''}</div>
                                <div class="conf">${item.confidence || 0}%</div>
                            </a>
                        `;
                    }).join('');
                }

                const popularGrid = document.getElementById('popularGrid');
                if (data.popular && data.popular.length > 0) {
                    popularGrid.innerHTML = data.popular.map((item, index) => `
                            <div class="popular-item">
                                <span class="rank">#${index + 1}</span>
                                <div class="info">
                                    <div class="name">${item.landmark_name || 'Unknown'}</div>
                                    <div class="country">${item.country || ''}</div>
                                </div>
                                <span class="count">${item.count || 0}×</span>
                            </div>
                        `).join('');
                }
            }
        } catch (error) {
            console.error('Failed to load dashboard data:', error);
        }
    }
    @endauth

    // ============================================
    // ANIMATE NUMBER
    // ============================================
    function animateNumber(elementId, target, suffix = '') {
        const el = document.getElementById(elementId);
        if (!el) return;
        if (prefersReducedMotion) {
            el.textContent = Math.round(target) + suffix;
            return;
        }
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

    // ============================================
    // COSMETIC: HERO COORDINATE READOUT
    // ============================================
    (function animateCoordReadout() {
        const el = document.getElementById('coordReadout');
        if (!el || prefersReducedMotion) return;
        const targetLat = 48.8584,
            targetLng = 2.2945;
        let t = 0;
        const steps = 40;
        const interval = setInterval(() => {
            t++;
            const progress = Math.min(t / steps, 1);
            const lat = (targetLat * progress).toFixed(4);
            const lng = (targetLng * progress).toFixed(4);
            el.textContent = `${lat}, ${lng}`;
            if (progress >= 1) clearInterval(interval);
        }, 60);
    })();

    // ============================================
    // SCROLL-REVEAL FOR FEATURE CARDS
    // ============================================
    (function initReveal() {
        const items = document.querySelectorAll('.reveal');
        if (!items.length) return;
        if (prefersReducedMotion || !('IntersectionObserver' in window)) {
            items.forEach(el => el.classList.add('in-view'));
            return;
        }
        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry, i) => {
                if (entry.isIntersecting) {
                    setTimeout(() => entry.target.classList.add('in-view'), i * 80);
                    observer.unobserve(entry.target);
                }
            });
        }, {
            threshold: 0.15
        });
        items.forEach(el => observer.observe(el));
    })();

    // ============================================
    // CHECK FOR RETURNING FROM ANALYSIS
    // ============================================
    const result = sessionStorage.getItem('analysisResult');
    if (result) {
        try {
            const data = JSON.parse(result);
            const fileName = sessionStorage.getItem('uploadedFileName') || 'image';
            showToast(`Found: ${data.landmark_name || 'Location'} from ${fileName}`, 'success');
            setTimeout(() => sessionStorage.removeItem('analysisResult'), 5000);
        } catch (e) {}
    }

    // ============================================
    // LOGOUT
    // ============================================
    document.getElementById('logoutBtn')?.addEventListener('click', async function(e) {
        e.preventDefault();

        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

            const response = await fetch('/logout', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken || '',
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
            });

            const result = await response.json();
            if (result.success) {
                showToast('Logged out successfully!', 'success');
                setTimeout(() => window.location.reload(), 500);
            }
        } catch (error) {
            console.error('Logout error:', error);
            showToast('Logged out successfully!', 'success');
            setTimeout(() => window.location.reload(), 500);
        }
    });

    // ============================================
    // KEYBOARD SHORTCUTS
    // ============================================
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'u') {
            e.preventDefault();
            closeDropdown();
            DOM.imageInput?.click();
        }
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
    console.log('📸 Upload options:');
    console.log('   📁 Upload File - Click "Choose Image" or Ctrl+U');
    console.log('   🔗 Paste URL - Enter URL and click Analyze');
    console.log('   🔄 Drag & Drop - Drop image anywhere');
    console.log('🔄 Upload redirects to /analysis with auto-start');
    console.log('✅ Ready!');
    @else
    console.log('✅ TraceGeo homepage loaded. User is not logged in.');
    console.log('📸 Please login or register to use the analysis features.');
    @endauth
    </script>

</body>

</html>