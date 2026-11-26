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

    <link rel="stylesheet" href="{{ asset('css/analysis.css') }}">
</head>

<body>

    <a href="#stageFrame" class="skip-link">Skip to analysis</a>

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
                <div class="pc-error" id="pcError" role="alert" aria-live="assertive"></div>
                <button type="button" class="pc-retry" id="pcRetry" style="display:none;">Retry analysis</button>
            </div>

            <!-- Results Split (Frame 7 & 8) -->
            <div class="results-split" id="resultsSplit">
                <div class="sat-pane" id="satPane">
                    <div id="resultMap"></div>
                    <canvas class="result-globe-canvas" id="resultGlobeCanvas"></canvas>
                    <div id="resultGlobeLabel" class="globe-label"></div>
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

                </div>
                <div class="data-pane" id="dataPane">
                    <div class="verified-pill high" id="verifiedPill">
                        <i class="fas fa-check-circle"></i> Identified Location
                    </div>

                    <!-- Phase E: calibrated confidence evidence + alternatives -->
                    <div class="confidence-evidence" id="confidenceEvidence" style="display:none;">
                        <div class="ce-title">Why this confidence</div>
                        <ul class="ce-factors" id="confidenceFactors"></ul>
                        <div class="ce-alternatives" id="confidenceAlternatives" style="display:none;">
                            <div class="ce-title">Other possibilities</div>
                            <ol class="ce-alternative-list" id="alternativeList"></ol>
                        </div>
                    </div>

                    <div class="place-title" id="placeTitle">Location</div>
                    <div class="place-country" id="placeCountry">Country</div>
                    <div class="place-region" id="placeRegion">Region</div>
                    <div class="coord-row">
                        <span id="coordText">0.0000° N, 0.0000° W</span>
                        <button id="copyCoordsBtn" title="Copy coordinates" aria-label="Copy coordinates"><i class="fas fa-copy"></i></button>
                    </div>

                    <div class="action-row">
                        <button class="action-primary" id="homeBtn" title="Back to home"><i class="fas fa-house"></i>
                            Home</button>
                        <button id="reuploadBtn" title="Analyze another photo"><i
                                class="fas fa-arrow-up-from-bracket"></i> Reupload</button>
                        <button id="saveReportBtn" title="Save report" aria-label="Save report"><i class="fas fa-folder"></i></button>
                        <button id="shareBtn" title="Share" aria-label="Share result"><i class="fas fa-share-nodes"></i></button>
                    </div>

                    <div class="pane-toggle-row" id="baseLayerToggleRow">
                        <!-- Map Layers -->
                        <button class="active" id="roadsToggle"><i class="fas fa-route"></i> Roads</button>
                        <button id="terrainToggle"><i class="fas fa-satellite"></i> Terrain</button>
                    </div>

                    <!-- NEW ROW: 3D Globe & Street View under the map layers -->
                    <div class="pane-toggle-row" id="viewModeToggleRow" style="margin-top: 8px;">
                        <button class="active" id="globeToggleBtn"><i class="fas fa-globe-americas"></i> 3D
                            Globe</button>
                        <button id="streetToggleBtn"><i class="fas fa-street-view"></i> Street View</button>
                    </div>

                    <div class="photo-label">Your Image</div>
                    <div class="photo-frame" id="photoFrame">
                        <div class="no-photo"><i class="fas fa-image"></i></div>
                    </div>
                    <div class="photo-icon-row">
                        <button id="viewFullSizeBtn" title="View full size" aria-label="View full size"><i
                                class="fas fa-magnifying-glass"></i></button>
                        <button id="fullscreenPhotoBtn" title="Fullscreen" aria-label="View photo fullscreen"><i class="fas fa-expand"></i></button>
                        <button id="reverseSearchBtn" title="Reverse image search" aria-label="Reverse image search"><i
                                class="fas fa-magnifying-glass-location"></i></button>
                        <button id="openOriginalBtn" title="Open original" aria-label="Open original image"><i
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
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/OrbitControls.js"></script>

    <script>
        @php
        // Safely fetch the config, ensuring it is always a string or empty
        $embedKey = config('services.google_maps.embed_key');
        if (!is_string($embedKey)) {
            $embedKey = '';
        }
        @endphp
        window.__EMBED_KEY__ = @json($embedKey);
    </script>
    <script src="{{ asset('js/confidence-heading.js') }}"></script>
    <script src="{{ asset('js/street-view-fallback.js') }}"></script>
    <script src="{{ asset('js/analysis.js') }}"></script>
    <script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/sw.js')
                .then((reg) => {
                    console.log('Service Worker registered.');
                    return navigator.serviceWorker.ready;
                })
                .then((reg) => {
                    if ('navigationPreload' in reg) {
                        return reg.navigationPreload.enable();
                    }
                })
                .catch((err) => console.log('SW registration failed: ', err));
        });
    }
    </script>
</body>

</html>
