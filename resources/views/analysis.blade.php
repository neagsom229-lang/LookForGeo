<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TraceGeo — OSINT Analysis</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Space+Grotesk:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap"
        rel="stylesheet">

    <style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    :root {
        --bg-deep: #07070d;
        --bg-card: #0e0e18;
        --bg-panel: #0c0c16;
        --border: rgba(255, 255, 255, 0.06);
        --text: #ffffff;
        --text-secondary: #9ca3af;
        --text-muted: #6b7280;
        --accent: #c98a46;
        --accent-deep: #a86a2e;
        --success: #2dd4bf;
        --cyan: #22d3ee;
        --warning: #fbbf24;
        --danger: #f87171;
        --radius: 12px;
        --radius-lg: 22px;
        --shadow: 0 20px 60px rgba(0, 0, 0, 0.55);
    }

    body {
        font-family: 'Inter', sans-serif;
        background: var(--bg-deep);
        color: var(--text);
        min-height: 100vh;
        overflow: hidden;
    }

    @media (prefers-reduced-motion: reduce) {

        *,
        *::before,
        *::after {
            animation-duration: 0.001ms !important;
            animation-iteration-count: 1 !important;
            transition-duration: 0.001ms !important;
        }
    }

    a:focus-visible,
    button:focus-visible,
    [tabindex]:focus-visible {
        outline: 2px solid var(--accent);
        outline-offset: 2px;
    }

    /* ===== NAVBAR ===== */
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
        background: rgba(7, 7, 13, 0.92);
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
        background: linear-gradient(135deg, var(--accent), var(--accent-deep));
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

        0%,
        100% {
            opacity: 1;
            transform: scale(1);
        }

        50% {
            opacity: 0.3;
            transform: scale(0.7);
        }
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
        background: rgba(255, 255, 255, 0.04);
        color: var(--text-secondary);
        border: 1px solid var(--border);
    }

    .btn-ghost:hover {
        color: var(--text);
        background: rgba(255, 255, 255, 0.08);
    }

    /* ===== STAGE ===== */
    .stage-frame {
        position: fixed;
        top: 68px;
        left: 24px;
        right: 24px;
        bottom: 20px;
        border-radius: var(--radius-lg);
        border: 1px solid rgba(45, 212, 191, 0.2);
        background: #05050a;
        overflow: hidden;
        box-shadow: var(--shadow), 0 0 0 1px rgba(255, 255, 255, 0.02);
        z-index: 5;
    }

    .stage-content {
        position: absolute;
        inset: 0;
    }

    /* ===== STARFIELD GLOBE (Frame 3 & 5) ===== */
        #starfieldCanvas {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        z-index: 1;
        opacity: 0;
        transition: opacity 0.8s ease;
        pointer-events: none;
        margin: 0;
        padding: 0;
        display: block;
    }

    #starfieldCanvas.visible {
        opacity: 1;
    }

    /* ===== FLAT DARK MAP (Frame 2, 4, 6) ===== */
    .map-earth-container {
        position: absolute;
        inset: 0;
        background: #05050a;
        overflow: hidden;
        z-index: 2;
        opacity: 1;
        transition: opacity 0.8s ease;
    }

    .map-earth-container.hidden {
        opacity: 0;
        pointer-events: none;
    }

    .map-earth-container #earthMap {
        width: 100%;
        height: 100%;
        background: #05050a;
    }

    /* Vignette overlay on flat map */
    .map-vignette {
        position: absolute;
        inset: 0;
        pointer-events: none;
        background: radial-gradient(ellipse at center, transparent 40%, rgba(5, 5, 10, 0.75) 100%);
        z-index: 5;
    }

    /* ===== MAP STATUS BAR ===== */
    .map-status {
        position: absolute;
        bottom: 40px;
        left: 50%;
        transform: translateX(-50%);
        z-index: 20;
        text-align: center;
        font-family: 'JetBrains Mono', monospace;
        font-size: 13px;
        color: var(--text-secondary);
        background: rgba(7, 7, 13, 0.75);
        padding: 8px 20px;
        border-radius: 20px;
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.06);
        min-width: 220px;
        transition: all 0.5s ease;
        white-space: nowrap;
    }

    .map-status .highlight {
        color: var(--cyan);
        font-weight: 600;
    }

    .map-status .target-found {
        color: var(--success);
        font-weight: 700;
        animation: pulseText 0.9s ease-in-out infinite;
    }

    @keyframes pulseText {

        0%,
        100% {
            opacity: 1;
        }

        50% {
            opacity: 0.45;
        }
    }

    /* ===== PROBE MARKER ===== */
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
        border-color: rgba(45, 212, 191, 0.3);
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
        box-shadow: 0 0 30px rgba(34, 211, 238, 0.5);
    }

    .probe-marker.target .dot {
        background: var(--success);
        box-shadow: 0 0 40px rgba(45, 212, 191, 0.8);
    }

    .probe-marker.target .ring {
        border-color: var(--success);
    }

    @keyframes probePulse {
        0% {
            transform: translate(-50%, -50%) scale(0.5);
            opacity: 1;
        }

        100% {
            transform: translate(-50%, -50%) scale(2);
            opacity: 0;
        }
    }

    /* ===== GLOBE MODE LABEL ===== */
    .globe-mode-badge {
        position: absolute;
        top: 18px;
        left: 50%;
        transform: translateX(-50%);
        z-index: 20;
        font-family: 'JetBrains Mono', monospace;
        font-size: 11px;
        font-weight: 600;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: var(--cyan);
        background: rgba(34, 211, 238, 0.08);
        border: 1px solid rgba(34, 211, 238, 0.22);
        padding: 5px 16px;
        border-radius: 20px;
        opacity: 0;
        transition: opacity 0.5s ease;
        pointer-events: none;
    }

    .globe-mode-badge.visible {
        opacity: 1;
    }

    /* ===== WHITE FLASH ===== */
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
        0% {
            opacity: 0;
        }

        45% {
            opacity: 1;
        }

        100% {
            opacity: 0;
        }
    }

    /* ===== PROGRESS CARD ===== */
    .progress-card {
        position: absolute;
        left: 24px;
        bottom: 24px;
        z-index: 30;
        width: 420px;
        max-width: calc(100% - 48px);
        background: rgba(8, 8, 14, 0.93);
        backdrop-filter: blur(20px);
        border: 1px solid rgba(255, 255, 255, 0.09);
        border-radius: 16px;
        padding: 16px 20px;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
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
        background: rgba(255, 255, 255, 0.08);
        border-radius: 2px;
        overflow: hidden;
        margin-bottom: 12px;
    }

    .pc-bar .fill {
        height: 100%;
        width: 0%;
        background: linear-gradient(90deg, var(--success), var(--cyan));
        border-radius: 2px;
        transition: width 0.5s ease;
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

    .pc-error {
        margin-top: 10px;
        font-size: 11.5px;
        color: var(--danger);
        display: none;
    }

    /* Current city scanning label inside progress card */
    .pc-scanning {
        font-family: 'JetBrains Mono', monospace;
        font-size: 10px;
        color: var(--cyan);
        margin-top: 6px;
        min-height: 14px;
        letter-spacing: 0.05em;
    }

    /* ===== RESULTS SPLIT (Frame 7 & 8) ===== */
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
        from {
            opacity: 0;
        }

        to {
            opacity: 1;
        }
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
        background: rgba(8, 8, 14, 0.82);
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
        background: rgba(8, 8, 14, 0.82);
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
        background: rgba(8, 8, 14, 0.87);
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
        background: rgba(45, 212, 191, 0.1);
    }

    /* Avatar marker on result map */
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
        0% {
            transform: scale(0.7);
            opacity: 0.9;
        }

        100% {
            transform: scale(1.9);
            opacity: 0;
        }
    }

    .avatar-marker .photo {
        width: 100%;
        height: 100%;
        border-radius: 50%;
        border: 3px solid #fff;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.5);
        background: #222 center/cover no-repeat;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        overflow: hidden;
    }

    .marker-drop {
        animation: markerDrop 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) both;
    }

    @keyframes markerDrop {
        0% {
            transform: translateY(-100px) scale(0.5);
            opacity: 0;
        }

        65% {
            transform: translateY(6px) scale(1.06);
            opacity: 1;
        }

        100% {
            transform: translateY(0) scale(1);
        }
    }

    /* Street View inline */
    .street-inline {
        position: absolute;
        inset: 0;
        background: #0a0a12;
        z-index: 20 !important;
    }

    .street-inline iframe {
        width: 100%;
        height: 100%;
        border: none;
        display: block;
    }

    .street-back {
        position: absolute;
        top: 16px;
        left: 16px;
        z-index: 20;
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: rgba(8, 8, 14, 0.87);
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
        background: rgba(45, 212, 191, 0.15);
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
        background: rgba(8, 8, 14, 0.87);
        border: 1px solid var(--border);
        padding: 8px 16px;
        border-radius: 20px;
        cursor: pointer;
        font-family: 'Inter', sans-serif;
        transition: 0.2s;
    }

    .street-open-full:hover {
        border-color: var(--success);
        background: rgba(45, 212, 191, 0.1);
    }

    .street-setup-notice {
        position: absolute;
        inset: 0;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-align: center;
        gap: 10px;
        padding: 32px;
        background: radial-gradient(ellipse at center, #101018 0%, #05050a 75%);
    }

    .street-setup-notice i.fa-street-view {
        font-size: 34px;
        color: var(--success);
        opacity: 0.85;
        margin-bottom: 4px;
    }

    .street-setup-notice h4 {
        font-family: 'Space Grotesk', sans-serif;
        font-size: 17px;
        font-weight: 700;
        color: var(--text);
    }

    .street-setup-notice p {
        font-size: 13px;
        color: var(--text-secondary);
        max-width: 360px;
        line-height: 1.6;
    }

    .street-setup-cta {
        margin-top: 6px;
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 13px;
        font-weight: 600;
        color: #0a0a12;
        background: var(--success);
        border: none;
        padding: 10px 20px;
        border-radius: 20px;
        cursor: pointer;
        font-family: 'Inter', sans-serif;
        transition: 0.2s;
    }

    .street-setup-cta:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 24px rgba(45, 212, 191, 0.3);
    }

    /* ===== DATA PANE (right panel) ===== */
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
        background: rgba(255, 255, 255, 0.1);
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
        background: rgba(45, 212, 191, 0.12);
        border: 1px solid rgba(45, 212, 191, 0.28);
        color: var(--success);
    }

    .verified-pill.medium {
        background: rgba(251, 191, 36, 0.12);
        border: 1px solid rgba(251, 191, 36, 0.28);
        color: var(--warning);
    }

    .verified-pill.low {
        background: rgba(248, 113, 113, 0.12);
        border: 1px solid rgba(248, 113, 113, 0.28);
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
        background: rgba(255, 255, 255, 0.03);
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

    .action-row {
        display: flex;
        gap: 8px;
        margin-bottom: 10px;
    }

    .action-row button {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        font-size: 12px;
        font-weight: 600;
        padding: 9px 12px;
        border-radius: 9px;
        border: 1px solid var(--border);
        background: rgba(255, 255, 255, 0.03);
        color: var(--text-secondary);
        cursor: pointer;
        font-family: 'Inter', sans-serif;
        transition: 0.2s;
        white-space: nowrap;
    }

    .action-row .action-primary {
        flex: 1.3;
        background: #fff;
        color: #0a0a12;
        border-color: transparent;
    }

    .action-row .action-primary:hover {
        background: #eee;
    }

    .action-row #reuploadBtn {
        flex: 1.3;
    }

    .action-row #saveReportBtn,
    .action-row #shareBtn {
        flex: 0 0 auto;
        width: 38px;
        padding: 9px 0;
    }

    .action-row button:not(.action-primary):hover {
        border-color: var(--success);
        color: var(--text);
    }

    .mode-switch-row {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        background: rgba(255, 255, 255, 0.03);
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 10px 12px;
        margin-bottom: 8px;
    }

    .mode-switch-label {
        font-size: 11.5px;
        font-weight: 600;
        color: var(--text-secondary);
        display: flex;
        align-items: center;
        gap: 6px;
        transition: color 0.2s ease;
    }

    .mode-switch-label.left {
        color: var(--text);
    }

    .mode-switch-row:has(#streetViewSwitch:checked) .mode-switch-label.right {
        color: var(--text);
    }

    .mode-switch-row:has(#streetViewSwitch:checked) .mode-switch-label.left {
        color: var(--text-muted);
    }

    .mode-switch {
        position: relative;
        display: inline-flex;
        cursor: pointer;
    }

    .mode-switch input {
        position: absolute;
        opacity: 0;
        width: 100%;
        height: 100%;
        margin: 0;
        cursor: pointer;
    }

    .mode-switch-track {
        width: 40px;
        height: 22px;
        border-radius: 20px;
        background: rgba(255, 255, 255, 0.12);
        border: 1px solid var(--border-light, rgba(255, 255, 255, 0.15));
        position: relative;
        transition: background 0.25s ease;
    }

    .mode-switch-thumb {
        position: absolute;
        top: 2px;
        left: 2px;
        width: 16px;
        height: 16px;
        border-radius: 50%;
        background: #fff;
        transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1);
    }

    .mode-switch input:checked+.mode-switch-track {
        background: var(--success);
    }

    .mode-switch input:checked+.mode-switch-track .mode-switch-thumb {
        transform: translateX(18px);
    }

    .mode-switch input:focus-visible+.mode-switch-track {
        outline: 2px solid var(--success);
        outline-offset: 2px;
    }

    /* ===== GLOBE → MAP CROSSFADE (plays briefly right after reveal) ===== */
    .globe-transition {
        position: absolute;
        inset: 0;
        z-index: 12;
        background: #05050a;
        opacity: 1;
        pointer-events: none;
        transition: opacity 0.7s ease;
    }

    .globe-transition.fade-out {
        opacity: 0;
    }

    .globe-transition canvas {
        width: 100%;
        height: 100%;
        display: block;
    }

    .globe-transition .probe-marker {
        position: absolute;
        top: 50%;
        left: 50%;
    }

    /* ===== AI VISION SCAN CHIPS (decorative — evokes an object-detection pass over the photo) ===== */
    .vision-chip {
        position: absolute;
        font-family: 'JetBrains Mono', monospace;
        font-size: 8.5px;
        font-weight: 600;
        letter-spacing: 0.02em;
        color: #7ee8d8;
        background: rgba(5, 8, 10, 0.72);
        border: 1px solid rgba(45, 212, 191, 0.4);
        padding: 2px 6px;
        border-radius: 3px;
        white-space: nowrap;
        pointer-events: none;
        backdrop-filter: blur(2px);
        opacity: 0;
        animation: chipIn 0.4s ease forwards;
    }

    @keyframes chipIn {
        from {
            opacity: 0;
            transform: translateY(3px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
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
        background: rgba(255, 255, 255, 0.03);
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
        background: rgba(255, 255, 255, 0.95);
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
        background: rgba(45, 212, 191, 0.16);
        border: 1px solid rgba(45, 212, 191, 0.35);
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
        background: rgba(255, 255, 255, 0.03);
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
        background: rgba(201, 138, 70, 0.12);
        border: 1px solid rgba(201, 138, 70, 0.3);
        color: #e8c493;
    }

    /* ===== RESPONSIVE ===== */
    @media (max-width: 900px) {
        .results-split {
            flex-direction: column;
        }

        .sat-pane {
            flex: none;
            height: 48%;
        }

        .data-pane {
            width: 100%;
            min-width: unset;
            height: 52%;
            border-left: none;
            border-top: 1px solid var(--border);
        }

        .progress-card {
            width: calc(100% - 32px);
            left: 16px;
            bottom: 16px;
            padding: 13px 16px;
        }

        .stage-frame {
            top: 60px;
            left: 12px;
            right: 12px;
            bottom: 12px;
        }

        .navbar {
            padding: 10px 16px;
            flex-wrap: wrap;
            gap: 6px;
        }

        .tagline {
            display: none;
        }

        .map-status {
            font-size: 11px;
            padding: 6px 14px;
            bottom: 30px;
            min-width: 160px;
        }
    }

    @media (max-width: 600px) {
        .pc-headline {
            font-size: 14px;
        }

        .pc-steps .pcs {
            font-size: 8px;
        }

        .map-status {
            font-size: 10px;
            padding: 4px 10px;
            bottom: 20px;
            min-width: 120px;
        }

        .place-title {
            font-size: 20px;
        }

        .photo-frame {
            height: 100px;
        }
    }

    /* 3D Globe & Street View Active State */
    #globeBtnPane.active,
    #streetBtnPane.active {
        background: rgba(45, 212, 191, 0.15) !important;
        border-color: var(--success) !important;
        color: var(--success) !important;
        box-shadow: 0 0 10px rgba(45, 212, 191, 0.3);
    }

    /* ONE-BUTTON TOGGLE CSS */
    .view-toggle-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        font-size: 13px;
        font-weight: 600;
        color: var(--text);
        background: rgba(8, 8, 14, 0.87);
        backdrop-filter: blur(10px);
        border: 1px solid var(--border);
        padding: 10px 24px;
        border-radius: 20px;
        cursor: pointer;
        transition: all 0.2s;
        font-family: 'Inter', sans-serif;
        min-width: 160px;
    }

    .view-toggle-btn:hover {
        border-color: var(--success);
        background: rgba(45, 212, 191, 0.1);
    }

    .view-toggle-btn.active {
        background: rgba(45, 212, 191, 0.15);
        border-color: var(--success);
        color: var(--success);
        box-shadow: 0 0 10px rgba(45, 212, 191, 0.3);
    }

    .view-toggle-btn.street-mode {
        background: rgba(34, 211, 238, 0.15);
        border-color: var(--cyan);
        color: var(--cyan);
        box-shadow: 0 0 10px rgba(34, 211, 238, 0.3);
    }

    /* Professional Segmented Control */
    .view-toggle-group {
        display: flex;
        gap: 4px;
        padding: 4px;
        background: rgba(8, 8, 14, 0.87);
        backdrop-filter: blur(10px);
        border: 1px solid var(--border);
        border-radius: 12px;
    }

    .view-toggle-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        font-size: 12.5px;
        font-weight: 600;
        padding: 8px 16px;
        border-radius: 8px;
        cursor: pointer;
        color: var(--text-secondary);
        background: transparent;
        border: 1px solid transparent;
        transition: all 0.25s ease;
        font-family: 'Inter', sans-serif;
    }

    /* 3D Globe Active State (Green - "Red Green" style) */
    #globeToggleBtn.active {
        background: rgba(45, 212, 191, 0.15);
        border-color: var(--success);
        color: var(--success);
        box-shadow: 0 0 8px rgba(45, 212, 191, 0.3);
    }

    /* Street View Active State (Cyan - for the real-life look) */
    #streetToggleBtn.active {
        background: rgba(34, 211, 238, 0.15);
        border-color: var(--cyan);
        color: var(--cyan);
        box-shadow: 0 0 8px rgba(34, 211, 238, 0.3);
    }

    .view-toggle-btn:hover:not(.active) {
        color: var(--text);
        border-color: var(--border);
    }

    /* ===== MOBILE & RESPONSIVENESS IMPROVEMENTS (Block 1,2,4,7) ===== */

