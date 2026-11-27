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
    const POLL_INTERVAL_MS = 1000;   // 1 second (used for the initial kick-off)
    const MAX_POLL_ATTEMPTS = 300;   // 300 seconds = 5 minutes
    // Backoff ladder: 2s, then 3s, then 5s -- don't hammer the API while a
    // job sits in the queue.
    const POLL_DELAYS_MS = [2000, 3000, 5000];
    // Worst case on the server: 4 tries x 110s Gemini budget + 3 release
    // delays (Retry-After can raise each to 180s) = 980s (~16m20s). Keep
    // polling calmly for 20 minutes ( worst case + ~4 min margin ), well
    // inside the 25-minute reaper window -- not the old 120s no-progress cap.
    const AI_WAIT_LABEL = 'Waiting for AI service';
    const AI_WAIT_MAX_MS = 20 * 60 * 1000;
    const AI_WAIT_CALM_HINT = 'The AI service is busy, so we are retrying automatically. This can take up to about 20 minutes.';

    const DEBUG = window.__TRACEGEO_DEBUG__ === true || /^(localhost|127\.0\.0\.1)$/.test(location.hostname);
    const dbg = DEBUG ? console.log.bind(console) : () => {};

    function nextPollDelay() {
        const base = POLL_DELAYS_MS[Math.min(Math.max(pollAttempts - 1, 0), POLL_DELAYS_MS.length - 1)];
        // During long AI-wait retries the server state changes slowly; back off to reduce load.
        if (aiWaitingSince !== null) return Math.max(base, 8000);
        return base;
    }
    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // ========== STREET VIEW CONFIG ==========
    // Real, photographic Street View can only be embedded reliably through
    // Google's official Maps Embed API, which needs a key.
    const GOOGLE_MAPS_EMBED_KEY_RAW = window.__EMBED_KEY__ || '';
    const GOOGLE_MAPS_EMBED_KEY = GOOGLE_MAPS_EMBED_KEY_RAW && !GOOGLE_MAPS_EMBED_KEY_RAW.includes('{{') ?
        GOOGLE_MAPS_EMBED_KEY_RAW : '';

