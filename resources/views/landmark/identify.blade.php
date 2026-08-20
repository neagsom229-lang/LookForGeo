<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TraceGeo — Where was this taken?</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet-control-geocoder@2.0.0/dist/Control.Geocoder.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet-control-geocoder@2.0.0/dist/Control.Geocoder.js"></script>

    <link rel="stylesheet" href="{{ asset('css/identify.css') }}"><link rel="stylesheet" href="{{ asset('vendor/leaflet-control-geocoder/css/identify.css') }}">
</head>
<body>
    <div class="tg-shell">
        <header class="tg-header">
            <div class="tg-mark">
                <span class="tg-mark-ring"></span>
                <span class="tg-mark-dot"></span>
            </div>
            <div class="tg-header-text">
                <h1>TraceGeo</h1>
                <p>Upload a photo. Gemini AI and OpenStreetMap triangulate where it was taken.</p>
            </div>
            <button class="tg-theme-toggle" id="themeToggle" type="button" aria-label="Toggle theme">
                <span id="themeIcon">◐</span>
            </button>
        </header>

        <main class="tg-card">
            <section class="tg-upload" id="uploadArea" tabindex="0" role="button" aria-label="Upload a photo">
                <div class="tg-upload-ring">
                    <div class="tg-upload-glyph">＋</div>
                </div>
                <h2>Drop a photo here</h2>
                <p>or tap to browse — JPG, PNG, WebP, up to 16MB</p>
                <input type="file" id="imageInput" accept="image/*" hidden>
            </section>

            <div class="tg-preview" id="previewContainer">
                <img id="previewImage" alt="Uploaded preview">
                <button class="tg-preview-remove" id="removeBtn" type="button" aria-label="Remove image">✕</button>
                <span class="tg-preview-info" id="fileInfo"></span>
            </div>

            <div class="tg-controls">
                <div class="tg-field">
                    <label for="modeSelect">Analysis mode</label>
                    <select id="modeSelect">
                        <option value="fast">Fast — quick read</option>
                        <option value="detailed">Detailed — deeper pass</option>
                    </select>
                </div>
                <button class="tg-identify-btn" id="identifyBtn" type="button" disabled>
                    <span id="identifyBtnLabel">Identify location</span>
                </button>
            </div>

            <div class="tg-message tg-message-error" id="errorMessage">
                <strong id="errorTitle">Couldn't place this one</strong>
                <span id="errorText"></span>
            </div>
            <div class="tg-message tg-message-info" id="infoMessage">
                <strong id="infoTitle">Note</strong>
                <span id="infoText"></span>
            </div>

            <div class="tg-loader" id="loader">
                <div class="tg-radar">
                    <div class="tg-radar-sweep"></div>
                    <div class="tg-radar-ring"></div>
                    <div class="tg-radar-ring tg-radar-ring--outer"></div>
                </div>
                <div class="tg-loader-title">Triangulating…</div>
                <div class="tg-loader-sub">Reading terrain, light, and signage cues</div>
            </div>

            <div class="tg-results" id="results">
                <div class="tg-result-header">
                    <div>
                        <span class="tg-eyebrow" id="sourceEyebrow">AI ESTIMATE</span>
                        <h2 id="landmarkName">—</h2>
                        <p class="tg-location" id="landmarkLocation">—</p>
                    </div>
                    <div class="tg-confidence" id="confidenceBadge">
                        <svg viewBox="0 0 120 120" class="tg-conf-ring">
                            <circle cx="60" cy="60" r="52" class="tg-conf-ring-track"></circle>
                            <circle cx="60" cy="60" r="52" class="tg-conf-ring-fill" id="confRingFill"></circle>
                        </svg>
                        <div class="tg-conf-value"><span id="confidenceValue">—</span><small>%</small></div>
                    </div>
                </div>

                <p class="tg-description" id="description">—</p>

                <div class="tg-tags" id="evidenceTags"></div>

                <div class="tg-readout" id="coordReadout">
                    <span class="tg-readout-label">Coordinates</span>
                    <span class="tg-readout-value" id="coordDMS">—</span>
                </div>

                <div class="tg-tabs" role="tablist">
                    <button class="tg-tab" data-tab="location" role="tab" aria-selected="true">Location</button>
                    <button class="tg-tab" data-tab="weather" role="tab" aria-selected="false">Weather</button>
                    <button class="tg-tab" data-tab="environment" role="tab" aria-selected="false">Environment</button>
                    <button class="tg-tab" data-tab="nearby" role="tab" aria-selected="false">Nearby</button>
                </div>

                <div class="tg-panel" data-panel="location">
                    <div id="map"><div class="tg-map-placeholder">Map will appear here</div></div>
                    <div class="tg-actions" id="actionButtons">
                        <a href="#" class="tg-action" id="googleMapsBtn" target="_blank" rel="noopener">Open in Google Maps</a>
                        <button class="tg-action tg-action--ghost" id="copyCoordsBtn" type="button">Copy coordinates</button>
                        <button class="tg-action tg-action--ghost" id="shareBtn" type="button">Share</button>
                    </div>
                </div>

                <div class="tg-panel" data-panel="weather" hidden>
                    <div class="tg-info-grid">
                        <div class="tg-info-card"><span class="label">Temperature</span><span class="value" id="weatherTemp">—</span></div>
                        <div class="tg-info-card"><span class="label">Conditions</span><span class="value" id="weatherDesc">—</span></div>
                        <div class="tg-info-card"><span class="label">Humidity</span><span class="value" id="weatherHumidity">—</span></div>
                        <div class="tg-info-card"><span class="label">Wind</span><span class="value" id="weatherWind">—</span></div>
                        <div class="tg-info-card"><span class="label">Cloud cover</span><span class="value" id="weatherClouds">—</span></div>
                    </div>
                </div>

                <div class="tg-panel" data-panel="environment" hidden>
                    <div class="tg-info-grid">
                        <div class="tg-info-card"><span class="label">Elevation</span><span class="value" id="elevMeters">—</span></div>
                        <div class="tg-info-card"><span class="label">Terrain</span><span class="value" id="elevCategory">—</span></div>
                        <div class="tg-info-card"><span class="label">Sunrise</span><span class="value" id="sunRise">—</span></div>
                        <div class="tg-info-card"><span class="label">Sunset</span><span class="value" id="sunSet">—</span></div>
                        <div class="tg-info-card"><span class="label">Sun altitude</span><span class="value" id="sunAltitude">—</span></div>
                        <div class="tg-info-card"><span class="label">Timezone</span><span class="value" id="locTimezone">—</span></div>
                    </div>
                </div>

                <div class="tg-panel" data-panel="nearby" hidden>
                    <div class="tg-nearby-list" id="nearbyList"></div>
                </div>

                <button class="tg-refine" id="refineBtn" type="button" hidden>Refine with detailed analysis</button>
            </div>
        </main>

        <footer class="tg-footer">
            Gemini AI <span>·</span> OpenStreetMap <span>·</span> <span id="footerYear"></span>
        </footer>
    </div>

    <script src="{{ asset('js/identify.js') }}"></script>
</body>
</html>