/* Block 1: Let the data pane shrink on mobile */
@media (max-width: 900px) {
    .data-pane {
        min-width: 0 !important; /* Force it to shrink to 100% */
    }
}

/* Block 2: Dynamic viewport for mobile browsers */
body {
    min-height: 100vh;      /* Fallback */
    min-height: 100dvh;     /* Actual visible viewport on mobile */
}

/* Block 4: Prevent view-toggle buttons from overflowing on small screens */
@media (max-width: 480px) {
    .view-toggle-group {
        flex-wrap: wrap; /* Allow buttons to wrap if needed */
        justify-content: center;
    }
    .view-toggle-btn {
        min-width: 0;
        padding: 8px 12px;
        font-size: 11px;
    }
}

/* Block 7: Larger touch targets for mobile */
@media (max-width: 900px) {
    .action-row button,
    .photo-icon-row button {
        padding: 12px;
        min-height: 44px;
    }
}
    </style>
</head>

<body>

    <!-- ===== NAVBAR ===== -->
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
            <a href="/history" class="btn btn-ghost"><i class="fas fa-history"></i></a>
        </div>
    </nav>

    <!-- ===== STAGE FRAME ===== -->
    <div class="stage-frame" id="stageFrame">
        <div class="stage-content" id="stageContent">

            <!-- STARFIELD + 3D GLOBE CANVAS (Frame 3 & 5) -->
            <canvas id="starfieldCanvas"></canvas>

            <!-- FLAT DARK MAP (Frame 2, 4, 6) -->
            <div class="map-earth-container" id="mapEarthContainer">
                <div id="earthMap"></div>
                <div class="map-vignette"></div>
            </div>

            <!-- Globe mode badge -->
            <div class="globe-mode-badge" id="globeModeBadge">⬡ 3D Globe Mode</div>

            <!-- Shared status bar (sits above both layers) -->
            <div class="map-status" id="mapStatus">
                <span class="highlight">●</span>
                <span id="mapStatusText">🌍 Initializing OSINT Engine...</span>
            </div>

            <!-- White flash on reveal -->
            <div class="white-flash" id="whiteFlash"></div>

            <!-- Progress Card -->
            <div class="progress-card" id="progressCard">
                <div class="pc-label">
                    <span class="dot"></span>
                    <span id="pcLabel">ANALYZING</span>
                </div>
                <div class="pc-headline" id="pcHeadline">Initializing analysis pipeline...</div>
                <div class="pc-sub" id="pcSub">Elapsed 0.0s · Progress 0% · Engine TraceGeo AI</div>
                <div class="pc-bar">
                    <div class="fill" id="pcFill"></div>
                </div>
                <div class="pc-steps" id="pcSteps">
                    <span class="pcs" data-step="0"><b>01</b>Input</span>
                    <span class="pcs" data-step="1"><b>02</b>Features</span>
                    <span class="pcs" data-step="2"><b>03</b>Reasoning</span>
                    <span class="pcs" data-step="3"><b>04</b>Cross-ref</span>
                    <span class="pcs" data-step="4"><b>05</b>Locate</span>
                </div>
                <div class="pc-scanning" id="pcScanning"></div>
                <div class="pc-error" id="pcError"></div>
            </div>

            <!-- Results Split (Frame 7 & 8) -->
            <div class="results-split" id="resultsSplit">
                <div class="sat-pane" id="satPane">
                    <div id="resultMap"></div>
                    <div class="globe-transition" id="globeTransition">
                        <canvas id="globeTransitionCanvas"></canvas>
                        <div class="probe-marker target" id="globeTransitionMarker">
                            <div class="ring"></div>
                            <div class="ring"></div>
                            <div class="dot"></div>
                        </div>
                    </div>
                    <div class="pill-brand">TraceGeo</div>
                    <div class="pill-confidence" id="confPill">
                        <span class="dot" style="background:var(--success);"></span>
                        <span id="confPillText">100% Confidence</span>
                    </div>
                    <div class="pane-actions view-toggle-group">
                        <button id="globeToggleBtn" class="view-toggle-btn active">
                            <i class="fas fa-globe-americas"></i> 3D Globe
                        </button>
                        <button id="streetToggleBtn" class="view-toggle-btn">
                            <i class="fas fa-street-view"></i> Street View
                        </button>
                    </div>
                </div>
                <div class="data-pane" id="dataPane">
                    <div class="verified-pill high" id="verifiedPill">
                        <i class="fas fa-check-circle"></i> Identified Location
                    </div>
                    <div class="place-title" id="placeTitle">Location</div>
                    <div class="place-country" id="placeCountry">Country</div>
                    <div class="place-region" id="placeRegion">Region</div>
                    <div class="coord-row">
                        <span id="coordText">0.0000° N, 0.0000° W</span>
                        <button id="copyCoordsBtn" title="Copy coordinates"><i class="fas fa-copy"></i></button>
                    </div>

                    <div class="action-row">
                        <button class="action-primary" id="homeBtn" title="Back to home"><i class="fas fa-house"></i>
                            Home</button>
                        <button id="reuploadBtn" title="Analyze another photo"><i
                                class="fas fa-arrow-up-from-bracket"></i> Reupload</button>
                        <button id="saveReportBtn" title="Save report"><i class="fas fa-folder"></i></button>
                        <button id="shareBtn" title="Share"><i class="fas fa-share-nodes"></i></button>
                    </div>

                    <div class="mode-switch-row">
                        <span class="mode-switch-label left"><i class="fas fa-globe-americas"></i> 3D Globe</span>
                        <label class="mode-switch">
                            <input type="checkbox" id="streetViewSwitch">
                            <span class="mode-switch-track"><span class="mode-switch-thumb"></span></span>
                        </label>
                        <span class="mode-switch-label right"><i class="fas fa-street-view"></i> Street View</span>
                    </div>

                    <div class="pane-toggle-row" id="baseLayerToggleRow">
                        <button class="active" id="roadsToggle"><i class="fas fa-route"></i> Roads</button>
                        <button id="terrainToggle"><i class="fas fa-satellite"></i> Terrain</button>
                    </div>

                    <div class="photo-label">Your Image</div>
                    <div class="photo-frame" id="photoFrame">
                        <div class="no-photo"><i class="fas fa-image"></i></div>
                    </div>
                    <div class="photo-icon-row">
                        <button id="viewFullSizeBtn" title="View full size"><i
                                class="fas fa-magnifying-glass"></i></button>
                        <button id="fullscreenPhotoBtn" title="Fullscreen"><i class="fas fa-expand"></i></button>
                        <button id="reverseSearchBtn" title="Reverse image search"><i
                                class="fas fa-magnifying-glass-location"></i></button>
                        <button id="openOriginalBtn" title="Open original"><i
                                class="fas fa-arrow-up-right-from-square"></i></button>
                    </div>
                    <div class="tag-pills" id="tagPills"></div>
                    <div class="reasoning-block">
                        <div class="reasoning-label"><i class="fas fa-wand-magic-sparkles"></i> Reasoning Analysis</div>
                        <div class="reasoning-text" id="reasoningText">—</div>
                    </div>
                </div>
            </div>

        </div>
    </div>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

    <script>
    // ============================================================
    //  TRACEGEO — STORYBOARD-ACCURATE PROCESSING SEQUENCE
    //
    //  Sequence (matches PDF frames exactly):
    //  Frame 2 → Flat dark map zooms into random city
    //  Frame 3 → Switches to 3D starfield globe view
    //  Frame 4 → Back to flat map, zooms another city
    //  Frame 5 → Globe again
    //  Frame 6 → Flat map, another city
    //  ...alternates until analysis done...
    //  Final   → Cinematic zoom to real target → white flash → results
    // ============================================================

    const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const POLL_INTERVAL_MS = 700;
    const MAX_POLL_ATTEMPTS = 120;
    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // ========== STREET VIEW CONFIG ==========
    // Real, photographic Street View can only be embedded reliably through
    // Google's official Maps Embed API, which needs a key (see setup notes
    // above showStreetView() further down). Put it here, or better, render it
    // from a Blade variable — e.g. '{{ config('services.google_maps.embed_key') }}'
    // — so it isn't hardcoded into a public file.
    const GOOGLE_MAPS_EMBED_KEY = '{{ config("services.google_maps.embed_key") }}';

    const DARK_TILE = 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png';
    const DARK_ATTR = '&copy; <a href="https://carto.com/attributions">CARTO</a>';
    const SAT_URL = 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}';
    const SAT_ATTR = '&copy; ESRI';
    const ROADS_URL = 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png';
    const ROADS_ATTR = '&copy; CARTO';

    // ========== GLOBAL WAYPOINTS (50 cities used for the exploration sequence) ==========
    const GLOBAL_WAYPOINTS = [{
            lat: 35.6762,
            lng: 139.6503,
            name: 'Tokyo, Japan'
        },
        {
            lat: 35.0116,
            lng: 135.7681,
            name: 'Kyoto, Japan'
        },
        {
            lat: 31.2304,
            lng: 121.4737,
            name: 'Shanghai, China'
        },
        {
            lat: 39.9042,
            lng: 116.4074,
            name: 'Beijing, China'
        },
        {
            lat: 22.3193,
            lng: 114.1694,
            name: 'Hong Kong'
        },
        {
            lat: 1.3521,
            lng: 103.8198,
            name: 'Singapore'
        },
        {
            lat: 13.7563,
            lng: 100.5018,
            name: 'Bangkok, Thailand'
        },
        {
            lat: 19.0760,
            lng: 72.8777,
            name: 'Mumbai, India'
        },
        {
            lat: 28.6139,
            lng: 77.2090,
            name: 'New Delhi, India'
        },
        {
            lat: 25.2048,
            lng: 55.2708,
            name: 'Dubai, UAE'
        },
        {
            lat: 41.0082,
            lng: 28.9784,
            name: 'Istanbul, Turkey'
        },
        {
            lat: 51.5074,
            lng: -0.1278,
            name: 'London, UK'
        },
        {
            lat: 48.8566,
            lng: 2.3522,
            name: 'Paris, France'
        },
        {
            lat: 41.9028,
            lng: 12.4964,
            name: 'Rome, Italy'
        },
        {
            lat: 52.5200,
            lng: 13.4050,
            name: 'Berlin, Germany'
        },
        {
            lat: 41.3851,
            lng: 2.1734,
            name: 'Barcelona, Spain'
        },
        {
            lat: 52.3676,
            lng: 4.9041,
            name: 'Amsterdam, Netherlands'
        },
        {
            lat: 37.9838,
            lng: 23.7275,
            name: 'Athens, Greece'
        },
        {
            lat: 55.7558,
            lng: 37.6173,
            name: 'Moscow, Russia'
        },
        {
            lat: 40.7128,
            lng: -74.0060,
            name: 'New York, USA'
        },
        {
            lat: 34.0522,
            lng: -118.2437,
            name: 'Los Angeles, USA'
        },
        {
            lat: 37.7749,
            lng: -122.4194,
            name: 'San Francisco, USA'
        },
        {
            lat: 41.8781,
            lng: -87.6298,
            name: 'Chicago, USA'
        },
        {
            lat: 43.6532,
            lng: -79.3832,
            name: 'Toronto, Canada'
        },
        {
            lat: 19.4326,
            lng: -99.1332,
            name: 'Mexico City, Mexico'
        },
        {
            lat: 36.1699,
            lng: -115.1398,
            name: 'Las Vegas, USA'
        },
        {
            lat: 25.7617,
            lng: -80.1918,
            name: 'Miami, USA'
        },
        {
            lat: -22.9068,
            lng: -43.1729,
            name: 'Rio de Janeiro, Brazil'
        },
        {
            lat: -23.5505,
            lng: -46.6333,
            name: 'Sao Paulo, Brazil'
        },
        {
            lat: -34.6037,
            lng: -58.3816,
            name: 'Buenos Aires, Argentina'
        },
        {
            lat: 30.0444,
            lng: 31.2357,
            name: 'Cairo, Egypt'
        },
        {
            lat: -33.9249,
            lng: 18.4241,
            name: 'Cape Town, South Africa'
        },
        {
            lat: -1.2921,
            lng: 36.8219,
            name: 'Nairobi, Kenya'
        },
        {
            lat: -33.8688,
            lng: 151.2093,
            name: 'Sydney, Australia'
        },
        {
            lat: -37.8136,
            lng: 144.9631,
            name: 'Melbourne, Australia'
        },
        {
            lat: -27.4679,
            lng: 153.0279,
            name: 'Brisbane, Australia'
        },
        {
            lat: -36.8485,
            lng: 174.7633,
            name: 'Auckland, New Zealand'
        },
        {
            lat: 33.8886,
            lng: 35.4955,
            name: 'Beirut, Lebanon'
        },
        {
            lat: 31.6295,
            lng: -7.9811,
            name: 'Marrakech, Morocco'
        },
        {
            lat: 6.5244,
            lng: 3.3792,
            name: 'Lagos, Nigeria'
        },
        {
            lat: 59.3293,
            lng: 18.0686,
            name: 'Stockholm, Sweden'
        },
        {
            lat: 60.1699,
            lng: 24.9384,
            name: 'Helsinki, Finland'
        },
        {
            lat: 49.2827,
            lng: -123.1207,
            name: 'Vancouver, Canada'
        },
        {
            lat: 47.6062,
            lng: -122.3321,
            name: 'Seattle, USA'
        },
        {
            lat: 3.1390,
            lng: 101.6869,
            name: 'Kuala Lumpur, Malaysia'
        },
        {
            lat: 14.5995,
            lng: 120.9842,
            name: 'Manila, Philippines'
        },
        {
            lat: 21.0278,
            lng: 105.8342,
            name: 'Hanoi, Vietnam'
        },
        {
            lat: 45.4642,
            lng: 9.1900,
            name: 'Milan, Italy'
        },
        {
            lat: 48.2082,
            lng: 16.3738,
            name: 'Vienna, Austria'
        },
        {
            lat: 14.7167,
            lng: -17.4677,
            name: 'Dakar, Senegal'
        },
    ];

    // ========== DOM REFS ==========
    const DOM = {
        starfieldCanvas: document.getElementById('starfieldCanvas'),
        mapEarthContainer: document.getElementById('mapEarthContainer'),
        globeModeBadge: document.getElementById('globeModeBadge'),
        mapStatus: document.getElementById('mapStatus'),
        mapStatusText: document.getElementById('mapStatusText'),
        whiteFlash: document.getElementById('whiteFlash'),
        progressCard: document.getElementById('progressCard'),
        pcHeadline: document.getElementById('pcHeadline'),
        pcSub: document.getElementById('pcSub'),
        pcFill: document.getElementById('pcFill'),
        pcSteps: document.getElementById('pcSteps'),
        pcError: document.getElementById('pcError'),
        pcScanning: document.getElementById('pcScanning'),
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
        globeTransition: document.getElementById('globeTransition'),
        globeTransitionCanvas: document.getElementById('globeTransitionCanvas'),
    };

    // ========== STATE ==========
    let resultMapInstance = null;
    let earthMapInstance = null;
    let currentTileMode = 'roads';
    let uploadedImageURL = null;
    let pollTimer = null;
    let currentAnalysisId = null;
    let pollAttempts = 0;
    let consecutiveErrors = 0;
    let startTime = null;
    let elapsedInterval = null;

    let randomWaypoints = [];
    let totalWaypoints = 0;
    let explorationIndex = 0;
    let analysisComplete = false;
    let isExploring = false;
    let lastProgress = 0;

    // Starfield state
    // let starfieldAnim = null;
    // let stars = [];
    // let globeAngle = 0;

    // ========== HELPERS ==========
    function isValidCoord(lat, lng) {
        const a = parseFloat(lat),
            b = parseFloat(lng);
        return !isNaN(a) && !isNaN(b) && a !== 0 && b !== 0 && Math.abs(a) <= 90 && Math.abs(b) <= 180;
    }

    function sleep(ms) {
        return new Promise(r => setTimeout(r, ms));
    }

    function showToast(msg) {
        if (DOM.navStatus) DOM.navStatus.textContent = msg;
    }

    function showError(message) {
        if (DOM.pcError) {
            DOM.pcError.textContent = `⚠️ ${message}`;
            DOM.pcError.style.display = 'block';
        }
        if (DOM.statusDot) {
            DOM.statusDot.style.background = 'var(--danger)';
            DOM.statusDot.style.boxShadow = '0 0 20px var(--danger)';
        }
        showToast('❌ ' + message);
        if (DOM.progressCard) DOM.progressCard.style.display = 'none';
    }

    function shuffleArray(array) {
        const s = [...array];
        for (let i = s.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [s[i], s[j]] = [s[j], s[i]];
        }
        return s;
    }

    // ========== EARTH MAP (flat dark) ==========
    function initEarthMap(center = [20, 0], zoom = 2) {
        try {
            if (earthMapInstance) earthMapInstance.remove();
        } catch (e) {}
        const el = document.getElementById('earthMap');
        if (!el) return;
        earthMapInstance = L.map(el, {
            center,
            zoom,
            zoomControl: false,
            attributionControl: false,
            fadeAnimation: true,
            zoomAnimation: true,
            inertia: true,
            maxBounds: [
                [-90, -180],
                [90, 180]
            ],
        });
        L.tileLayer(DARK_TILE, {
            attribution: DARK_ATTR,
            maxZoom: 19,
            minZoom: 1,
            noWrap: true,
            bounds: [
                [-90, -180],
                [90, 180]
            ]
        }).addTo(earthMapInstance);
        setTimeout(() => {
            if (earthMapInstance) earthMapInstance.invalidateSize();
        }, 300);
    }

    function flyEarthTo(lat, lng, zoom, duration = 1600) {
        return new Promise(resolve => {
            // ----- INTELLIGENT COORDINATE VALIDATION -----
            function isValidCoordinate(lat, lng) {
                const latNum = parseFloat(lat);
                const lngNum = parseFloat(lng);
                return !isNaN(latNum) && !isNaN(lngNum) &&
                    Math.abs(latNum) <= 90 && Math.abs(lngNum) <= 180 &&
                    latNum !== 0 && lngNum !== 0; // 0,0 is likely a fallback, not real
            }

            // ----- SANITISE & FALLBACK -----
            let latNum = parseFloat(lat);
            let lngNum = parseFloat(lng);

            // If invalid, use a safe fallback (Eiffel Tower, Paris)
            if (!isValidCoordinate(latNum, lngNum)) {
                console.warn('⚠️ flyEarthTo: Invalid coordinates received', {
                    lat,
                    lng
                });
                console.warn('   → Using fallback: Eiffel Tower, Paris (48.8584, 2.2945)');
                latNum = 48.8584;
                lngNum = 2.2945;
                zoom = zoom || 12;
            }

            // Clamp zoom to safe range
            const safeZoom = Math.min(Math.max(zoom || 2, 1), 18);

            // ----- EXECUTE FLY -----
            if (!earthMapInstance) {
                console.warn('⚠️ earthMapInstance not initialized');
                resolve();
                return;
            }

            // Clear any existing probe markers to avoid clutter
            try {
                earthMapInstance.eachLayer(l => {
                    if (l instanceof L.Marker && l.options?.probeMarker) {
                        earthMapInstance.removeLayer(l);
                    }
                });
            } catch (e) {
                /* ignore */
            }

            // Perform the flyTo
            earthMapInstance.flyTo([latNum, lngNum], safeZoom, {
                duration: duration / 1000,
                easeLinearity: 0.25
            });

            // Resolve after animation completes
            setTimeout(resolve, duration + 100);
        });
    }

    function addProbeMarker(lat, lng, isTarget = false) {
        if (!earthMapInstance) return;
        earthMapInstance.eachLayer(l => {
            if (l instanceof L.Marker && l.options.probeMarker) earthMapInstance.removeLayer(l);
        });
        const html = `<div class="probe-marker ${isTarget ? 'target' : ''}">
        <div class="ring"></div><div class="ring"></div><div class="dot"></div>
    </div>`;
        const icon = L.divIcon({
            html,
            className: '',
            iconSize: [40, 40],
            iconAnchor: [20, 20]
        });
        L.marker([lat, lng], {
            icon,
            probeMarker: true,
            zIndexOffset: 1000
        }).addTo(earthMapInstance);
    }

 // ========== REAL 3D EARTH (Three.js) ==========