const DARK_TILE = 'https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Dark_Gray_Base/MapServer/tile/{z}/{y}/{x}';
const DARK_ATTR = 'Tiles &copy; <a href="https://www.esri.com/">Esri</a>';
const SAT_URL = 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}';
const SAT_ATTR = 'Tiles &copy; <a href="https://www.esri.com/">Esri</a>';
const ROADS_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
const ROADS_ATTR = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';

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
        pcRetry: document.getElementById('pcRetry'),
        pcScanning: document.getElementById('pcScanning'),
        resultsSplit: document.getElementById('resultsSplit'),
        satPane: document.getElementById('satPane'),
        dataPane: document.getElementById('dataPane'),
        resultMap: document.getElementById('resultMap'),
        resultGlobeCanvas: document.getElementById('resultGlobeCanvas'),
        confPill: document.getElementById('confPill'),
        confPillText: document.getElementById('confPillText'),
        verifiedPill: document.getElementById('verifiedPill'),
        confidenceEvidence: document.getElementById('confidenceEvidence'),
        confidenceFactors: document.getElementById('confidenceFactors'),
        confidenceAlternatives: document.getElementById('confidenceAlternatives'),
        alternativeList: document.getElementById('alternativeList'),
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
    let streetViewTimer = null;
    let streetViewSession = null;
    let completionTimers = [];
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
    let lastProgressTime = Date.now();
    // When the current poll first saw the 'Waiting for AI service' label;
    // null whenever the analysis is not on that path.
    let aiWaitingSince = null;

    // ========== HELPERS ==========
    // Strict gate for EVERY map/Street View URL. Number(null) === 0, so
    // explicit null/empty must be rejected before conversion.
    function isValidCoord(lat, lng) {
        if (lat === null || lat === undefined || lat === '' ||
            lng === null || lng === undefined || lng === '') return false;
        const a = typeof lat === 'number' ? lat : Number(lat);
        const b = typeof lng === 'number' ? lng : Number(lng);
        return Number.isFinite(a) && Number.isFinite(b) &&
            Math.abs(a) <= 90 && Math.abs(b) <= 180 &&
            !(a === 0 && b === 0);
    }

    function sleep(ms) {
        return new Promise(r => setTimeout(r, ms));
    }

    function showToast(msg) {
        if (DOM.navStatus) DOM.navStatus.textContent = msg;
    }

    function showError(message, failedAnalysisId) {
        if (DOM.pcError) {
            DOM.pcError.textContent = `⚠️ ${message}`;
            DOM.pcError.style.display = 'block';
        }
        if (DOM.statusDot) {
            DOM.statusDot.style.background = 'var(--danger)';
            DOM.statusDot.style.boxShadow = '0 0 20px var(--danger)';
        }
        showToast('❌ ' + message);

        if (failedAnalysisId) {
            // Keep the card visible so the Retry action is reachable.
            if (DOM.progressCard) DOM.progressCard.style.display = 'block';
            if (DOM.pcHeadline) DOM.pcHeadline.textContent = 'Analysis failed';
            showRetryButton(failedAnalysisId);
        } else if (DOM.progressCard) {
            DOM.progressCard.style.display = 'none';
        }
    }

    function showRetryButton(analysisId) {
        if (!DOM.pcRetry) return;
        DOM.pcRetry.style.display = 'inline-flex';
        DOM.pcRetry.disabled = false;
        DOM.pcRetry.textContent = 'Retry analysis';
        DOM.pcRetry.dataset.analysisId = String(analysisId);
    }

    function hideRetryButton() {
        if (!DOM.pcRetry) return;
        DOM.pcRetry.style.display = 'none';
        DOM.pcRetry.disabled = false;
        delete DOM.pcRetry.dataset.analysisId;
    }

    async function retryFailedAnalysis() {
        const id = DOM.pcRetry && DOM.pcRetry.dataset.analysisId;
        if (!id) return;
        if (DOM.pcRetry) {
            DOM.pcRetry.disabled = true;
            DOM.pcRetry.textContent = 'Retrying...';
        }
        try {
            const res = await fetch(`/api/analyze/${id}/retry`, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': CSRF_TOKEN
                },
                credentials: 'same-origin'
            });
            if (res.status === 419) {
                throw new Error('Your session expired, reload the page');
            }
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.success) {
                throw new Error(data.message || `HTTP ${res.status}`);
            }
            hideRetryButton();
            if (DOM.pcError) DOM.pcError.style.display = 'none';
            startAnalysis(parseInt(id, 10), sessionStorage.getItem('uploadedImage') || uploadedImageURL);
        } catch (err) {
            if (DOM.pcError) {
                DOM.pcError.textContent = `⚠️ ${err.message || 'Retry failed'}`;
                DOM.pcError.style.display = 'block';
            }
            if (DOM.pcRetry) {
                DOM.pcRetry.disabled = false;
                DOM.pcRetry.textContent = 'Retry analysis';
            }
        }
    }

    /**
     * Surface server-provided hints (e.g. "queue worker not running?")
     * inside the progress card and in the nav status line.
     * Pass toast=false for hints re-sent on every poll (a toast on each
     * poll would spam the user while they wait).
     */
    function showQueueHint(hint, toast = true) {
        if (!hint) return;
        let el = document.getElementById('pcHint');
        if (!el) {
            el = document.createElement('div');
            el.id = 'pcHint';
            el.style.cssText = 'margin-top:10px;padding:8px 10px;border-radius:8px;' +
                'background:rgba(255,193,7,.12);color:#ffd54f;font-size:12px;line-height:1.4;display:none;';
            const anchor = DOM.pcSteps || DOM.pcScanning;
            if (anchor && anchor.parentElement) {
                anchor.parentElement.insertBefore(el, anchor.nextSibling);
            } else if (DOM.progressCard) {
                DOM.progressCard.appendChild(el);
            }
        }
        el.textContent = '💡 ' + hint;
        el.style.display = 'block';
        if (toast !== false) showToast(hint);
    }

    function hideQueueHint() {
        const el = document.getElementById('pcHint');
        if (el) el.style.display = 'none';
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
            if (!isValidCoord(lat, lng)) {
                console.warn('flyEarthTo: no usable coordinates; not moving the globe', {lat, lng});
                resolve();
                return;
            }

            const latNum = Number(lat);
            const lngNum = Number(lng);

            const safeZoom = Math.min(Math.max(zoom || 2, 1), 18);

            if (!earthMapInstance) {
                console.warn('⚠️ earthMapInstance not initialized');
                resolve();
                return;
            }

            try {
                earthMapInstance.eachLayer(l => {
                    if (l instanceof L.Marker && l.options?.probeMarker) {
                        earthMapInstance.removeLayer(l);
                    }
                });
            } catch (e) {
                /* ignore */
            }

            earthMapInstance.flyTo([latNum, lngNum], safeZoom, {
                duration: duration / 1000,
                easeLinearity: 0.25
            });

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
    const EARTH_TEXTURE_URL =
        'https://cdn.jsdelivr.net/gh/mrdoob/three.js@r128/examples/textures/planets/earth_atmos_2048.jpg';
    const EARTH_SPEC_URL =
        'https://cdn.jsdelivr.net/gh/mrdoob/three.js@r128/examples/textures/planets/earth_specular_2048.jpg';
    const EARTH_CLOUDS_URL =
        'https://cdn.jsdelivr.net/gh/mrdoob/three.js@r128/examples/textures/planets/earth_clouds_1024.png';
    const EARTH_NORMAL_URL =
        'https://cdn.jsdelivr.net/gh/mrdoob/three.js@r128/examples/textures/planets/earth_normal_2048.jpg';

    const textureLoader = new THREE.TextureLoader();
    let cachedEarthTex, cachedSpecTex, cachedCloudsTex, cachedNormalTex, texturesLoading = null;

    function loadEarthTextures() {
        if (texturesLoading) return texturesLoading;
        texturesLoading = Promise.all([
            new Promise(res => textureLoader.load(EARTH_TEXTURE_URL, t => {
                cachedEarthTex = t;
                res();
            }, undefined, () => res())),
            new Promise(res => textureLoader.load(EARTH_SPEC_URL, t => {
                cachedSpecTex = t;
                res();
            }, undefined, () => res())),
            new Promise(res => textureLoader.load(EARTH_CLOUDS_URL, t => {
                cachedCloudsTex = t;
                res();
            }, undefined, () => res())),
            new Promise(res => textureLoader.load(EARTH_NORMAL_URL, t => {
                cachedNormalTex = t;
                res();
            }, undefined, () => res())),
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
        const renderer = new THREE.WebGLRenderer({
            canvas,
            alpha: true,
            antialias: true
        });
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
            starPositions[i * 3] = r * Math.sin(phi) * Math.cos(theta);
            starPositions[i * 3 + 1] = r * Math.sin(phi) * Math.sin(theta);
            starPositions[i * 3 + 2] = r * Math.cos(phi);
        }
        starGeo.setAttribute('position', new THREE.BufferAttribute(starPositions, 3));
        scene.add(new THREE.Points(starGeo, new THREE.PointsMaterial({
            color: 0xffffff,
            size: 0.09,
            transparent: true,
            opacity: 0.75
        })));

        // Brighter, more forgiving lighting so the globe reads clearly even
        // on whichever hemisphere is facing the camera at a given moment.
        const sun = new THREE.DirectionalLight(0xffffff, 1.4);
        sun.position.set(5, 3, 5);
        scene.add(sun);
        const sunBack = new THREE.DirectionalLight(0xffffff, 0.5);
        sunBack.position.set(-5, -2, -4);
        scene.add(sunBack);
        scene.add(new THREE.AmbientLight(0x8899aa, 0.65));

        const earthMat = new THREE.MeshPhongMaterial({
            shininess: 12,
            color: 0x223344
        });
        const earth = new THREE.Mesh(new THREE.SphereGeometry(1, 64, 64), earthMat);
        scene.add(earth);

        const cloudMat = new THREE.MeshPhongMaterial({
            transparent: true,
            opacity: 0.5,
            depthWrite: false
        });
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
            if (cachedSpecTex) {
                earthMat.specularMap = cachedSpecTex;
                earthMat.specular = new THREE.Color(0x333333);
            }
            earthMat.color.set(0xffffff);
            earthMat.needsUpdate = true;
            if (cachedCloudsTex) {
                cloudMat.map = cachedCloudsTex;
                cloudMat.needsUpdate = true;
            }
        });

        let lastW = 0, lastH = 0;
        function resize() {
            const rect = canvas.getBoundingClientRect();
            const w = Math.max(1, Math.floor(rect.width));
            const h = Math.max(1, Math.floor(rect.height));
            if (w === lastW && h === lastH) return;
            lastW = w; lastH = h;
            renderer.setSize(w, h, false);
            camera.aspect = w / h;
            camera.updateProjectionMatrix();
        }
        resize();

        function focusOn(lat, lng) {
            earth.rotation.y = (lng * Math.PI) / 180;
            earth.rotation.x = (lat * Math.PI) / 180 - Math.PI / 2;
            clouds.rotation.y = earth.rotation.y;
            clouds.rotation.x = earth.rotation.x;
        }

        // Add OrbitControls for rotation, zoom, and panning
        const controls = new THREE.OrbitControls(camera, renderer.domElement);
        controls.enableDamping = true;
        controls.dampingFactor = 0.08;
        controls.enableZoom = true;
        controls.enablePan = true;
        controls.maxDistance = 8;
        controls.minDistance = 1.5;

        return {
            renderer,
            scene,
            camera,
            earth,
            clouds,
            resize,
            focusOn,
            controls
        };
    }

    let earthScene = null;
    let earthAnimId = null;
    let globeMarkerMesh = null;

    function initStarfield() {
        if (!earthScene) {
            earthScene = buildEarthScene(DOM.starfieldCanvas);
            const globeResizeObserver = new ResizeObserver(() => {
                if (earthScene) earthScene.resize();
            });
            globeResizeObserver.observe(DOM.starfieldCanvas);
        }

        const {
            renderer,
            scene,
            camera,
            earth,
            clouds,
            resize
        } = earthScene;

        function frame() {
            if (document.hidden) { earthAnimId = null; return; }
            resize();
            if (!prefersReducedMotion) {
                earth.rotation.y += 0.0016;
                clouds.rotation.y += 0.0021;
            }
            renderer.render(scene, camera);
            earthAnimId = requestAnimationFrame(frame);
        }
        if (earthAnimId) cancelAnimationFrame(earthAnimId);
        frame();
    }

    function stopStarfield() {
        if (earthAnimId) {
            cancelAnimationFrame(earthAnimId);
            earthAnimId = null;
        }
    }

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) return;
        if (!earthAnimId && earthScene && DOM.starfieldCanvas && DOM.starfieldCanvas.offsetParent !== null) {
            initStarfield();
        }
        if (!resultEarthAnimId && resultEarthScene && DOM.resultGlobeCanvas && DOM.resultGlobeCanvas.classList.contains('active')
            && Number.isFinite(lastResultLat) && Number.isFinite(lastResultLng)) {
            startResultGlobe(lastResultLat, lastResultLng, lastResultName);
        }
    });

    function setGlobeMarker(lat, lng, isTarget = false) {
        if (!earthScene) return;
        const {
            earth
        } = earthScene;
        if (globeMarkerMesh) {
            earth.remove(globeMarkerMesh);
            globeMarkerMesh = null;
        }
        const pos = latLngToVector3(lat, lng, 1.02);
        globeMarkerMesh = new THREE.Mesh(
            new THREE.SphereGeometry(0.018, 16, 16),
            new THREE.MeshBasicMaterial({
                color: isTarget ? 0x2dd4bf : 0x22d3ee
            })
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
        return () => {
            if (animId) cancelAnimationFrame(animId);
        };
    }

    // ---- Dedicated 3D globe instance for the RESULTS panel ----
    // FIX: this used to try to reuse #starfieldCanvas + earthScene, but that
    // canvas sits outside .results-split's stacking context (z-index 1-2)
    // while .results-split is z-index 20 with opaque panes on top of it —
    // so toggling "3D Globe" after results appeared visually did nothing;
    // the flat map underneath just kept showing. This is a separate scene
    // bound to #resultGlobeCanvas, which lives *inside* #satPane.
    // let resultEarthScene = null;
    // let resultEarthAnimId = null;
    // let resultGlobeMarkerMesh = null;

    // ---- Dedicated 3D globe instance for the RESULTS panel ----
    let resultEarthScene = null;
    let resultEarthAnimId = null;
    let resultGlobeMarkerMesh = null;
    let targetName = '';
    let lastResultLat = null;
    let lastResultLng = null;
    let lastResultName = '';

    function startResultGlobe(lat, lng, name) {
        if (!DOM.resultGlobeCanvas) return;
        targetName = name || 'Target Location';
        lastResultLat = lat;
        lastResultLng = lng;
        lastResultName = targetName;

        if (!resultEarthScene) {
            resultEarthScene = buildEarthScene(DOM.resultGlobeCanvas);
        }

        resultEarthScene.focusOn(lat, lng);

        // Remove old marker if exists
        if (resultGlobeMarkerMesh) {
            resultEarthScene.earth.remove(resultGlobeMarkerMesh);
            resultGlobeMarkerMesh = null;
        }

        // Create a glowing 3D goal pin
        const group = new THREE.Group();
        const coneGeo = new THREE.ConeGeometry(0.025, 0.08, 16);
        const coneMat = new THREE.MeshBasicMaterial({
            color: 0x2dd4bf
        });
        const cone = new THREE.Mesh(coneGeo, coneMat);
        cone.rotation.x = Math.PI;
        cone.position.y = 0.04;
        group.add(cone);

        const sphereGeo = new THREE.SphereGeometry(0.02, 16, 16);
        const sphereMat = new THREE.MeshBasicMaterial({
            color: 0xffffff
        });
        const sphere = new THREE.Mesh(sphereGeo, sphereMat);
        sphere.position.y = 0.02;
        group.add(sphere);

        const pos = latLngToVector3(lat, lng, 1.02);
        group.position.copy(pos);
        group.lookAt(new THREE.Vector3(0, 0, 0));

        resultGlobeMarkerMesh = group;
        resultEarthScene.earth.add(resultGlobeMarkerMesh);

        stopResultGlobeAnim();

        const {
            renderer,
            scene,
            camera,
            resize,
            controls
        } = resultEarthScene;
        const label = document.getElementById('resultGlobeLabel');

        // ===== CORRECT ZOOM TO MAP THRESHOLD =====
        const ZOOM_TO_MAP_THRESHOLD = 2.3;
        let hasTriggered = false;

        // Listen for when the user stops interacting (zoom/rotate/pan)
        if (controls._traceGeoEndHandler) controls.removeEventListener('end', controls._traceGeoEndHandler);
        controls._traceGeoEndHandler = () => {
            // Use camera distance to target for zoom threshold.
            const distance = camera.position.distanceTo(controls.target);

            if (!hasTriggered && distance < ZOOM_TO_MAP_THRESHOLD) {
                hasTriggered = true;
                switchFromGlobeToMap(lat, lng);
            }
        };
        controls.addEventListener('end', controls._traceGeoEndHandler);
        // =================================

        function updateLabel() {
            if (!label || !resultGlobeMarkerMesh) return;
            const vector = resultGlobeMarkerMesh.position.clone();
            vector.project(camera);
            const x = (vector.x * 0.5 + 0.5) * DOM.resultGlobeCanvas.clientWidth;
            const y = (-vector.y * 0.5 + 0.5) * DOM.resultGlobeCanvas.clientHeight;
            label.style.left = x + 'px';
            label.style.top = y + 'px';
            label.style.display = 'block';
            label.textContent = '📍 ' + targetName;
        }

        function frame() {
            if (document.hidden) { resultEarthAnimId = null; return; }
            resize();
            controls.update();
            updateLabel();
            renderer.render(scene, camera);
            resultEarthAnimId = requestAnimationFrame(frame);
        }
        if (resultEarthAnimId) cancelAnimationFrame(resultEarthAnimId);
        frame();
    }

    function stopResultGlobeAnim() {
        if (resultEarthAnimId) {
            cancelAnimationFrame(resultEarthAnimId);
            resultEarthAnimId = null;
        }
        const label = document.getElementById('resultGlobeLabel');
        if (label) label.style.display = 'none';
    }
    // ========== SWITCH BETWEEN FLAT MAP ↔ GLOBE (pre-results exploration) ==========
    function showGlobeMode(statusText) {
        DOM.mapEarthContainer.classList.add('hidden');
        DOM.starfieldCanvas.classList.add('visible');
        DOM.globeModeBadge.classList.add('visible');
        if (DOM.mapStatusText) DOM.mapStatusText.textContent = statusText || '🌐 3D Globe scan active...';
    }

    function showFlatMapMode() {
        DOM.starfieldCanvas.classList.remove('visible');
        DOM.globeModeBadge.classList.remove('visible');
        DOM.mapEarthContainer.classList.remove('hidden');
    }

    // ========== EXPLORATION SEQUENCE ==========
    async function runExplorationSequence(waypoints) {
        while (!analysisComplete) {
            randomWaypoints = shuffleArray(waypoints).slice(0, 6);
            totalWaypoints = randomWaypoints.length;

            for (let i = 0; i < totalWaypoints; i++) {
                if (analysisComplete) return;
                const wp = randomWaypoints[i];
                explorationIndex = i;

                if (i % 2 === 1) {
                    showGlobeMode(`🌐 Scanning ${wp.name}...`);
                    setGlobeMarker(wp.lat, wp.lng, false);
                    if (DOM.pcScanning) DOM.pcScanning.textContent = `// SCANNING ${wp.name.toUpperCase()}`;
                    await sleep(3200);
                    if (analysisComplete) break;
                    showFlatMapMode();
                    await sleep(400);
                    continue;
                }

                showFlatMapMode();
                if (DOM.mapStatusText) {
                    DOM.mapStatusText.textContent = `🌍 Probing ${wp.name}...`;
                }
                if (DOM.pcScanning) DOM.pcScanning.textContent = `// PROBING ${wp.name.toUpperCase()}`;

                await flyEarthTo(wp.lat, wp.lng, 4, 1400);
                if (analysisComplete) break;

                addProbeMarker(wp.lat, wp.lng, false);
                await sleep(600);
                if (analysisComplete) break;

                await flyEarthTo(wp.lat, wp.lng, 10, 1500);
                if (analysisComplete) break;
                await sleep(900);
                if (analysisComplete) break;

                await flyEarthTo(wp.lat, wp.lng, 3.5, 1200);
                if (analysisComplete) break;
                await sleep(500);
            }
        }
    }

    // ========== CINEMATIC TARGET REVEAL ==========
    async function revealTarget(lat, lng, name) {
        analysisComplete = true;

        if (!isValidCoord(lat, lng)) {
            console.warn('revealTarget: no usable coordinates; skipping map reveal', {lat, lng, name});
            return;
        }

        const latNum = Number(lat);
        const lngNum = Number(lng);

        showFlatMapMode();
        await sleep(300);

        if (earthMapInstance) {
            earthMapInstance.eachLayer(l => {
                if (l instanceof L.Marker && l.options?.probeMarker) {
                    earthMapInstance.removeLayer(l);
                }
            });
        }

        if (DOM.mapStatusText) DOM.mapStatusText.textContent = `🎯 Target acquired — ${name}...`;
        if (DOM.pcScanning) DOM.pcScanning.textContent = `// PINPOINTING ${name.toUpperCase()}`;

        await flyEarthTo(latNum, lngNum, 2.5, 1400);
        await sleep(500);

        addProbeMarker(latNum, lngNum, true);

        await flyEarthTo(latNum, lngNum, 6, 1600);
        await sleep(500);

        await flyEarthTo(latNum, lngNum, 11, 1500);
        await sleep(500);

        await flyEarthTo(latNum, lngNum, 15.5, 1700);
        await sleep(900);

        if (DOM.mapStatusText) {
            DOM.mapStatusText.innerHTML = `<span class="target-found">🎯 ${name} — CONFIRMED!</span>`;
        }
    }

    // ========== PROGRESS CARD ==========
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
        const p = Math.min(progress || 0, 100);
        if (DOM.pcHeadline) DOM.pcHeadline.textContent = label || labels[stage] || 'Analyzing…';
        if (DOM.pcFill) DOM.pcFill.style.width = p + '%';
        if (p > lastProgress) {
            lastProgressTime = Date.now();
        }
        lastProgress = p;
        renderSub(lastProgress);
        document.querySelectorAll('.pc-steps .pcs').forEach(el => {
            const idx = parseInt(el.dataset.step, 10);
            el.classList.remove('active', 'done');
            if (idx < stage) el.classList.add('done');
            else if (idx === stage) el.classList.add('active');
        });
    }

    // ========== SESSION ==========
    function clearTimers() {
        if (pollTimer) {
            clearTimeout(pollTimer);
            pollTimer = null;
        }
        if (streetViewSession) {
            streetViewSession.cancel();
            streetViewSession = null;
        }
        if (streetViewTimer) {
            clearTimeout(streetViewTimer);
            streetViewTimer = null;
        }
        if (completionTimers.length) {
            completionTimers.forEach(t => clearTimeout(t));
            completionTimers = [];
        }
        if (elapsedInterval) {
            clearInterval(elapsedInterval);
            elapsedInterval = null;
        }
    }

    function clearSession() {
        sessionStorage.removeItem('analysisId');
        sessionStorage.removeItem('analysisResult');
        clearTimers();
        currentAnalysisId = null;
        pollAttempts = 0;
        consecutiveErrors = 0;
        analysisComplete = true;
        isExploring = false;
        aiWaitingSince = null;
        hideQueueHint();
    }

    // ========== POLL BACKEND ==========
    function pollStatus() {
        if (!currentAnalysisId) return;
        pollAttempts++;
        if (pollAttempts > MAX_POLL_ATTEMPTS) {
            showError('This analysis is taking too long. Check your connection, then use Retry analysis below.', currentAnalysisId);
            clearSession();
            return;
        }

        fetch(`/api/analyze/${currentAnalysisId}/status`, {
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': CSRF_TOKEN
                },
                credentials: 'same-origin'
            })
            .then(r => {
                if (r.status === 419) {
                    throw new Error('Your session expired, reload the page');
                }
                if (!r.ok) return r.json().then(d => {
                    throw new Error(d.message || `HTTP ${r.status}`);
                });
                return r.json();
            })
            .then(data => {
                console.log('📊', data);
                consecutiveErrors = 0;

                if (data.image_url) {
                    if (data.image_url.includes('res.cloudinary.com')) {
                        uploadedImageURL = data.image_url;
                        sessionStorage.setItem('uploadedImage', uploadedImageURL);
                    }
                }

                const progress = data.progress || 0;
                const stage = data.stage || 0;

                updateProgressCard(stage, progress, data.stage_label);

                // Transient AI failures keep status 'processing' with this exact
                // label and a frozen progress bar while the server retries on
                // 30/90/180s release delays. Remember when we entered that path
                // so the generic no-progress timeout does not abort the wait.
                const waitingForAI = data.status === 'processing' &&
                    String(data.stage_label || '').indexOf(AI_WAIT_LABEL) === 0;

                if (waitingForAI) {
                    if (aiWaitingSince === null) aiWaitingSince = Date.now();
                } else {
                    aiWaitingSince = null;
                }
                const aiWaitWithinBudget = waitingForAI &&
                    (Date.now() - aiWaitingSince) < AI_WAIT_MAX_MS;

                if (data.status === 'delayed') {
                    showError(data.message || 'Analysis is taking longer than expected. Please retry.', currentAnalysisId);
                    return;
                }

                // Budget spent on the waiting path: stop, but say why.
                if (waitingForAI && !aiWaitWithinBudget) {
                    showError('The AI service stayed busy for about 20 minutes. Your photo is saved - use Retry analysis below.', currentAnalysisId);
                    return;
                }

                if (!aiWaitWithinBudget && Date.now() - lastProgressTime > 120000) {
                    showError('Analysis is taking longer than expected. Use Retry analysis below.', currentAnalysisId);
                    return;
                }

                if (progress > 8 && !isExploring && !analysisComplete) {
                    isExploring = true;
                    runExplorationSequence(GLOBAL_WAYPOINTS);
                }

                if (data.status === 'completed') {
                    hideQueueHint();
                    if (data.result) {
                        let result = data.result;
                        if (typeof result === 'string') {
                            try {
                                result = JSON.parse(result);
                            } catch (e) {}
                        }
                        sessionStorage.setItem('analysisResult', JSON.stringify(result));
                        clearSession();
                        analysisComplete = true;

                        // Never invent a location. null coordinates mean the
                        // place was identified but cannot be pinned.
                        const tLat = result.latitude ?? result.lat ?? null;
                        const tLng = result.longitude ?? result.lng ?? null;
                        const tName = result.landmark_name || result.city || result.place || 'Unknown Location';
                        const canReveal = isValidCoord(tLat, tLng) && !result.coordinates_missing;

                        dbg('Target coordinates:', {
                            tLat,
                            tLng,
                            tName,
                            canReveal
                        });
                        console.log('📦 Full result:', result);

                        // No fallback flight: with missing coordinates we keep
                        // the current view and show the honest status message.
                        const t1 = setTimeout(async () => {
                            if (analysisComplete !== true) return;
                            stopStarfield();
                            if (canReveal) {
                                await revealTarget(tLat, tLng, tName);
                            } else if (DOM.mapStatusText) {
                                DOM.mapStatusText.textContent = 'Location could not be pinned - coordinates missing';
                            }
                            DOM.whiteFlash.classList.remove('run');
                            void DOM.whiteFlash.offsetWidth;
                            DOM.whiteFlash.classList.add('run');
                            // revealResults defers its heavy tail (Leaflet
                            // init, image decode, controls) to rAF chunks.
                            const t2 = setTimeout(() => {
                                if (analysisComplete !== true) return;
                                revealResults(result);
                            }, 700);
                            completionTimers.push(t2);
                        }, 3000);
                        completionTimers.push(t1);
                    } else {
                        showError('Analysis completed but no results found');
                    }
                    return;
                }

                if (data.status === 'failed') {
                    hideQueueHint();
                    showError(data.error || 'Your photo is saved. Use Retry analysis below, or upload a different photo.', currentAnalysisId);
                    clearSession();
                    return;
                }

                // Server-side hint when the job has been queued too long
                // (e.g. no queue worker running). It disappears as soon as a
                // worker picks the job up (server stops sending it).
                if (data.hint) {
                    showQueueHint(data.hint);
                } else if (aiWaitWithinBudget) {
                    showQueueHint(AI_WAIT_CALM_HINT, false);
                } else {
                    hideQueueHint();
                }

                pollTimer = setTimeout(pollStatus, nextPollDelay());
            })
            .catch(err => {
                console.error('Poll error:', err);
                consecutiveErrors++;
                const delay = nextPollDelay();
                if (consecutiveErrors < 8 && pollAttempts <= MAX_POLL_ATTEMPTS) {
                    pollTimer = setTimeout(pollStatus, delay);
                } else {
                    showError('Could not reach the analysis service. Use Retry analysis below.', currentAnalysisId);
                    clearSession();
                }
            });
    }

    // ========== REVEAL RESULTS (Frame 7 & 8) ==========
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
        if (DOM.resultsSplit) DOM.resultsSplit.classList.add('show');

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
        // Phase E: the calibrated low_confidence flag always wins over the
        // numeric tier, so a weak result can never look "identified".
        const tier = data.low_confidence ? 'low' :
            (confidence >= 80 ? 'high' : confidence >= 50 ? 'medium' : 'low');

        if (DOM.verifiedPill) {
            DOM.verifiedPill.className = `verified-pill ${tier}`;
            DOM.verifiedPill.innerHTML = tier === 'high' ?
                '<i class="fas fa-check-circle"></i> Identified Location' :
                tier === 'medium' ?
                '<i class="fas fa-exclamation-circle"></i> Likely Location' :
                '<i class="fas fa-question-circle"></i> Low Confidence - check evidence';
        }

        // Phase E: show WHY the score is what it is, and up to three
        // alternatives when the result is low confidence. The model's own
        // self-reported number is shown only as a non-authoritative note.
        const factors = Array.isArray(data.confidence_factors) ? [...data.confidence_factors] : [];
        // A floored score is a rule, not evidence. Say so next to the number
        // instead of letting 49 look like measured support.
        if (data.confidence_floor_applied === true) {
            factors.unshift('Low evidence (score floored): the raw signal sum was below 49, so the identified-landmark floor set the score.');
        }
        if (data.model_reported_confidence !== undefined && data.model_reported_confidence !== null) {
            factors.push(`Model self-reported ${data.model_reported_confidence}% (not used for this score).`);
        }
        const alternatives = Array.isArray(data.alternatives) ? data.alternatives : [];

        if (DOM.placeTitle) DOM.placeTitle.textContent = data.landmark_name || data.city || 'Unknown';
        if (DOM.placeCountry) DOM.placeCountry.textContent = data.country || '';
        if (DOM.placeRegion) DOM.placeRegion.textContent = data.region || '';
        if (DOM.coordText) {
            DOM.coordText.textContent = (data.coordinates_missing || !hasCoords)
                ? 'Location could not be pinned - coordinates missing'
                : `${Math.abs(lat).toFixed(4)}\u00b0 ${lat>=0?'N':'S'}, ${Math.abs(lng).toFixed(4)}\u00b0 ${lng>=0?'E':'W'}`;
        }
        if (DOM.confPillText) DOM.confPillText.textContent = `${confidence}% Confidence${data.confidence_level ? ' - ' + data.confidence_level : ''}`;
        // Colour comes from the band class, never from inline styles: CSS
        // guarantees green only for >= 50 (high), amber for mid and red for
        // low, and the calibrated low_confidence flag always wins.
        if (DOM.confPill) DOM.confPill.className = `pill-confidence band-${tier}`;

        // Location precision: a model-only estimate is "approximate" (area
        // level: drawn as a circle labelled "Approximate area", never a pin);
        // a gazetteer-backed answer is "landmark". The uncertainty radius is in
        // meters and drives the map circle. A model-vs-gazetteer disagreement
        // beyond 5 km means the gazetteer answer was preferred upstream and is
        // surfaced here as a warning.
        const locationPrecision = data.location_precision || (hasCoords ? 'approximate' : 'region');
        const uncertaintyRadiusM = Number(data.uncertainty_radius_m ?? 0);
        const coordinateDisagreementKm = Number(data.coordinate_disagreement_km ?? 0);
        const locationDisagreed = coordinateDisagreementKm > 5;
        const lp = globalThis.LocationPrecision;
        const approximateArea = lp ? lp.isApproximateArea(locationPrecision) : (locationPrecision === 'approximate');

        if (locationDisagreed && DOM.mapStatusText) {
            DOM.mapStatusText.textContent = 'Model and gazetteer disagree by ' + coordinateDisagreementKm.toFixed(1) + ' km ? showing model estimate with widened uncertainty';
        }

        function scheduleRevealFrame(callback) {
            if (prefersReducedMotion || typeof window.requestAnimationFrame !== 'function') {
                setTimeout(callback, 0);
                return;
            }
            // Double rAF: the first frame lets the browser lay out the newly
            // shown results split; the second does the expensive DOM work.
            window.requestAnimationFrame(() => window.requestAnimationFrame(callback));
        }

        const renderReasoning = () => {
            if (DOM.confidenceEvidence) {
                DOM.confidenceEvidence.style.display = factors.length ? 'block' : 'none';
                if (DOM.confidenceFactors) {
                    DOM.confidenceFactors.innerHTML = '';
                    factors.forEach(f => {
                        const li = document.createElement('li');
                        li.textContent = f;
                        DOM.confidenceFactors.appendChild(li);
                    });
                }
                if (DOM.confidenceAlternatives) {
                    DOM.confidenceAlternatives.style.display = alternatives.length ? 'block' : 'none';
                    if (DOM.alternativeList) {
                        DOM.alternativeList.innerHTML = '';
                        alternatives.forEach(a => {
                            const li = document.createElement('li');
                            li.textContent = `${a.location || a.city || 'Unknown'} (${a.probability ?? 0}%)`;
                            DOM.alternativeList.appendChild(li);
                        });
                    }
                }
            }

            if (DOM.reasoningText) {
                const reasoning = data.reasoning || 'No reasoning available.';
                const latStr = hasCoords ?
                    `${Math.abs(lat).toFixed(4)}? ${lat>=0?'N':'S'}, ${Math.abs(lng).toFixed(4)}? ${lng>=0?'E':'W'}` :
                    'N/A';

                // Honest heading: "confirmed" needs a valid pin AND a score at or
                // above the defined threshold AND no low_confidence flag.
                const headingText = window.ConfidenceHeading
                    ? window.ConfidenceHeading.evidenceHeading(
                        confidence,
                        hasCoords && !data.coordinates_missing,
                        data.low_confidence === true
                    )
                    : ((hasCoords && !data.coordinates_missing && data.low_confidence !== true && confidence >= 65)
                        ? 'CONFIRMED LOCATION EVIDENCE'
                        : 'Possible location (low evidence)');
                const confirmedHeading = headingText === 'CONFIRMED LOCATION EVIDENCE';
                const headingColor = confirmedHeading ? 'var(--success)' : 'var(--warning)';
                const headingBg = confirmedHeading ? 'rgba(45,212,191,0.05)' : 'rgba(245,158,11,0.06)';

                DOM.reasoningText.innerHTML = `
                    <div style="margin-bottom:12px; padding:10px; border-left:3px solid ${headingColor}; background:${headingBg};">
                        <strong style="color:${headingColor};">${headingText}</strong><br>
                        <span style="color:var(--text-secondary);">Landmark: <strong>${data.landmark_name || data.city || 'Unknown'}</strong></span><br>
                        <span style="color:var(--text-secondary);">Coordinates: <strong>${latStr}</strong></span><br>
                        <span style="color:var(--text-secondary);">Confidence: <strong>${confidence}%</strong></span><br>
                        ${data.confidence_floor_applied === true
                            ? '<span style="color:var(--warning);"><strong>Low evidence (score floored)</strong></span><br>'
                            : ''}
                    </div>
                    <div style="line-height:1.8;">
                        <strong style="color:var(--accent);">AI Reasoning:</strong><br>
                        ${reasoning}
                    </div>
                    ${approximateArea ? '<span style="color:var(--warning);"><strong>Approximate area</strong> (uncertainty ~' + (Number(uncertaintyRadiusM ?? 0) / 1000).toFixed(1) + ' km)</span>' : ''}
                    ${locationDisagreed ? '<span style="color:var(--warning);"><strong>Model/gazetteer disagreement: ' + coordinateDisagreementKm.toFixed(1) + ' km (model kept; uncertainty widened)</strong></span>' : ''}
                `;
            }
        };

        if (DOM.photoFrame) {
            const imgUrl = data.image_url || data.result?.image_url || data.result_image_url || uploadedImageURL ||
                sessionStorage.getItem('uploadedImage');
            dbg('?? Full imgUrl:', imgUrl);

            // Split the heavy tail across animation frames instead of doing
            // Leaflet map init + image decode + control binding in one
            // synchronous callback. The first frame paints the text/pill
            // state above; the map gets its own frame, media/controls the
            // next. Runtime timing is UNVERIFIED in a browser (node --check
            // only), but the work is no longer one uninterrupted block.
            scheduleRevealFrame(() => {
                renderResultMap(data, lat, lng, hasCoords, imgUrl);
                scheduleRevealFrame(() => {
                    renderResultMedia(data, imgUrl, lat, lng, hasCoords);
                    renderReasoning();
                });
            });
        } else {
            scheduleRevealFrame(renderReasoning);
        }

        function renderResultMap(data, lat, lng, hasCoords, imgUrl) {
            if (data.coordinates_missing || !hasCoords) {
                // No pin, no fallback globe flight: show a clear message only.
                if (DOM.mapStatusText) {
                    DOM.mapStatusText.textContent = 'Location could not be pinned - coordinates missing';
                }
                return;
            }

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

            // Uncertainty radius (meters) from the enriched result: drawn as a
            // dashed circle only for area-level answers ("Approximate area").
            // A gazetteer-backed landmark pin has its own (small) radius and is
            // drawn as a pin, never as an area circle.
            const areaRadiusM = Number(data.uncertainty_radius_m ?? 0);
            const drawAreaCircle = lp
                ? lp.shouldDrawUncertaintyCircle(locationPrecision, areaRadiusM)
                : (approximateArea && areaRadiusM > 0);
            if (drawAreaCircle) {
                L.circle([lat, lng], {
                    radius: areaRadiusM,
                    color: '#f59e0b',
                    weight: 2,
                    dashArray: '6 6',
                    fillColor: '#f59e0b',
                    fillOpacity: 0.08
                }).addTo(resultMapInstance);
            }
        }

        function renderResultMedia(data, imgUrl, lat, lng, hasCoords) {
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
    }

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

        const roadsBtn = document.getElementById('roadsToggle');
        const terrainBtn = document.getElementById('terrainToggle');
        const globeBtn = document.getElementById('globeToggleBtn');
        const streetBtn = document.getElementById('streetToggleBtn');

        const removeStreetView = () => {
            if (streetViewSession) {
                streetViewSession.cancel();
                streetViewSession = null;
            }
            if (streetViewTimer) {
                clearTimeout(streetViewTimer);
                streetViewTimer = null;
            }
            DOM.satPane?.querySelector('.street-inline')?.remove();
        };

        // ===== SET TILE (Roads / Terrain) =====
        // ===== SET TILE (Roads / Terrain) =====
        const setTile = (mode) => {
            currentTileMode = mode;
            if (roadsBtn) roadsBtn.classList.toggle('active', mode === 'roads');
            if (terrainBtn) terrainBtn.classList.toggle('active', mode === 'terrain');

            removeStreetView();
            stopResultGlobeAnim();
            if (DOM.resultGlobeCanvas) DOM.resultGlobeCanvas.classList.remove('active');
            const resultMapDiv = document.getElementById('resultMap');
            if (resultMapDiv) resultMapDiv.style.display = 'block';

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
                setTimeout(() => resultMapInstance.invalidateSize(), 200);
            }
        };

        // ===== AUTO-SWITCH FROM 3D GLOBE TO MAP =====
        // ===== AUTO-SWITCH FROM 3D GLOBE TO MAP =====
        window.switchFromGlobeToMap = (targetLat, targetLng) => {
            removeStreetView();
            stopResultGlobeAnim();
            if (DOM.resultGlobeCanvas) DOM.resultGlobeCanvas.classList.remove('active');
            const resultMapDiv = document.getElementById('resultMap');
            if (resultMapDiv) resultMapDiv.style.display = 'block';

            // Set map to specific target and zoom in
            if (resultMapInstance) {
                resultMapInstance.setView([targetLat, targetLng], 15); // Zoom level 15
                setTimeout(() => resultMapInstance.invalidateSize(), 200);
            }

            // Update buttons
            if (globeBtn) globeBtn.classList.remove('active');
            if (streetBtn) streetBtn.classList.remove('active');
            if (roadsBtn) roadsBtn.classList.remove('active');
            if (terrainBtn) terrainBtn.classList.add('active');

            showToast('🗺️ Zoomed to precise location!');
        };

        // ===== STRICT MODE SWITCHING =====
        const switchTo3DGlobe = () => {
            removeStreetView();

            // Hide Flat Map, show 3D Globe
            const resultMapDiv = document.getElementById('resultMap');
            if (resultMapDiv) resultMapDiv.style.display = 'none';
            if (DOM.resultGlobeCanvas) DOM.resultGlobeCanvas.classList.add('active');

            startResultGlobe(lat, lng, data.landmark_name || data.city || 'Target');

            globeBtn.classList.add('active');
            streetBtn.classList.remove('active');
            roadsBtn.classList.remove('active');
            terrainBtn.classList.remove('active');
            showToast('🌐 Real 3D Globe Mode Active');
        };

        const switchToStreetView = () => {
            removeStreetView();
            stopResultGlobeAnim();
            if (DOM.resultGlobeCanvas) DOM.resultGlobeCanvas.classList.remove('active');

            // Show Flat Map (to load Street View)
            const resultMapDiv = document.getElementById('resultMap');
            if (resultMapDiv) resultMapDiv.style.display = 'block';

            showStreetView(lat, lng);

            streetBtn.classList.add('active');
            globeBtn.classList.remove('active');
            roadsBtn.classList.remove('active');
            terrainBtn.classList.remove('active');
            showToast('📸 Real-Time Street View Active');
        };

        // Choose default view based on screen size
        if (window.innerWidth < 768) {
            // Mobile: show flat map (Roads layer)
            setTile('roads');
            // Ensure globe is hidden, map is shown
            const resultMapDiv = document.getElementById('resultMap');
            if (resultMapDiv) resultMapDiv.style.display = 'block';
            if (DOM.resultGlobeCanvas) DOM.resultGlobeCanvas.classList.remove('active');
            stopResultGlobeAnim();
            // Update button states
            if (globeBtn) globeBtn.classList.remove('active');
            if (streetBtn) streetBtn.classList.remove('active');
            if (roadsBtn) roadsBtn.classList.add('active');
            if (terrainBtn) terrainBtn.classList.remove('active');
        } else {
            // Desktop: show 3D globe
            switchTo3DGlobe();
        }

        // Bind the 4 buttons
        globeBtn.onclick = switchTo3DGlobe;
        streetBtn.onclick = switchToStreetView;
        if (roadsBtn) roadsBtn.onclick = () => {
            setTile('roads');
        };
        if (terrainBtn) terrainBtn.onclick = () => {
            setTile('terrain');
        };

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

    // FIX: Street View used to get permanently stuck on Google's own
    // "loading pin" spinner with no way out whenever the embed was slow,
    // blocked (ad blockers / CSP / offline), or the key was bad. Now it
    // shows an explicit loading state, listens for the iframe's own load
    // event, AND falls back to a clear "open in a new tab" notice if the
    // embed hasn't loaded within STREET_VIEW_TIMEOUT_MS.
    const STREET_VIEW_TIMEOUT_MS = 6000;

    function showStreetView(lat, lng) {
        // Hard gate: no coordinates -> no URL is ever constructed. This also
        // protects the embedded key from being attached to a NaN request.
        if (!isValidCoord(lat, lng)) {
            if (DOM.satPane) {
                DOM.satPane.querySelector('.street-inline')?.remove();
                const notice = document.createElement('div');
                notice.className = 'street-inline street-setup-notice';
                notice.textContent = 'Street View is unavailable: this result has no valid coordinates.';
                DOM.satPane.appendChild(notice);
            }
            console.warn('showStreetView: no valid coordinates; no URL built');
            return;
        }
        if (streetViewSession) {
            streetViewSession.cancel();
            streetViewSession = null;
        }
        if (streetViewTimer) {
            clearTimeout(streetViewTimer);
            streetViewTimer = null;
        }
        DOM.satPane?.querySelector('.street-inline')?.remove();
        const wrap = document.createElement('div');
        wrap.className = 'street-inline';
        const fullUrl = `https://www.google.com/maps/@?api=1&map_action=pano&viewpoint=${Number(lat)},${Number(lng)}`;

        const globeBtn = document.getElementById('globeToggleBtn');
        const streetBtn = document.getElementById('streetToggleBtn');

        if (globeBtn) globeBtn.classList.remove('active');
        if (streetBtn) streetBtn.classList.add('active');

        const streetFallback = (typeof StreetViewFallback !== 'undefined' ? StreetViewFallback : window.StreetViewFallback);
        const onBack = () => {
            if (streetViewSession) {
                streetViewSession.cancel();
                streetViewSession = null;
            }
            wrap.remove();
            if (globeBtn) globeBtn.classList.remove('active');
            if (streetBtn) streetBtn.classList.remove('active');
            const roadsBtn = document.getElementById('roadsToggle');
            const terrainBtn = document.getElementById('terrainToggle');
            if (currentTileMode === 'terrain') terrainBtn?.classList.add('active');
            else roadsBtn?.classList.add('active');
        };
        const bindBack = () => {
            if (streetFallback && typeof streetFallback.safeBindBack === 'function') {
                streetFallback.safeBindBack(wrap, onBack);
                return;
            }
            const backBtn = wrap.querySelector('#streetBackBtn');
            if (backBtn) backBtn.onclick = onBack;
        };

        if (!GOOGLE_MAPS_EMBED_KEY) {
            wrap.innerHTML = `
        <button class="street-back" id="streetBackBtn"><i class="fas fa-arrow-left"></i></button>
        <div class="street-setup-notice">
            <i class="fas fa-street-view"></i>
            <h4>Street View needs one setup step</h4>
            <p>Inline Street View requires a free Google Maps Embed API key — it isn't configured yet, so it can't show the real panorama here.</p>
            <button class="street-setup-cta" id="streetOpenRealBtn">
                <i class="fas fa-up-right-from-square"></i> Open real Street View
            </button>
        </div>`;
            DOM.satPane.appendChild(wrap);
            bindBack();
            wrap.querySelector('#streetOpenRealBtn')?.addEventListener('click', () => window.open(fullUrl, '_blank'));
            return;
        }

        const embedSrc =
            `https://www.google.com/maps/embed/v1/streetview?key=${encodeURIComponent(GOOGLE_MAPS_EMBED_KEY)}&location=${Number(lat)},${Number(lng)}&heading=0&pitch=0&fov=90`;

        wrap.innerHTML = `
        <button class="street-back" id="streetBackBtn"><i class="fas fa-arrow-left"></i></button>
        <div class="street-loading-notice" id="streetLoadingNotice">
            <div class="spinner"></div>
            <p>Loading Street View…</p>
        </div>
        <iframe id="streetIframe" style="opacity:0;" src="${embedSrc}" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allow="accelerometer; gyroscope; magnetometer; fullscreen"></iframe>
        <button class="street-open-full" id="streetOpenFullBtn" style="display:none;"><i class="fas fa-expand"></i> Open Full Street View</button>
    `;
        DOM.satPane.appendChild(wrap);
        bindBack();

        const iframe = wrap.querySelector('#streetIframe');
        const loadingNotice = wrap.querySelector('#streetLoadingNotice');
        const openFullBtn = wrap.querySelector('#streetOpenFullBtn');

        const showTimedOutFallback = () => {
            wrap.innerHTML = `
        <button class="street-back" id="streetBackBtn"><i class="fas fa-arrow-left"></i></button>
        <div class="street-setup-notice">
            <i class="fas fa-street-view"></i>
            <h4>Street View is taking too long</h4>
            <p>It didn't load in time — this can happen if a browser extension, network, or firewall is blocking Google Maps embeds. You can still open the real panorama in a new tab.</p>
            <button class="street-setup-cta" id="streetOpenRealBtn">
                <i class="fas fa-up-right-from-square"></i> Open real Street View
            </button>
        </div>`;
            bindBack();
            wrap.querySelector('#streetOpenRealBtn')?.addEventListener('click', () => window.open(fullUrl, '_blank'));
        };

        streetViewSession = streetFallback && typeof streetFallback.createSession === 'function'
            ? streetFallback.createSession({
                wrap,
                timeoutMs: STREET_VIEW_TIMEOUT_MS,
                isCompleted: () => analysisComplete === true,
                onFallback: showTimedOutFallback,
                onBack
            })
            : null;

        if (!streetViewSession) {
            // Fallback: no helper loaded; keep minimal guard.
            let settled = false;
            streetViewTimer = setTimeout(() => {
                streetViewTimer = null;
                if (settled || !wrap.isConnected || analysisComplete === true) return;
                settled = true;
                showTimedOutFallback();
            }, STREET_VIEW_TIMEOUT_MS);
            streetViewSession = { cancel: () => { settled = true; if (streetViewTimer) { clearTimeout(streetViewTimer); streetViewTimer = null; } }, isSettled: () => settled };
        }

        const settleLoaded = () => {
            if (streetViewSession && streetViewSession.isSettled()) return;
            if (streetViewSession) streetViewSession.cancel();
            if (loadingNotice) loadingNotice.remove();
            if (iframe) iframe.style.opacity = '1';
            if (openFullBtn) openFullBtn.style.display = '';
        };

        if (iframe) {
            iframe.addEventListener('load', settleLoaded);
            iframe.addEventListener('error', () => {
                if (streetViewSession && !streetViewSession.isSettled()) {
                    streetViewSession.triggerFallback();
                }
            });
        }

        wrap.querySelector('#streetOpenFullBtn')?.addEventListener('click', () => window.open(fullUrl, '_blank'));
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
        lastProgress = 0;
        lastProgressTime = Date.now();
        aiWaitingSince = null;
        analysisComplete = false;
        isExploring = false;

        if (DOM.progressCard) DOM.progressCard.style.display = 'block';
        if (DOM.pcError) DOM.pcError.style.display = 'none';
        hideRetryButton();
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

        initStarfield();

        const startPt = GLOBAL_WAYPOINTS[Math.floor(Math.random() * GLOBAL_WAYPOINTS.length)];
        initEarthMap([startPt.lat, startPt.lng], 3);

        if (DOM.mapStatusText) DOM.mapStatusText.textContent = '🌍 Initializing OSINT Engine...';

        pollStatus();
    }

    document.addEventListener('DOMContentLoaded', () => {
        if (DOM.pcRetry) DOM.pcRetry.addEventListener('click', retryFailedAnalysis);

        const analysisId = sessionStorage.getItem('analysisId');
        const uploadedImage = sessionStorage.getItem('uploadedImage');

        if (analysisId) {
            startAnalysis(analysisId, uploadedImage);
        } else {
            initStarfield();
            showGlobeMode('🌍 Ready for analysis');
            if (DOM.statusDot) DOM.statusDot.style.background = 'var(--success)';
            showToast('🌍 OSINT Engine ready');
        }
    });

    // ========== COORDINATED RESIZE & LAYOUT HANDLING ==========
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
            if (resultEarthScene) resultEarthScene.resize();
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
        hideRetryButton();
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
