/**
 * TraceGeo front-end — landmark identification with correction feedback loop.
 * 
 * Features:
 * - Upload image → AI identifies landmark
 * - Shows coordinates, weather, nearby places
 * - Clickable Google Maps link
 * - "🚩 Report wrong location" button → submits correction to database
 * - Verified corrections are stored permanently and used for future requests
 */
(function () {
  'use strict';

  // =============================================
  // CONFIGURATION
  // =============================================
  const API_BASE = '/api';
  const REQUEST_TIMEOUT_MS = 160000;
  const GEO_TIMEOUT_MS = 4000;

  const state = {
    file: null,
    mode: 'fast',
    lastResult: null,
    analyzing: false,
    geoHint: null,
    geoAsked: false,
    currentCoords: null,
    currentLandmarkId: null,
  };

  // =============================================
  // DOM HELPERS
  // =============================================
  const $ = (id) => document.getElementById(id);
  const $$ = (sel) => document.querySelectorAll(sel);
  const setText = (id, value) => {
    const el = $(id);
    if (el) el.textContent = value ?? '--';
  };

  // =============================================
  // TOAST NOTIFICATIONS
  // =============================================
  function toast(msg, type = 'info') {
    const el = $('toast');
    const msgEl = $('toastMsg');
    if (!el || !msgEl) {
      console.log('[Toast]', msg);
      return;
    }
    msgEl.textContent = msg;
    el.className = 'show';
    el.style.borderColor = type === 'error' ? '#f87171' : type === 'success' ? '#4ade80' : 'var(--border)';
    clearTimeout(toast._t);
    toast._t = setTimeout(() => el.classList.remove('show'), 4000);
  }

  // =============================================
  // TOKEN / AUTH
  // =============================================
  function getToken() {
    return localStorage.getItem('tg_token');
  }

  // =============================================
  // ESCAPE HTML
  // =============================================
  function escapeHtml(str) {
    return String(str).replace(/[&<>"']/g, (c) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#39;',
    }[c] || c));
  }

  // =============================================
  // COORDINATE SOURCE LABELS
  // =============================================
  const COORD_SOURCE_LABELS = {
    user_verified: { label: '✓✓✓ Confirmed by a previous correction', color: '#4ade80' },
    gps_exif: { label: '✓✓ GPS-verified (from photo metadata)', color: '#4ade80' },
    geocoded: { label: '✓ Verified location', color: '#4ade80' },
    geocoded_fallback: { label: '≈ Approximate — city level', color: '#facc15' },
    ai_estimate: { label: '⚠ Unverified AI estimate', color: '#fb923c' },
    none: { label: '⚠ No coordinates available', color: '#f87171' },
  };

  // =============================================
  // LOCATION HINTS (Browser Geolocation)
  // =============================================
  function isLocationHintEnabled() {
    const toggle = $('useLocationHint');
    if (toggle && 'checked' in toggle) return toggle.checked;
    return sessionStorage.getItem('tg_geo_declined') !== '1';
  }

  function requestGeoHint() {
    if (state.geoAsked || !isLocationHintEnabled() || !('geolocation' in navigator)) {
      return Promise.resolve(null);
    }
    state.geoAsked = true;

    return new Promise((resolve) => {
      const timer = setTimeout(() => resolve(null), GEO_TIMEOUT_MS);
      navigator.geolocation.getCurrentPosition(
        (pos) => {
          clearTimeout(timer);
          state.geoHint = {
            lat: pos.coords.latitude,
            lng: pos.coords.longitude,
            accuracy_m: Math.round(pos.coords.accuracy),
          };
          resolve(state.geoHint);
        },
        () => {
          clearTimeout(timer);
          sessionStorage.setItem('tg_geo_declined', '1');
          resolve(null);
        },
        { timeout: GEO_TIMEOUT_MS, maximumAge: 300000, enableHighAccuracy: false }
      );
    });
  }

  function getInstantHints() {
    const hints = {};
    try {
      hints.timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || null;
    } catch {
      hints.timezone = null;
    }
    hints.locale = navigator.language || null;
    return hints;
  }

  function appendLocationHints(form) {
    const instant = getInstantHints();
    if (instant.timezone) form.append('hint_timezone', instant.timezone);
    if (instant.locale) form.append('hint_locale', instant.locale);
    if (state.geoHint) {
      form.append('hint_lat', String(state.geoHint.lat));
      form.append('hint_lng', String(state.geoHint.lng));
      form.append('hint_accuracy_m', String(state.geoHint.accuracy_m));
    }
  }

  // =============================================
  // MAKE ACTION BUTTON
  // =============================================
  function makeActionButton(label, onClick, icon = '') {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'tg-action-btn';
    btn.innerHTML = icon ? `${icon} ${label}` : label;
    btn.style.cssText = [
      'padding:8px 16px',
      'border-radius:8px',
      'border:1px solid rgba(139,92,246,0.3)',
      'background:rgba(139,92,246,0.1)',
      'color:#c4b5fd',
      'font-size:13px',
      'font-weight:500',
      'cursor:pointer',
      'transition:all 0.2s ease',
      'min-height:36px',
      'touch-action:manipulation',
      'display:inline-flex',
      'align-items:center',
      'gap:6px',
    ].join(';');
    btn.addEventListener('mouseenter', () => {
      btn.style.background = 'rgba(139,92,246,0.2)';
    });
    btn.addEventListener('mouseleave', () => {
      btn.style.background = 'rgba(139,92,246,0.1)';
    });
    btn.addEventListener('click', onClick);
    return btn;
  }

  // =============================================
  // COPY COORDINATES
  // =============================================
  async function copyCoords(lat, lng) {
    const text = `${lat}, ${lng}`;
    try {
      await navigator.clipboard.writeText(text);
      toast('✅ Coordinates copied to clipboard!');
    } catch {
      toast('❌ Could not copy — browser blocked clipboard access.', 'error');
    }
  }

  // =============================================
  // OPEN CORRECTION PROMPT
  // =============================================
  async function openCorrectionPrompt(landmarkId, currentLat, currentLng, landmarkName) {
    if (!landmarkId) {
      toast('❌ No landmark ID available to correct.', 'error');
      return;
    }

    const input = window.prompt(
      `Enter the correct coordinates for "${landmarkName || 'this landmark'}"\nas "latitude, longitude" (e.g. 10.4825, 104.2885):`,
      currentLat != null && currentLng != null ? `${currentLat}, ${currentLng}` : ''
    );

    if (input === null) return; // User cancelled

    const parts = input.split(',').map((s) => parseFloat(s.trim()));
    const [lat, lng] = parts;

    if (parts.length !== 2 || Number.isNaN(lat) || Number.isNaN(lng) || Math.abs(lat) > 90 || Math.abs(lng) > 180) {
      toast('❌ Invalid coordinates — expected "latitude, longitude" (e.g. 10.4825, 104.2885).', 'error');
      return;
    }

    // Submit the correction
    const headers = { 'Content-Type': 'application/json' };
    const token = getToken();
    if (token) headers.Authorization = `Bearer ${token}`;

    try {
      const res = await fetch(`${API_BASE}/landmarks/${landmarkId}/correct-location`, {
        method: 'POST',
        headers,
        body: JSON.stringify({ latitude: lat, longitude: lng }),
      });
      const data = await res.json();

      if (!res.ok) {
        toast(data.message || '❌ Could not submit correction.', 'error');
        return;
      }

      toast('✅ Correction submitted — thank you! The location will be updated.', 'success');

      // Update the displayed coordinates immediately
      if (state.currentCoords) {
        state.currentCoords = { lat, lng };
        setText('latitude', lat);
        setText('longitude', lng);
        // Update DMS if available
        const dmsEl = $('coordDMS');
        if (dmsEl) dmsEl.textContent = `${lat}, ${lng}`;
      }
    } catch (err) {
      console.error('Correction submission error:', err);
      toast('❌ Network error — could not submit correction.', 'error');
    }
  }

  // =============================================
  // WIRE MAP ACTIONS & CORRECTION BUTTON
  // =============================================
  function wireMapActions(lat, lng, name, landmarkId) {
    if (lat === null || lat === undefined || lng === null || lng === undefined) {
      const bar = $('tgMapActionBar');
      if (bar) bar.innerHTML = '';
      return;
    }

    state.currentCoords = { lat, lng };
    state.currentLandmarkId = landmarkId;

    const mapsUrl = `https://www.google.com/maps?q=${encodeURIComponent(lat)},${encodeURIComponent(lng)}`;
    const directionsUrl = `https://www.google.com/maps/dir//${lat},${lng}`;
    const streetViewUrl = `https://www.google.com/maps/@?api=1&map_action=pano&viewpoint=${lat},${lng}`;

    // Make coordinates clickable
    ['latitude', 'longitude', 'city'].forEach((id) => {
      const el = $(id);
      if (!el) return;
      el.style.cursor = 'pointer';
      el.title = `Open in Google Maps: ${name || 'Location'}`;
      el.setAttribute('role', 'button');
      el.setAttribute('tabindex', '0');
      el.onclick = () => window.open(mapsUrl, '_blank');
      el.onkeydown = (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          window.open(mapsUrl, '_blank');
        }
      };
    });

    // Build action bar
    let bar = $('tgMapActionBar');
    if (!bar) {
      const grid = document.querySelector('.coord-row') || document.querySelector('.intel-grid');
      if (grid && grid.parentElement) {
        bar = document.createElement('div');
        bar.id = 'tgMapActionBar';
        bar.style.cssText = 'display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;padding-top:12px;border-top:1px solid var(--border, rgba(255,255,255,0.06));';
        grid.parentElement.insertBefore(bar, grid.nextSibling);
      } else {
        // Fallback: find a good spot
        const resultsData = $('resultsData');
        if (resultsData) {
          bar = document.createElement('div');
          bar.id = 'tgMapActionBar';
          bar.style.cssText = 'display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;padding:12px 0;';
          resultsData.appendChild(bar);
        }
      }
    }

    if (bar) {
      bar.innerHTML = '';
      bar.appendChild(makeActionButton('📍 Google Maps', () => window.open(mapsUrl, '_blank'), '🗺️'));
      bar.appendChild(makeActionButton('🧭 Directions', () => window.open(directionsUrl, '_blank'), '🧭'));
      bar.appendChild(makeActionButton('👁️ Street View', () => window.open(streetViewUrl, '_blank'), '👁️'));
      bar.appendChild(makeActionButton('📋 Copy Coords', () => copyCoords(lat, lng), '📋'));

      // ✅ CORRECTION BUTTON - the key feature!
      if (landmarkId) {
        const correctionBtn = makeActionButton(
          '🚩 Report wrong location',
          () => openCorrectionPrompt(landmarkId, lat, lng, name),
          '🚩'
        );
        correctionBtn.id = 'tgCorrectionBtn';
        correctionBtn.style.borderColor = 'rgba(251,146,60,0.4)';
        correctionBtn.style.color = '#fb923c';
        correctionBtn.style.background = 'rgba(251,146,60,0.1)';
        correctionBtn.addEventListener('mouseenter', () => {
          correctionBtn.style.background = 'rgba(251,146,60,0.2)';
        });
        correctionBtn.addEventListener('mouseleave', () => {
          correctionBtn.style.background = 'rgba(251,146,60,0.1)';
        });
        bar.appendChild(correctionBtn);
      }
    }
  }

  // =============================================
  // RENDER RESULTS
  // =============================================
  function renderResults(data, file) {
    const { landmark, weather, elevation, sun_data: sun, nearby_places: places, coordinates } = data;

    const resultsData = $('resultsData');
    const noResults = $('noResults');
    if (resultsData) resultsData.style.display = 'block';
    if (noResults) noResults.style.display = 'none';

    // Image
    const img = $('uploadedImage');
    if (img && file) img.src = URL.createObjectURL(file);

    // Basic info
    const name = landmark.name || '--';
    setText('city', name);
    setText('country', landmark.full_location || [landmark.city, landmark.country].filter(Boolean).join(', '));
    setText('latitude', landmark.latitude ?? '--');
    setText('longitude', landmark.longitude ?? '--');

    // Confidence gauge
    const conf = landmark.confidence ?? 0;
    const gaugeFill = $('confGaugeFill');
    if (gaugeFill) {
      const circumference = 326.73;
      gaugeFill.style.strokeDashoffset = String(circumference - (circumference * conf) / 100);
    }
    setText('confScoreVal', conf);
    setText('confGaugeLevel', conf >= 75 ? 'High' : conf >= 40 ? 'Medium' : 'Low');

    // Description
    const explanation = $('aiExplanation');
    if (explanation) {
      explanation.textContent = [landmark.description, landmark.reasoning].filter(Boolean).join(' ');
    }

    // Tags
    const tagsWrap = $('evidenceTagsContainer');
    if (tagsWrap && Array.isArray(landmark.tags)) {
      tagsWrap.style.display = landmark.tags.length ? 'flex' : 'none';
      tagsWrap.innerHTML = landmark.tags
        .map((t) => `<span class="evidence-tag">${escapeHtml(t)}</span>`)
        .join('');
    }

    // Coordinates
    if (coordinates) {
      setText('coordDMS', coordinates.dms || '--');
      setText('coordGeohash', coordinates.geohash || '--');
    }

    // Weather
    if (weather) {
      setText('weatherTemp', weather.temp != null ? `${weather.temp}°C` : '--°C');
      setText('weatherDesc', weather.description || '--');
      setText('weatherHumidity', weather.humidity != null ? `${weather.humidity}%` : '--%');
      setText('weatherWind', weather.wind_speed != null ? `${weather.wind_speed} km/h` : '-- km/h');
      setText('weatherClouds', weather.clouds != null ? `${weather.clouds}%` : '--%');
    }

    // Elevation
    if (elevation) {
      setText('elevMeters', elevation.meters != null ? `${elevation.meters} m` : '-- m');
      setText('elevFeet', elevation.feet != null ? `${elevation.feet} ft` : '-- ft');
      setText('elevCategory', elevation.category || '--');
    }

    // Sun data
    if (sun) {
      setText('sunRise', sun.sunrise || '--');
      setText('sunSet', sun.sunset || '--');
      setText('sunAltitude', sun.sun_altitude != null ? `${sun.sun_altitude}°` : '--°');
      setText('locTimezone', sun.timezone || '--');
    }

    // Nearby places
    const landmarksList = $('landmarksList');
    if (landmarksList) {
      landmarksList.innerHTML = (places || [])
        .map(
          (p) => `<div class="intel-item"><span class="intel-label">${escapeHtml(p.type || 'Place')}</span>
                   <span class="intel-value">${escapeHtml(p.name || 'Unknown')} · ${p.distance || '?'}m</span></div>`
        )
        .join('') || '<p style="color:var(--text-muted);font-size:13px;">No nearby landmarks found.</p>';
    }

    // ✅ Map actions with correction button
    const hasCoords = landmark.latitude !== null && landmark.latitude !== undefined &&
      landmark.longitude !== null && landmark.longitude !== undefined;

    if (hasCoords) {
      wireMapActions(landmark.latitude, landmark.longitude, name, landmark.id);
    }

    // Tabs
    wireResultTabs();

    // Refine CTA
    wireRefineAnalysisCta(conf);

    // Toast
    toast(`✅ Identified: ${name} (${conf}% confidence)`);
  }

  // =============================================
  // ANALYZE FUNCTION
  // =============================================
  async function analyze(file, modeOverride) {
    if (state.analyzing) return;
    const mode = modeOverride || state.mode;

    await requestGeoHint();

    const form = new FormData();
    form.append('image', file);
    form.append('mode', mode);
    appendLocationHints(form);

    const headers = {};
    const token = getToken();
    if (token) headers.Authorization = `Bearer ${token}`;

    const controller = new AbortController();
    const hardTimeout = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);

    state.analyzing = true;
    setAnalyzeButtonsBusy(true);
    showSkeleton(true);

    try {
      const res = await fetch(`${API_BASE}/identify`, {
        method: 'POST',
        headers,
        body: form,
        signal: controller.signal,
      });
      const data = await res.json();

      if (!res.ok) {
        toast(data.message || '❌ Could not analyze that image.', 'error');
        return;
      }

      state.lastResult = data;
      state.lastMode = mode;
      renderResults(data, file);
    } catch (err) {
      if (err.name === 'AbortError') {
        toast('⏱️ That took too long — please try again.', 'error');
      } else {
        console.error(err);
        toast('❌ Network error — please try again.', 'error');
      }
    } finally {
      clearTimeout(hardTimeout);
      state.analyzing = false;
      setAnalyzeButtonsBusy(false);
      showSkeleton(false);
    }
  }

  // =============================================
  // UI HELPERS
  // =============================================
  function showSkeleton(show) {
    const skel = $('skeletonResults');
    const noRes = $('noResults');
    if (skel) skel.style.display = show ? 'block' : 'none';
    if (noRes) noRes.style.display = show ? 'none' : (state.lastResult ? 'none' : 'block');
  }

  function setAnalyzeButtonsBusy(busy) {
    ['stagedAnalyzeBtn'].forEach((id) => {
      const btn = $(id);
      if (!btn) return;
      btn.disabled = busy;
      btn.style.opacity = busy ? '0.6' : '';
      btn.style.cursor = busy ? 'wait' : '';
    });
  }

  function wireRefineAnalysisCta(confidence) {
    const anchor = $('confScoreVal');
    if (!anchor) return;
    const gaugeContainer = anchor.closest('div') || anchor.parentElement;
    if (!gaugeContainer || !gaugeContainer.parentElement) return;

    let cta = $('tgRefineCta');
    const shouldShow = state.lastMode === 'fast' && confidence < 90 && state.file;

    if (!shouldShow) {
      if (cta) cta.style.display = 'none';
      return;
    }

    if (!cta) {
      cta = document.createElement('button');
      cta.id = 'tgRefineCta';
      cta.type = 'button';
      cta.style.cssText = [
        'margin-top:10px',
        'padding:8px 16px',
        'border-radius:8px',
        'border:1px solid rgba(139,92,246,0.35)',
        'background:transparent',
        'color:#c4b5fd',
        'font-size:13px',
        'cursor:pointer',
        'min-height:36px',
      ].join(';');
      gaugeContainer.parentElement.insertBefore(cta, gaugeContainer.nextSibling);
    }
    cta.style.display = '';
    cta.textContent = '🔍 Refine with detailed AI analysis';
    cta.onclick = () => {
      if (!state.file) return;
      cta.disabled = true;
      cta.textContent = '⏳ Re-analyzing…';
      analyze(state.file, 'detailed');
    };
  }

  // =============================================
  // TABS
  // =============================================
  let tabsWired = false;

  function wireResultTabs() {
    if (tabsWired) return;

    const labels = ['Location', 'Weather', 'Environment', 'Nearby'];
    const buttons = findTabButtons(labels);
    const panels = [
      findPanel(['coordDMS', 'coordGeohash']),
      findPanel(['weatherTemp', 'weatherDesc', 'weatherHumidity', 'weatherWind', 'weatherClouds']),
      findPanel(['elevMeters', 'elevFeet', 'elevCategory', 'sunRise', 'sunSet', 'sunAltitude', 'locTimezone']),
      findPanel(['landmarksList']),
    ];

    if (!buttons || panels.some((p) => !p)) {
      console.warn('[TraceGeo] Could not locate tabs — leaving all sections visible.');
      return;
    }

    const tabRow = buttons[0].parentElement;
    if (tabRow) {
      tabRow.style.overflowX = 'auto';
      tabRow.style.webkitOverflowScrolling = 'touch';
      tabRow.style.scrollbarWidth = 'none';
    }

    function activate(index) {
      panels.forEach((p, i) => { p.style.display = i === index ? '' : 'none'; });
      buttons.forEach((b, i) => {
        const active = i === index;
        b.setAttribute('aria-selected', String(active));
        b.style.opacity = active ? '1' : '0.6';
      });
    }

    buttons.forEach((btn, i) => {
      btn.style.cursor = 'pointer';
      btn.style.touchAction = 'manipulation';
      btn.setAttribute('role', 'tab');
      btn.addEventListener('click', () => activate(i));
    });

    activate(0);
    tabsWired = true;
  }

  function findTabButtons(labels) {
    const leaves = Array.from(document.querySelectorAll('body *')).filter(
      (el) => el.children.length === 0 && el.textContent.trim().length > 0
    );
    const byParent = new Map();
    leaves.forEach((el) => {
      const text = el.textContent.trim();
      if (!labels.includes(text)) return;
      const p = el.parentElement;
      if (!p) return;
      if (!byParent.has(p)) byParent.set(p, new Map());
      byParent.get(p).set(text, el);
    });
    for (const [, map] of byParent) {
      if (labels.every((l) => map.has(l))) {
        return labels.map((l) => map.get(l));
      }
    }
    return null;
  }

  function findPanel(ids) {
    const els = ids.map((id) => $(id)).filter(Boolean);
    if (!els.length) return null;
    return els.reduce((acc, el) => (acc ? commonAncestor(acc, el) : el), null);
  }

  function commonAncestor(a, b) {
    if (!a || !b) return null;
    const parents = new Set();
    for (let n = a; n; n = n.parentElement) parents.add(n);
    for (let n = b; n; n = n.parentElement) if (parents.has(n)) return n;
    return null;
  }

  // =============================================
  // INIT: DOM Ready
  // =============================================
  document.addEventListener('DOMContentLoaded', () => {
    const fileInput = $('fileInput');
    const browseBtn = $('browseBtn');
    const uploadZone = $('uploadCircle');
    const modeSelect = $('analysisModeSelect');
    const stagedPreview = $('stagedImagePreview');
    const stagedPanel = $('stagedImagePanel');
    const stagedAnalyzeBtn = $('stagedAnalyzeBtn');

    // Mode selection
    if (modeSelect) {
      modeSelect.addEventListener('change', () => {
        state.mode = modeSelect.value === 'osint_full' || modeSelect.value === 'forensic'
          ? 'detailed'
          : 'fast';
      });
    }

    // Stage file
    function stageFile(file) {
      if (!file) return;
      if (!file.type || !file.type.startsWith('image/')) {
        toast('❌ Please choose an image file (JPG, PNG, or WebP).', 'error');
        return;
      }
      state.file = file;
      if (stagedPreview) stagedPreview.src = URL.createObjectURL(file);
      if (stagedPanel) stagedPanel.style.display = 'block';
    }

    // Browse button
    if (browseBtn) {
      browseBtn.addEventListener('click', () => fileInput && fileInput.click());
    }

    // File input
    if (fileInput) {
      fileInput.addEventListener('change', (e) => {
        if (e.target.files[0]) stageFile(e.target.files[0]);
      });
    }

    // Upload zone
    if (uploadZone) {
      uploadZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        uploadZone.classList.add('dragover');
      });
      uploadZone.addEventListener('dragleave', () => {
        uploadZone.classList.remove('dragover');
      });
      uploadZone.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadZone.classList.remove('dragover');
        const file = e.dataTransfer.files[0];
        if (file) stageFile(file);
      });
      uploadZone.addEventListener('click', (e) => {
        if (e.target === uploadZone && fileInput) fileInput.click();
      });
    }

    // Analyze button
    if (stagedAnalyzeBtn) {
      stagedAnalyzeBtn.addEventListener('click', () => {
        if (state.file) analyze(state.file);
      });
    }

    console.log('✅ TraceGeo initialized with correction feedback loop!');
    console.log('📸 Upload an image to identify a landmark.');
    console.log('🚩 If the location is wrong, click "Report wrong location" to submit a correction.');
  });

  // Expose for debugging
  window.tg = {
    analyze,
    state,
    toast,
    copyCoords,
    openCorrectionPrompt,
  };
})();