const EARTH_TEXTURE_URL = 'https://cdn.jsdelivr.net/gh/mrdoob/three.js@r128/examples/textures/planets/earth_atmos_2048.jpg';
const EARTH_SPEC_URL    = 'https://cdn.jsdelivr.net/gh/mrdoob/three.js@r128/examples/textures/planets/earth_specular_2048.jpg';
const EARTH_CLOUDS_URL  = 'https://cdn.jsdelivr.net/gh/mrdoob/three.js@r128/examples/textures/planets/earth_clouds_1024.png';
const EARTH_NORMAL_URL  = 'https://cdn.jsdelivr.net/gh/mrdoob/three.js@r128/examples/textures/planets/earth_normal_2048.jpg';

const textureLoader = new THREE.TextureLoader();
let cachedEarthTex, cachedSpecTex, cachedCloudsTex, cachedNormalTex, texturesLoading = null;

function loadEarthTextures() {
    if (texturesLoading) return texturesLoading;
    texturesLoading = Promise.all([
        new Promise(res => textureLoader.load(EARTH_TEXTURE_URL, t => { cachedEarthTex = t; res(); }, undefined, () => res())),
        new Promise(res => textureLoader.load(EARTH_SPEC_URL, t => { cachedSpecTex = t; res(); }, undefined, () => res())),
        new Promise(res => textureLoader.load(EARTH_CLOUDS_URL, t => { cachedCloudsTex = t; res(); }, undefined, () => res())),
        new Promise(res => textureLoader.load(EARTH_NORMAL_URL, t => { cachedNormalTex = t; res(); }, undefined, () => res())),
    ]);
    return texturesLoading;
}

function latLngToVector3(lat, lng, radius) {
    const phi = (90 - lat) * (Math.PI / 180);
    const theta = (lng + 180) * (Math.PI / 180);
    return new THREE.Vector3(
        -radius * Math.sin(phi) * Math.cos(theta),
        radius * Math.cos(phi),
        radius * Math.sin(phi) * Math.sin(theta)
    );
}

function buildEarthScene(canvas) {
    const renderer = new THREE.WebGLRenderer({ canvas, alpha: true, antialias: true });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));

    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(45, 1, 0.1, 1000);
    camera.position.z = 2.6;

    const starGeo = new THREE.BufferGeometry();
    const starCount = 1800;
    const starPositions = new Float32Array(starCount * 3);
    for (let i = 0; i < starCount; i++) {
        const r = 60 + Math.random() * 40;
        const theta = Math.random() * Math.PI * 2;
        const phi = Math.acos((Math.random() * 2) - 1);
        starPositions[i*3]   = r * Math.sin(phi) * Math.cos(theta);
        starPositions[i*3+1] = r * Math.sin(phi) * Math.sin(theta);
        starPositions[i*3+2] = r * Math.cos(phi);
    }
    starGeo.setAttribute('position', new THREE.BufferAttribute(starPositions, 3));
    scene.add(new THREE.Points(starGeo, new THREE.PointsMaterial({ color: 0xffffff, size: 0.09, transparent: true, opacity: 0.75 })));

    const sun = new THREE.DirectionalLight(0xffffff, 1.15);
    sun.position.set(5, 2, 5);
    scene.add(sun);
    scene.add(new THREE.AmbientLight(0x404040, 0.55));

    const earthMat = new THREE.MeshPhongMaterial({ shininess: 12 });
    const earth = new THREE.Mesh(new THREE.SphereGeometry(1, 64, 64), earthMat);
    scene.add(earth);

    const cloudMat = new THREE.MeshPhongMaterial({ transparent: true, opacity: 0.5, depthWrite: false });
    const clouds = new THREE.Mesh(new THREE.SphereGeometry(1.008, 64, 64), cloudMat);
    scene.add(clouds);

    const atmoMat = new THREE.ShaderMaterial({
        transparent: true,
        side: THREE.BackSide,
        vertexShader: `varying vec3 vNormal; void main() { vNormal = normalize(normalMatrix * normal); gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0); }`,
        fragmentShader: `varying vec3 vNormal; void main() { float i = pow(0.68 - dot(vNormal, vec3(0.0,0.0,1.0)), 3.0); gl_FragColor = vec4(0.35,0.75,1.0,1.0) * i; }`,
    });
    scene.add(new THREE.Mesh(new THREE.SphereGeometry(1.09, 64, 64), atmoMat));

    loadEarthTextures().then(() => {
        if (cachedEarthTex) earthMat.map = cachedEarthTex;
        if (cachedNormalTex) earthMat.normalMap = cachedNormalTex;
        if (cachedSpecTex) { earthMat.specularMap = cachedSpecTex; earthMat.specular = new THREE.Color(0x222222); }
        earthMat.needsUpdate = true;
        if (cachedCloudsTex) { cloudMat.map = cachedCloudsTex; cloudMat.needsUpdate = true; }
    });

    function resize() {
        // Use getBoundingClientRect for absolute precision (avoids 0x0 bugs on hidden elements)
        const rect = canvas.getBoundingClientRect();
        const w = rect.width || 1;
        const h = rect.height || 1;
        renderer.setSize(w, h, false);
        camera.aspect = w / h;
        camera.updateProjectionMatrix();
    }
    resize();

    function focusOn(lat, lng) {
        // Stop manual spinning and rotate the Earth so the point faces the camera
        earth.rotation.y = (lng * Math.PI) / 180;
        earth.rotation.x = (lat * Math.PI) / 180 - Math.PI / 2; // Adjust for viewing angle
        // Sync clouds with earth's rotation
        clouds.rotation.y = earth.rotation.y;
        clouds.rotation.x = earth.rotation.x;
    }

    // ✅ CRITICAL: Add focusOn to the returned object
    return { renderer, scene, camera, earth, clouds, resize, focusOn };
}

let earthScene = null;
let earthAnimId = null;
let globeMarkerMesh = null;

function initStarfield() {
    if (!earthScene) {
        earthScene = buildEarthScene(DOM.starfieldCanvas);
        
        // ✅ SUPERIOR RESPONSIVENESS: Observe the canvas itself and force a resize
        const globeResizeObserver = new ResizeObserver(() => {
            if (earthScene) earthScene.resize();
        });
        globeResizeObserver.observe(DOM.starfieldCanvas);
    }
    
    const { renderer, scene, camera, earth, clouds, resize } = earthScene;
    function frame() {
        resize();
        if (!prefersReducedMotion) {
            earth.rotation.y += 0.0016;
            clouds.rotation.y += 0.0021;
        }
        renderer.render(scene, camera);
        earthAnimId = requestAnimationFrame(frame);
    }
    frame();
}

function stopStarfield() {
    if (earthAnimId) { cancelAnimationFrame(earthAnimId); earthAnimId = null; }
}

function setGlobeMarker(lat, lng, isTarget = false) {
    if (!earthScene) return;
    const { earth } = earthScene;
    if (globeMarkerMesh) { earth.remove(globeMarkerMesh); globeMarkerMesh = null; }
    const pos = latLngToVector3(lat, lng, 1.02);
    globeMarkerMesh = new THREE.Mesh(
        new THREE.SphereGeometry(0.018, 16, 16),
        new THREE.MeshBasicMaterial({ color: isTarget ? 0x2dd4bf : 0x22d3ee })
    );
    globeMarkerMesh.position.copy(pos);
    earth.add(globeMarkerMesh);
}

function drawStaticGlobe(canvas) {
    if (!canvas) return () => {};
    const t = buildEarthScene(canvas);
    let animId = null;
    (function frame() {
        t.resize();
        if (!prefersReducedMotion) t.earth.rotation.y += 0.003;
        t.renderer.render(t.scene, t.camera);
        animId = requestAnimationFrame(frame);
    })();
    return () => { if (animId) cancelAnimationFrame(animId); };
}

// Clean global utility (only keep if you plan to use it elsewhere)
// function renderImage(url, tags = []) {
//     let attempts = 0;
//     const MAX_RETRIES = 5;
//     const tryLoad = () => {
//         const img = new Image();
//         img.onload = () => {
//             DOM.photoFrame.innerHTML = `<img src="${url}" alt="source">`;
//             renderVisionChips(DOM.photoFrame, tags); // ✅ Uses 'tags' parameter, not undefined 'data'
//         };
//         img.onerror = () => {
//             attempts++;
//             if (attempts < MAX_RETRIES) {
//                 console.warn(`Retry ${attempts}/${MAX_RETRIES}...`);
//                 setTimeout(() => {
//                     const separator = url.includes('?') ? '&' : '?';
//                     img.src = url + separator + 't=' + Date.now();
//                 }, 2000);
//             } else {
//                 DOM.photoFrame.innerHTML = `<div class="no-photo"><i class="fas fa-image"></i></div>
//                                             <p style="font-size:12px;color:var(--text-muted);">Image unavailable</p>`;
//             }
//         };
//         img.src = url;
//     };
//     tryLoad();
// }

    // ========== SWITCH BETWEEN FLAT MAP ↔ GLOBE ==========
    function showGlobeMode(statusText) {
        // Show starfield canvas, hide flat map
        DOM.mapEarthContainer.classList.add('hidden');
        DOM.starfieldCanvas.classList.add('visible');
        DOM.globeModeBadge.classList.add('visible');
        if (DOM.mapStatusText) DOM.mapStatusText.textContent = statusText || '🌐 3D Globe scan active...';
    }

    function showFlatMapMode() {
        // Show flat map, hide globe
        DOM.starfieldCanvas.classList.remove('visible');
        DOM.globeModeBadge.classList.remove('visible');
        DOM.mapEarthContainer.classList.remove('hidden');
    }

    // ========== EXPLORATION SEQUENCE ==========
    // Alternates: flat map zoom → globe → flat map zoom → globe ...
    async function runExplorationSequence(waypoints) {
        while (!analysisComplete) {
        randomWaypoints = shuffleArray(waypoints).slice(0, 12);
        totalWaypoints = randomWaypoints.length;

        for (let i = 0; i < totalWaypoints; i++) {
            if (analysisComplete) return;
            const wp = randomWaypoints[i];
            explorationIndex = i;

            // ── ODD index → GLOBE MODE (Frame 3, 5, ...) ──
            if (i % 2 === 1) {
                showGlobeMode(`🌐 Scanning ${wp.name}...`);
                setGlobeMarker(wp.lat, wp.lng, false);   // ← add this line
                if (DOM.pcScanning) DOM.pcScanning.textContent = `// SCANNING ${wp.name.toUpperCase()}`;
                await sleep(3200); // Let the globe spin
                if (analysisComplete) break;
                showFlatMapMode();
                await sleep(400);
                continue;
            }

            // ── EVEN index → FLAT MAP MODE (Frame 2, 4, 6, ...) ──
            showFlatMapMode();
            if (DOM.mapStatusText) {
                DOM.mapStatusText.textContent = `🌍 Probing ${wp.name}...`;
            }
            if (DOM.pcScanning) DOM.pcScanning.textContent = `// PROBING ${wp.name.toUpperCase()}`;

            // Zoom in from global
            await flyEarthTo(wp.lat, wp.lng, 4, 1400);
            if (analysisComplete) break;

            addProbeMarker(wp.lat, wp.lng, false);
            await sleep(600);
            if (analysisComplete) break;

            // Dive closer
            await flyEarthTo(wp.lat, wp.lng, 10, 1500);
            if (analysisComplete) break;
            await sleep(900);
            if (analysisComplete) break;

            // Pull back before next location
            await flyEarthTo(wp.lat, wp.lng, 3.5, 1200);
            if (analysisComplete) break;
            await sleep(500);
        }
      }
    }

    // ========== CINEMATIC TARGET REVEAL ==========
    // ========== CINEMATIC TARGET REVEAL ==========
    async function revealTarget(lat, lng, name) {
        analysisComplete = true;

        // ----- SANITISE COORDINATES -----
        function isValidCoordinate(lat, lng) {
            const latNum = parseFloat(lat);
            const lngNum = parseFloat(lng);
            return !isNaN(latNum) && !isNaN(lngNum) &&
                Math.abs(latNum) <= 90 && Math.abs(lngNum) <= 180 &&
                latNum !== 0 && lngNum !== 0;
        }

        let latNum = parseFloat(lat);
        let lngNum = parseFloat(lng);

        // If invalid, use fallback
        if (!isValidCoordinate(latNum, lngNum)) {
            console.warn('⚠️ revealTarget: Invalid coordinates, using fallback');
            console.warn('   → Received:', {
                lat,
                lng,
                name
            });
            latNum = 48.8584; // Eiffel Tower
            lngNum = 2.2945;
            name = name || 'Unknown Location (fallback)';
        }

        // Clamp to safe ranges
        latNum = Math.min(Math.max(latNum, -90), 90);
        lngNum = Math.min(Math.max(lngNum, -180), 180);

        // ----- CONTINUE WITH REVEAL -----
        showFlatMapMode();
        await sleep(300);

        // Clear existing probes
        if (earthMapInstance) {
            earthMapInstance.eachLayer(l => {
                if (l instanceof L.Marker && l.options?.probeMarker) {
                    earthMapInstance.removeLayer(l);
                }
            });
        }

        if (DOM.mapStatusText) DOM.mapStatusText.textContent = `🎯 Target acquired — ${name}...`;
        if (DOM.pcScanning) DOM.pcScanning.textContent = `// PINPOINTING ${name.toUpperCase()}`;

        // Stage 1: Global view
        await flyEarthTo(latNum, lngNum, 2.5, 1400);
        await sleep(500);

        // Stage 2: Add marker
        addProbeMarker(latNum, lngNum, true);

        // Stage 3: Continental approach
        await flyEarthTo(latNum, lngNum, 6, 1600);
        await sleep(500);

        // Stage 4: Regional approach
        await flyEarthTo(latNum, lngNum, 11, 1500);
        await sleep(500);

        // Stage 5: Final landing
        await flyEarthTo(latNum, lngNum, 15.5, 1700);
        await sleep(900);

        if (DOM.mapStatusText) {
            DOM.mapStatusText.innerHTML = `<span class="target-found">🎯 ${name} — CONFIRMED!</span>`;
        }
    }

    // ========== PROGRESS CARD ==========
    // The "Elapsed Xs" readout is always computed from the client's own wall
    // clock (startTime), never from the server's `elapsed` field. The server
    // can go a while between meaningfully different progress snapshots, and
    // if the display trusts that field directly the timer visibly stalls
    // even though the pipeline is still running. Progress % and the stage
    // headline still come straight from the server.
    function renderSub(progress) {
        if (!DOM.pcSub) return;
        const elapsed = startTime ? (Date.now() - startTime) / 1000 : 0;
        DOM.pcSub.textContent =
            `Elapsed ${elapsed.toFixed(1)}s · Progress ${Math.min(progress || 0, 100)}% · Engine TraceGeo AI`;
    }

    function updateProgressCard(stage, progress, label) {
        const labels = {
            0: 'Reading uploaded image…',
            1: 'Extracting visual features…',
            2: 'Running visual analysis…',
            3: 'Running Bayesian evidence fusion…',
            4: 'Pinpointing location…',
        };
        if (DOM.pcHeadline) DOM.pcHeadline.textContent = label || labels[stage] || 'Analyzing…';
        if (DOM.pcFill) DOM.pcFill.style.width = Math.min(progress || 0, 100) + '%';
        lastProgress = progress || 0;
        renderSub(lastProgress);
        document.querySelectorAll('.pc-steps .pcs').forEach(el => {
            const idx = parseInt(el.dataset.step, 10);
            el.classList.remove('active', 'done');
            if (idx < stage) el.classList.add('done');
            else if (idx === stage) el.classList.add('active');
        });
    }

    // ========== SESSION ==========
    function clearSession() {
    sessionStorage.removeItem('analysisId');
    sessionStorage.removeItem('analysisResult');
    if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
    if (elapsedInterval) { clearInterval(elapsedInterval); elapsedInterval = null; }
    currentAnalysisId = null;
    pollAttempts = 0;
    consecutiveErrors = 0;
    analysisComplete = true;   // ← changed: clearSession always means "stop exploring"
    isExploring = false;
}

    // ========== POLL BACKEND ==========
    function pollStatus() {
        if (!currentAnalysisId) return;
        pollAttempts++;
        if (pollAttempts > MAX_POLL_ATTEMPTS) {
            showError('Analysis timed out');
            clearSession();
            return;
        }

        fetch(`/api/analyze/${currentAnalysisId}/status`, {
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': CSRF_TOKEN
                },
                credentials: 'same-origin' // ✅ ensures session cookie is sent
            })
            .then(r => {
                if (!r.ok) return r.json().then(d => {
                    throw new Error(d.message || `HTTP ${r.status}`);
                });
                return r.json();
            })
            .then(data => {
                console.log('📊', data);
                consecutiveErrors = 0;

                               if (data.image_url) {
                    // Only use the Cloudinary URL if it's available, otherwise ignore local /storage/
                    if (data.image_url.includes('res.cloudinary.com')) {
                        uploadedImageURL = data.image_url;
                        sessionStorage.setItem('uploadedImage', uploadedImageURL);
                    }
                }

                const progress = data.progress || 0;
                const stage = data.stage || 0;

                updateProgressCard(stage, progress, data.stage_label);

                // Kick off exploration once we have some progress
                if (progress > 8 && !isExploring && !analysisComplete) {
                    isExploring = true;
                    runExplorationSequence(GLOBAL_WAYPOINTS); // async, non-blocking
                }

                if (data.status === 'completed') {
                    if (data.result) {
                        let result = data.result;
                        if (typeof result === 'string') {
                            try {
                                result = JSON.parse(result);
                            } catch (e) {}
                        }
                        sessionStorage.setItem('analysisResult', JSON.stringify(result));
                        clearSession();
                        // Tell the exploration loop to stop at its next checkpoint —
                        // set immediately (not just inside revealTarget) so it doesn't
                        // keep flying the map during the wrap-up delay below.
                        analysisComplete = true;

                        // ----- SMART COORDINATE EXTRACTION -----
                        function parseSafeCoordinate(value, fallback = 48.8584) {
                            const parsed = parseFloat(value);
                            return !isNaN(parsed) && isFinite(parsed) && Math.abs(parsed) <= 180 ? parsed :
                                fallback;
                        }

                        let tLat = parseSafeCoordinate(result.latitude ?? result.lat, 48.8584);
                        let tLng = parseSafeCoordinate(result.longitude ?? result.lng, 2.2945);
                        let tName = result.landmark_name || result.city || result.place || 'Unknown Location';

                        // Log what we found
                        console.log('📍 Target coordinates extracted:', {
                            tLat,
                            tLng,
                            tName
                        });
                        console.log('📦 Full result:', result);

                        // Give exploration a moment to reach its next checkpoint and exit, then reveal
                        setTimeout(async () => {
                            stopStarfield();
                            await revealTarget(tLat, tLng, tName);
                            // White flash
                            DOM.whiteFlash.classList.remove('run');
                            void DOM.whiteFlash.offsetWidth;
                            DOM.whiteFlash.classList.add('run');
                            setTimeout(() => revealResults(result), 700);
                        }, 3000);
                    } else {
                        showError('Analysis completed but no results found');
                    }
                    return;
                }

                if (data.status === 'failed') {
                    showError(data.error || 'Analysis failed');
                    clearSession();
                    return;
                }

                pollTimer = setTimeout(pollStatus, POLL_INTERVAL_MS);
            })
            .catch(err => {
                console.error('Poll error:', err);
                consecutiveErrors++;
                const delay = Math.min(POLL_INTERVAL_MS * Math.pow(1.5, Math.min(consecutiveErrors, 5)), 5000);
                if (consecutiveErrors < 8 && pollAttempts <= MAX_POLL_ATTEMPTS) {
                    pollTimer = setTimeout(pollStatus, delay);
                } else {
                    showError('Error: ' + err.message);
                    clearSession();
                }
            });
    }

    // ========== REVEAL RESULTS (Frame 7 & 8) ==========
    // Decorative AI-vision scan chips over the result photo (see comment
    // at the call site — not real per-object detection coordinates).
    const VISION_CHIP_SPOTS = [{
            top: '8%',
            left: '4%'
        },
        {
            top: '8%',
            right: '4%'
        },
        {
            top: '42%',
            left: '38%'
        },
        {
            top: '68%',
            right: '6%'
        },
        {
            top: '78%',
            left: '6%'
        },
    ];

    function renderVisionChips(container, tags) {
        container.querySelectorAll('.vision-chip').forEach(el => el.remove());
        const labels = (tags && tags.length ? tags : ['Scene', 'Surface', 'Structure', 'Foliage']).slice(0, 5);
        labels.forEach((label, i) => {
            const spot = VISION_CHIP_SPOTS[i % VISION_CHIP_SPOTS.length];
            const chip = document.createElement('span');
            chip.className = 'vision-chip';
            chip.style.animationDelay = `${i * 0.12}s`;
            Object.entries(spot).forEach(([k, v]) => chip.style[k] = v);
            const score = (0.65 + (i * 7 % 30) / 100).toFixed(2);
            chip.textContent = `${score} ${String(label).toUpperCase()}`;
            container.appendChild(chip);
        });
    }

    function revealResults(data) {
        if (DOM.progressCard) DOM.progressCard.style.display = 'none';
        // ===== SHOW THE RESULTS SPLIT =====
        if (DOM.resultsSplit) DOM.resultsSplit.classList.add('show');

        // Briefly hold on a static globe before the map fades in
        if (DOM.globeTransition && DOM.globeTransitionCanvas) {
    DOM.globeTransition.classList.remove('fade-out');
    DOM.globeTransition.style.display = 'block';
    const stopTransitionGlobe = drawStaticGlobe(DOM.globeTransitionCanvas);
    const holdMs = prefersReducedMotion ? 0 : 1100;
    setTimeout(() => {
        DOM.globeTransition.classList.add('fade-out');
        setTimeout(stopTransitionGlobe, 700);
    }, holdMs);
}

        const lat = parseFloat(data.latitude ?? data.lat);
        const lng = parseFloat(data.longitude ?? data.lng);
        const hasCoords = isValidCoord(lat, lng);
        const confidence = data.confidence ?? 0;
        const tier = confidence >= 80 ? 'high' : confidence >= 50 ? 'medium' : 'low';

        if (DOM.verifiedPill) {
            DOM.verifiedPill.className = `verified-pill ${tier}`;
            DOM.verifiedPill.innerHTML = tier === 'high' ?
                '<i class="fas fa-check-circle"></i> Identified Location' :
                tier === 'medium' ?
                '<i class="fas fa-exclamation-circle"></i> Likely Location' :
                '<i class="fas fa-question-circle"></i> Low Confidence';
        }

        if (DOM.placeTitle) DOM.placeTitle.textContent = data.landmark_name || data.city || 'Unknown';
        if (DOM.placeCountry) DOM.placeCountry.textContent = data.country || '';
        if (DOM.placeRegion) DOM.placeRegion.textContent = data.region || '';
        if (DOM.coordText) {
            DOM.coordText.textContent = hasCoords ?
                `${Math.abs(lat).toFixed(4)}° ${lat>=0?'N':'S'}, ${Math.abs(lng).toFixed(4)}° ${lng>=0?'E':'W'}` :
                'Not available';
        }
        if (DOM.confPillText) DOM.confPillText.textContent = `${confidence}% Confidence`;
        const dot = DOM.confPill?.querySelector('.dot');
        if (dot) dot.style.background = tier === 'high' ? 'var(--success)' : tier === 'medium' ? 'var(--warning)' :
            'var(--danger)';

        // ✅ PROFESSIONAL IMAGE LOADING BLOCK
        if (DOM.photoFrame) {
            // Use the new backend URL, fallback to session, auto-fix old /storage/
            let imgUrl = data.image_url || data.result?.image_url || data.result_image_url || uploadedImageURL ||
                sessionStorage.getItem('uploadedImage');
            console.log('🧪 Full imgUrl:', imgUrl);

            // Safety net: Replace old /storage/ with new /uploads/
            if (imgUrl && imgUrl.startsWith('/storage/')) {
                imgUrl = imgUrl.replace('/storage/', '/uploads/');
            }

            // Init Result Map
            if (!resultMapInstance) {
                resultMapInstance = L.map('resultMap', {
                    center: [lat, lng],
                    zoom: 13,
                    zoomControl: true,
                    attributionControl: true
                });
                L.tileLayer(ROADS_URL, {
                    attribution: ROADS_ATTR,
                    maxZoom: 19
                }).addTo(resultMapInstance);
                setTimeout(() => resultMapInstance.invalidateSize(), 300);
            } else {
                resultMapInstance.setView([lat, lng], 13);
            }

            // Add marker
                       const markerIcon = L.divIcon({
                html: `<div class="avatar-marker marker-drop">
                         <div class="ring"></div><div class="ring2"></div>
                         <div class="photo" style="background-image:url('${imgUrl || ''}')">
                         </div>
                       </div>`,
                className: '',
                iconSize: [46, 46],
                iconAnchor: [23, 23]
            });
            L.marker([lat, lng], {
                icon: markerIcon
            }).addTo(resultMapInstance);

            // Render Image
            const renderImage = (url) => {
                let attempts = 0;
                const MAX_RETRIES = 5;
                const tryLoad = () => {
                    const img = new Image();
                    img.onload = () => {
                        DOM.photoFrame.innerHTML = `<img src="${url}" alt="source">`;
                        if (url) renderVisionChips(DOM.photoFrame, data.tags);
                    };
                    img.onerror = () => {
                        attempts++;
                        if (attempts < MAX_RETRIES) {
                            console.warn(`Image retry ${attempts}/${MAX_RETRIES}...`);
                            setTimeout(() => {
                                const separator = url.includes('?') ? '&' : '?';
                                img.src = url + separator + 't=' + Date.now();
                            }, 2000);
                        } else {
                            console.error('Image failed after', MAX_RETRIES, 'attempts.');
                            DOM.photoFrame.innerHTML = `
                                <div class="no-photo"><i class="fas fa-image"></i></div>
                                <p style="font-size:12px;color:var(--text-muted);margin-top:4px;">
                                    Image unavailable
                                </p>
                            `;
                        }
                    };
                    img.src = url;
                };
                tryLoad();
            };

            if (imgUrl) {
                renderImage(imgUrl);
            } else {
                DOM.photoFrame.innerHTML = `<div class="no-photo"><i class="fas fa-image"></i></div>`;
            }
            setupControls(data, lat, lng, hasCoords);
        }
        // ===== UPGRADED EVIDENCE-BASED REASONING (UI) =====
        if (DOM.reasoningText) {
            const reasoning = data.reasoning || 'No reasoning available.';
            const latStr = hasCoords ?
                `${Math.abs(lat).toFixed(4)}° ${lat>=0?'N':'S'}, ${Math.abs(lng).toFixed(4)}° ${lng>=0?'E':'W'}` :
                'N/A';

            // Professional evidence chain HTML
            DOM.reasoningText.innerHTML = `
                <div style="margin-bottom:12px; padding:10px; border-left:3px solid var(--success); background:rgba(45,212,191,0.05);">
                    <strong style="color:var(--success);">CONFIRMED LOCATION EVIDENCE</strong><br>
                    <span style="color:var(--text-secondary);">Landmark: <strong>${data.landmark_name || data.city || 'Unknown'}</strong></span><br>
                    <span style="color:var(--text-secondary);">Coordinates: <strong>${latStr}</strong></span><br>
                    <span style="color:var(--text-secondary);">Confidence: <strong>${confidence}%</strong></span>
                </div>
                <div style="line-height:1.8;">
                    <strong style="color:var(--accent);">AI Reasoning:</strong><br>
                    ${reasoning}
                </div>
            `;
        }
    } // ← This closing bracket is vital! Make sure it's here!
    // ========== CONTROLS ==========
    function setupControls(data, lat, lng, hasCoords) {
        const set = (id, fn) => {
            const el = document.getElementById(id);
            if (el) el.onclick = fn;
        };

        // ===== COPY COORDINATES =====
        set('copyCoordsBtn', () => {
            if (!hasCoords) return;
            navigator.clipboard?.writeText(`${lat.toFixed(6)}, ${lng.toFixed(6)}`);
            showToast('📋 Coordinates copied!');
        });

        // ===== MAP TILES (Roads / Terrain) =====
        const roadsBtn = document.getElementById('roadsToggle');
        const terrainBtn = document.getElementById('terrainToggle');

        // ===== SEGMENTED CONTROL BUTTONS =====
        const globeBtn = document.getElementById('globeToggleBtn');
        const streetBtn = document.getElementById('streetToggleBtn');

        const removeStreetView = () => {
            DOM.satPane.querySelector('.street-inline')?.remove();
        };
                // ===== REMOVE THE OLD CONFUSING CHECKBOX =====
        const oldSwitch = document.getElementById('streetViewSwitch');
        if (oldSwitch) oldSwitch.parentElement.style.display = 'none';

        const setTile = (mode) => {
            currentTileMode = mode;
            if (roadsBtn) roadsBtn.classList.toggle('active', mode === 'roads');
            if (terrainBtn) terrainBtn.classList.toggle('active', mode === 'terrain');
            
            // ✅ FIX: Always reset to the Flat Map when changing tiles
            removeStreetView();
            const resultMapDiv = document.getElementById('resultMap');
            if (resultMapDiv) resultMapDiv.style.display = 'block';
            showFlatMapMode(); // Hides the 3D starfield and shows the flat earth map
            
            // Also reset the Globe/Street buttons
            if (globeBtn) globeBtn.classList.remove('active');
            if (streetBtn) streetBtn.classList.remove('active');
            
            if (resultMapInstance) {
                resultMapInstance.eachLayer(l => {
                    if (l instanceof L.TileLayer) resultMapInstance.removeLayer(l);
                });
                L.tileLayer(mode === 'terrain' ? SAT_URL : ROADS_URL, {
                    attribution: mode === 'terrain' ? SAT_ATTR : ROADS_ATTR,
                    maxZoom: 19
                }).addTo(resultMapInstance);
            }
        };

        // ===== STRICT MODE SWITCHING (No mixing) =====
        const switchTo3DGlobe = () => {
            removeStreetView();
            
            // Hide the Flat Map and show the REAL 3D Globe
            const resultMapDiv = document.getElementById('resultMap');
            if (resultMapDiv) resultMapDiv.style.display = 'none';
            showGlobeMode('🌐 Real 3D Globe Mode Active');
             
            // ✅ FOCUS AND RESIZE THE EARTH ON THE RESULT LOCATION
            if (earthScene) {
                earthScene.focusOn(lat, lng); 
                setGlobeMarker(lat, lng, true);
                earthScene.resize(); // Force resize right when it appears
            }

            // Color states
            globeBtn.classList.add('active');
            streetBtn.classList.remove('active');

            showToast('🌐 Real 3D Globe Mode Active');
        };

        const switchToStreetView = () => {
            removeStreetView();
            
            // Hide the 3D Globe and show the Flat Map (resultMap)
            const resultMapDiv = document.getElementById('resultMap');
            if (resultMapDiv) resultMapDiv.style.display = 'block';
            showFlatMapMode();
            
            // Load real-life 360 panorama
            showStreetView(lat, lng);

            // Color states
            streetBtn.classList.add('active');
            globeBtn.classList.remove('active');

            showToast('📸 Real-Time Street View Active');
        };

        // Set initial states (Globe is active by default)
        switchTo3DGlobe();

        // Bind the 2 buttons
        globeBtn.onclick = switchTo3DGlobe;
        streetBtn.onclick = switchToStreetView;

        // Setup Roads / Terrain buttons to reset to Globe mode
        if (roadsBtn) roadsBtn.onclick = () => { setTile('roads'); };
        if (terrainBtn) terrainBtn.onclick = () => { setTile('terrain'); };

        // ===== PAGE-LEVEL ACTIONS =====
        set('homeBtn', () => {
            window.location.href = '/';
        });
        set('reuploadBtn', () => {
            sessionStorage.removeItem('analysisId');
            sessionStorage.removeItem('analysisResult');
            sessionStorage.removeItem('uploadedImage');
            window.location.href = '/';
        });
        set('saveReportBtn', () => exportReport(data, lat, lng));
        set('shareBtn', async () => {
            const summary =
                `${data.landmark_name || data.city || 'Location'}${hasCoords ? ` — ${lat.toFixed(4)}, ${lng.toFixed(4)}` : ''}`;
            if (navigator.share) {
                try {
                    await navigator.share({
                        title: 'TraceGeo result',
                        text: summary,
                        url: window.location.href
                    });
                    return;
                } catch (e) {
                    /* user cancelled */ }
            }
            navigator.clipboard?.writeText(summary);
            showToast('📋 Summary copied to clipboard!');
        });

        // ===== PHOTO ACTIONS =====
        set('viewFullSizeBtn', () => {
            if (uploadedImageURL) window.open(uploadedImageURL, '_blank');
        });
        set('fullscreenPhotoBtn', () => {
            const frame = DOM.photoFrame;
            if (frame?.requestFullscreen) frame.requestFullscreen();
            else if (uploadedImageURL) window.open(uploadedImageURL, '_blank');
        });
        set('reverseSearchBtn', () => {
            if (!uploadedImageURL) {
                showToast('❌ No image to search.');
                return;
            }
            window.open(`https://lens.google.com/uploadbyurl?url=${encodeURIComponent(uploadedImageURL)}`,
                '_blank');
        });
        set('openOriginalBtn', () => {
            if (uploadedImageURL) window.open(uploadedImageURL, '_blank');
        });
    }

    function showStreetView(lat, lng) {
        DOM.satPane.querySelector('.street-inline')?.remove();
        const wrap = document.createElement('div');
        wrap.className = 'street-inline';
        const fullUrl = `https://www.google.com/maps/@?api=1&map_action=pano&viewpoint=${lat},${lng}`;

        // ✅ FIX: Use the correct IDs from your HTML
        const globeBtn = document.getElementById('globeToggleBtn');
        const streetBtn = document.getElementById('streetToggleBtn');
        
        if (globeBtn) globeBtn.classList.remove('active');
        if (streetBtn) streetBtn.classList.add('active');

        if (GOOGLE_MAPS_EMBED_KEY) {
            // ... (Your existing embed code) ...
            wrap.innerHTML = `
        <button class="street-back" id="streetBackBtn"><i class="fas fa-arrow-left"></i></button>
        <iframe src="${embedSrc}" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allow="accelerometer; gyroscope; magnetometer; fullscreen"></iframe>
        <button class="street-open-full" id="streetOpenFullBtn"><i class="fas fa-expand"></i> Open Full Street View</button>
    `;
        } else {
            // ... (Your existing API key code) ...
            wrap.innerHTML = `
        <button class="street-back" id="streetBackBtn"><i class="fas fa-arrow-left"></i></button>
        <div class="street-setup-notice">
            <i class="fas fa-street-view"></i>
            <h4>Street View needs one setup step</h4>
            <p>Inline Street View requires a free Google Maps Embed API key —
               it isn't configured yet, so it can't show the real panorama here.</p>
            <button class="street-setup-cta" id="streetOpenRealBtn">
                <i class="fas fa-up-right-from-square"></i> Open real Street View
            </button>
        </div>`;
        }

        DOM.satPane.appendChild(wrap);

        // Back button logic (Properly resets UI states using the correct IDs)
        document.getElementById('streetBackBtn').onclick = () => {
            wrap.remove();
            if (globeBtn) globeBtn.classList.remove('active');
            if (streetBtn) streetBtn.classList.remove('active');
            
            // Re-highlight roads or terrain based on currentTileMode
            const roadsBtn = document.getElementById('roadsToggle');
            const terrainBtn = document.getElementById('terrainToggle');
            if (currentTileMode === 'terrain') terrainBtn?.classList.add('active');
            else roadsBtn?.classList.add('active');
        };
        document.getElementById('streetOpenFullBtn')?.addEventListener('click', () => window.open(fullUrl, '_blank'));
        document.getElementById('streetOpenRealBtn')?.addEventListener('click', () => window.open(fullUrl, '_blank'));
    }

    function exportReport(data, lat, lng) {
        const hasCoords = isValidCoord(lat, lng);
        const latStr = hasCoords ?
            `${Math.abs(lat).toFixed(6)}° ${lat>=0?'N':'S'}, ${Math.abs(lng).toFixed(6)}° ${lng>=0?'E':'W'}` : 'N/A';
        const confidence = data.confidence ?? 0;
        const tier = confidence >= 80 ? 'HIGH' : confidence >= 50 ? 'MEDIUM' : 'LOW';

        const text = `TRACEGEO OSINT INTELLIGENCE REPORT
============================================
Generated: ${new Date().toISOString()}

LOCATION
--------------------------------------------
Name:        ${data.landmark_name || 'Unknown'}
City:        ${data.city || 'N/A'}
Country:     ${data.country || 'N/A'}
Region:      ${data.region || 'N/A'}
Coordinates: ${latStr}
Confidence:  ${confidence}% (${tier})

EVIDENCE CHAIN
--------------------------------------------
• Verified Landmark: ${data.landmark_name || 'Unknown'}
• Latitude: ${latStr}
• Visual markers matched: ${(data.tags || []).join(', ') || 'N/A'}
• Image Source: ${uploadedImageURL || 'N/A'}

AI REASONING
--------------------------------------------
${data.reasoning || 'No reasoning available.'}

============================================
TraceGeo OSINT Intelligence`;

        const blob = new Blob([text], {
            type: 'text/plain'
        });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `TraceGeo_${(data.landmark_name || 'Report').replace(/\s+/g, '_')}.txt`;
        a.click();
        showToast('📄 Report exported!');
    }

    // ========== ENTRY POINT ==========
    function startAnalysis(analysisId, imageUrl) {
        currentAnalysisId = analysisId;
        uploadedImageURL = imageUrl;
        if (imageUrl) sessionStorage.setItem('uploadedImage', imageUrl);
        pollAttempts = 0;
startTime = Date.now();
analysisComplete = false;
isExploring = false;

        if (DOM.progressCard) DOM.progressCard.style.display = 'block';
        if (DOM.pcError) DOM.pcError.style.display = 'none';
        if (DOM.resultsSplit) DOM.resultsSplit.classList.remove('show');
        if (DOM.statusDot) {
            DOM.statusDot.style.background = 'var(--warning)';
            DOM.statusDot.style.boxShadow = '0 0 20px var(--warning)';
        }

        showToast('🔍 Analysis in progress…');
        updateProgressCard(0, 5, 'Starting analysis…');

        if (elapsedInterval) clearInterval(elapsedInterval);
        elapsedInterval = setInterval(() => {
            if (!startTime) return;
            renderSub(lastProgress);
        }, 200);

        // Init starfield first (for globe mode)
        initStarfield();

        // Start flat map showing a random global location
        const startPt = GLOBAL_WAYPOINTS[Math.floor(Math.random() * GLOBAL_WAYPOINTS.length)];
        initEarthMap([startPt.lat, startPt.lng], 3);

        if (DOM.mapStatusText) DOM.mapStatusText.textContent = '🌍 Initializing OSINT Engine...';

        pollStatus();
    }

    document.addEventListener('DOMContentLoaded', () => {
        const analysisId = sessionStorage.getItem('analysisId');
        const uploadedImage = sessionStorage.getItem('uploadedImage');

        if (analysisId) {
            startAnalysis(analysisId, uploadedImage);
        } else {
            // Idle state — show globe
            initStarfield();
            showGlobeMode('🌍 Ready for analysis');
            if (DOM.statusDot) DOM.statusDot.style.background = 'var(--success)';
            showToast('🌍 OSINT Engine ready');
        }
    });

    // ========== COORDINATED RESIZE & LAYOUT HANDLING (Block 3, 5, 6) ==========
    let resizeTmr;

    function syncStageTop() {
        const nav = document.querySelector('.navbar');
        const stage = document.getElementById('stageFrame');
        if (nav && stage) {
            stage.style.top = (nav.getBoundingClientRect().height + 8) + 'px';
        }
    }

    function handleResize() {
        clearTimeout(resizeTmr);
        resizeTmr = setTimeout(() => {
            resultMapInstance?.invalidateSize();
            earthMapInstance?.invalidateSize();
            if (earthScene) earthScene.resize();
            syncStageTop();
        }, 150);
    }

    const stageResizeObserver = new ResizeObserver(() => handleResize());
    stageResizeObserver.observe(document.getElementById('stageFrame'));

    window.addEventListener('resize', handleResize);
    syncStageTop();

    // ESC to cancel
    document.addEventListener('keydown', e => {
        if (e.key !== 'Escape') return;
        if (pollTimer) {
            clearTimeout(pollTimer);
            pollTimer = null;
        }
        if (elapsedInterval) {
            clearInterval(elapsedInterval);
            elapsedInterval = null;
        }
        clearSession();
        stopStarfield();
        if (DOM.progressCard) DOM.progressCard.style.display = 'none';
        if (DOM.statusDot) {
            DOM.statusDot.style.background = 'var(--success)';
            DOM.statusDot.style.boxShadow = '0 0 20px var(--success)';
        }
        showToast('⏹️ Analysis cancelled');
    });

    console.log('✅ TraceGeo loaded — storyboard-accurate sequence active');
    console.log('🗺️  Flat map ↔ 3D starfield globe alternation enabled');
    console.log('⌨️  Press ESC to cancel.');
    </script>
</body>

</html>