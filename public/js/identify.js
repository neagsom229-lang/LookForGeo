(function () {
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const REQUEST_TIMEOUT_MS = 160000;

  let map = null;
  let currentCoords = null;
  let isProcessing = false;
  let lastFile = null;
  let lastMode = 'fast';

  const el = (id) => document.getElementById(id);
  const uploadArea = el('uploadArea');
  const imageInput = el('imageInput');
  const previewContainer = el('previewContainer');
  const previewImage = el('previewImage');
  const fileInfo = el('fileInfo');
  const removeBtn = el('removeBtn');
  const identifyBtn = el('identifyBtn');
  const identifyBtnLabel = el('identifyBtnLabel');
  const modeSelect = el('modeSelect');
  const loader = el('loader');
  const results = el('results');
  const errorMessage = el('errorMessage');
  const errorTitle = el('errorTitle');
  const errorText = el('errorText');
  const infoMessage = el('infoMessage');
  const infoTitle = el('infoTitle');
  const infoText = el('infoText');
  const refineBtn = el('refineBtn');

  el('footerYear').textContent = new Date().getFullYear();

  // ---------------- theme toggle ----------------
  (function () {
    const root = document.documentElement;
    const toggle = el('themeToggle');
    const icon = el('themeIcon');
    const KEY = 'tg_theme_preference';
    const icons = { auto: '◐', light: '☀', dark: '☾' };

    function apply(pref) {
      if (pref === 'light' || pref === 'dark') root.setAttribute('data-theme', pref);
      else root.removeAttribute('data-theme');
      if (icon) icon.textContent = icons[pref];
    }

    let current = localStorage.getItem(KEY) || 'auto';
    apply(current);
    if (toggle) {
      toggle.addEventListener('click', () => {
        current = current === 'auto' ? 'light' : current === 'light' ? 'dark' : 'auto';
        localStorage.setItem(KEY, current);
        apply(current);
      });
    }
  })();

  // ---------------- upload handling ----------------
  function handleFile(file) {
    if (!file.type || !file.type.startsWith('image/')) {
      showError('Please choose an image file (JPG, PNG, or WebP).', "That's not an image");
      return;
    }
    if (file.size > 16 * 1024 * 1024) {
      showError('Please choose a file under 16MB.', 'File too large');
      return;
    }
    lastFile = file;
    const reader = new FileReader();
    reader.onload = (e) => {
      previewImage.src = e.target.result;
      previewContainer.style.display = 'block';
      uploadArea.style.display = 'none';
      identifyBtn.disabled = false;
      hideMessages();
      results.style.display = 'none';
      if (fileInfo) fileInfo.textContent = `${file.name} · ${(file.size / 1024).toFixed(0)} KB`;
    };
    reader.readAsDataURL(file);
  }

  uploadArea.addEventListener('click', () => imageInput.click());
  uploadArea.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); imageInput.click(); }
  });
  uploadArea.addEventListener('dragover', (e) => { e.preventDefault(); uploadArea.classList.add('dragover'); });
  uploadArea.addEventListener('dragleave', () => uploadArea.classList.remove('dragover'));
  uploadArea.addEventListener('drop', (e) => {
    e.preventDefault();
    uploadArea.classList.remove('dragover');
    if (e.dataTransfer.files.length) handleFile(e.dataTransfer.files[0]);
  });
  imageInput.addEventListener('change', (e) => { if (e.target.files.length) handleFile(e.target.files[0]); });

  removeBtn.addEventListener('click', resetUpload);

  function resetUpload() {
    previewContainer.style.display = 'none';
    uploadArea.style.display = 'block';
    identifyBtn.disabled = true;
    imageInput.value = '';
    lastFile = null;
    results.style.display = 'none';
    hideMessages();
    if (map) { map.remove(); map = null; 
      const mapEl = el('map');
      if (mapEl) mapEl.innerHTML = '<div class="tg-map-placeholder">Map will appear here</div>';
    }
  }

  // ---------------- analyze ----------------
  identifyBtn.addEventListener('click', () => { if (lastFile) analyze(lastFile, modeSelect.value); });
  refineBtn.addEventListener('click', () => {
    if (!lastFile) return;
    refineBtn.disabled = true;
    refineBtn.textContent = 'Re-analyzing…';
    analyze(lastFile, 'detailed');
  });

  async function analyze(file, mode) {
    if (isProcessing) return;
    isProcessing = true;
    lastMode = mode;

    identifyBtn.disabled = true;
    if (identifyBtnLabel) identifyBtnLabel.textContent = 'Analyzing…';
    loader.style.display = 'block';
    results.style.display = 'none';
    hideMessages();

    const form = new FormData();
    form.append('image', file);
    form.append('mode', mode);

    const controller = new AbortController();
    const hardTimeout = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);

    try {
      const res = await fetch('/api/identify', {
        method: 'POST',
        headers: {
          'X-CSRF-TOKEN': csrfToken,
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: form,
        signal: controller.signal,
        credentials: 'same-origin',
      });

      console.log('Response status:', res.status);

      if (!res.ok) {
        if (res.status === 419) {
          showError('Session expired. Please refresh the page.', 'Session Error');
          return;
        }
        if (res.status === 429) {
          showError('Daily quota exceeded (20 requests/day). Please try again tomorrow.', 'Quota Exceeded');
          return;
        }
        if (res.status === 500) {
          const errorData = await res.json().catch(() => ({}));
          showError(errorData.message || 'Server error. Please check the logs.', 'Server Error');
          return;
        }
        const errorData = await res.json().catch(() => ({}));
        showError(errorData.message || `HTTP ${res.status}: ${res.statusText}`, 'Error');
        return;
      }

      const data = await res.json();
      console.log('Response data:', data);

      if (!data.success) {
        showError(data.message || 'Could not identify a location in that photo.', 'No result');
        return;
      }

      renderResults(data);
    } catch (err) {
      console.error('Fetch error:', err);
      if (err.name === 'AbortError') {
        showError('That took too long. Please try again.', 'Timed Out');
      } else if (err.message && err.message.includes('Failed to fetch')) {
        showError('Cannot connect to the server. Please make sure `php artisan serve` is running.', 'Connection Error');
      } else {
        showError('Network error: ' + (err.message || 'Unknown error'), 'Error');
      }
    } finally {
      clearTimeout(hardTimeout);
      isProcessing = false;
      identifyBtn.disabled = false;
      if (identifyBtnLabel) identifyBtnLabel.textContent = 'Identify location';
      loader.style.display = 'none';
    }
  }

  // ---------------- render ---------------- 
  function renderResults(data) {
    // ✅ FIXED: Use data.data or fallback to data
    const d = data.data || data;

    // Extract data with fallbacks
    const name = d.landmark_name || d.name || 'Unidentified location';
    const confidence = d.confidence || 0;
    const description = d.description || 'No description available.';
    const country = d.country || 'Unknown';
    const type = d.type || 'Unknown';
    const coords = d.coordinates || null;
    const source = d.coordinate_source || d.source || 'ai_estimate';

    // Update DOM elements
    const nameEl = el('landmarkName');
    if (nameEl) nameEl.textContent = name;

    const locationEl = el('landmarkLocation');
    if (locationEl) locationEl.textContent = country;

    const descEl = el('description');
    if (descEl) descEl.textContent = description;

    const typeEl = el('type');
    if (typeEl) typeEl.textContent = type;

    const confEl = el('confidence');
    if (confEl) confEl.textContent = confidence + '%';

    // Confidence badge
    const badge = el('confidenceBadge');
    if (badge) {
      badge.textContent = confidence + '% Confidence';
      badge.className = 'confidence-badge';
      if (confidence >= 80) badge.classList.add('high');
      else if (confidence >= 50) badge.classList.add('medium');
      else badge.classList.add('low');
    }

    // Coordinates
    const coordEl = el('coordinates');
    if (coords && coords.lat && coords.lng) {
      currentCoords = { lat: coords.lat, lng: coords.lng };
      if (coordEl) coordEl.textContent = `${coords.lat.toFixed(6)}, ${coords.lng.toFixed(6)}`;
      
      // Initialize map
      initOSMMap(coords.lat, coords.lng, name);
      
      // Update action buttons
      setLink('googleMapsBtn', `https://www.google.com/maps/search/?api=1&query=${coords.lat},${coords.lng}`);
      setLink('directionsBtn', `https://www.google.com/maps/dir//${coords.lat},${coords.lng}`);
      setLink('streetViewBtn', `https://www.google.com/maps/@?api=1&map_action=pano&viewpoint=${coords.lat},${coords.lng}`);
      setLink('osmBtn', `https://www.openstreetmap.org/#map=15/${coords.lat}/${coords.lng}`);
      
      // Show action buttons
      const actionsBar = el('actionButtons');
      if (actionsBar) actionsBar.style.display = 'flex';
    } else {
      if (coordEl) coordEl.textContent = 'Not available';
      const actionsBar = el('actionButtons');
      if (actionsBar) actionsBar.style.display = 'none';
    }

    // Nearby attractions
    const nearby = d.nearby_attractions || d.nearby_places || [];
    renderNearby(nearby);

    // Source badge
    const sourceEl = el('sourceEyebrow');
    if (sourceEl) {
      sourceEl.textContent = source === 'gps' ? '📡 GPS EXIF' : '🧠 AI ESTIMATE';
    }

    // Show results
    results.style.display = 'block';
    loader.style.display = 'none';
    results.scrollIntoView({ behavior: 'smooth', block: 'start' });

    // Refine button
    if (refineBtn) {
      refineBtn.hidden = !(lastMode === 'fast' && confidence < 90);
      refineBtn.disabled = false;
      refineBtn.textContent = 'Refine with detailed analysis';
    }
  }

  // ---------------- helper: set link ----------------
  function setLink(id, url) {
    const el = document.getElementById(id);
    if (el) {
      el.href = url;
      el.style.display = 'inline-flex';
    }
  }

  // ---------------- render nearby ----------------
  function renderNearby(places) {
    const wrap = el('nearbyList');
    if (!wrap) return;
    wrap.innerHTML = '';
    if (!Array.isArray(places) || !places.length) {
      wrap.innerHTML = '<p class="tg-nearby-empty">No nearby points of interest found.</p>';
      return;
    }
    places.slice(0, 10).forEach((p) => {
      const row = document.createElement('div');
      row.className = 'tg-nearby-item';
      const dist = p.distance ?? p.distance_m;
      row.innerHTML = `
        <div>
          <div class="name">${escapeHtml(p.name || 'Unnamed')}</div>
          <div class="type">${escapeHtml(p.type || 'Point of interest')}</div>
        </div>
        ${dist != null ? `<span class="distance">${Math.round(dist)}m</span>` : ''}
      `;
      wrap.appendChild(row);
    });
  }

  // ---------------- OpenStreetMap ----------------
  function initOSMMap(lat, lng, name) {
    const container = el('map');
    if (!container) return;
    container.innerHTML = '';

    map = L.map('map', { center: [lat, lng], zoom: 15, zoomControl: true });
    
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
      maxZoom: 19,
    }).addTo(map);

    L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
      attribution: '&copy; <a href="https://www.esri.com">ESRI</a>',
      maxZoom: 19,
    }).addTo(map);

    const icon = L.divIcon({
      html: '<div style="width:16px;height:16px;border-radius:50%;background:#5cd6cc;border:3px solid #0a0e17;box-shadow:0 0 0 2px #5cd6cc;"></div>',
      className: '',
      iconSize: [16, 16],
      iconAnchor: [8, 8],
    });
    
    L.marker([lat, lng], { icon })
      .addTo(map)
      .bindPopup(`<strong>${escapeHtml(name || 'Location')}</strong>`)
      .openPopup();
      
    setTimeout(() => map.invalidateSize(), 200);
  }

  // ---------------- copy coords ----------------
  const copyBtn = el('copyCoordsBtn');
  if (copyBtn) {
    copyBtn.addEventListener('click', async () => {
      if (!currentCoords) return;
      const text = `${currentCoords.lat}, ${currentCoords.lng}`;
      try {
        await navigator.clipboard.writeText(text);
        showInfo('Coordinates copied to clipboard.', 'Copied');
        setTimeout(hideMessages, 2500);
      } catch {
        showError('Your browser blocked clipboard access.', "Couldn't copy");
      }
    });
  }

  // ---------------- share ----------------
  const shareBtn = el('shareBtn');
  if (shareBtn) {
    shareBtn.addEventListener('click', () => {
      const nameEl = el('landmarkName');
      const descEl = el('description');
      const name = nameEl ? nameEl.textContent : '';
      const desc = descEl ? descEl.textContent : '';
      const coords = currentCoords ? `${currentCoords.lat}, ${currentCoords.lng}` : '';
      const text = `${name}\n${desc}\n\n📍 ${coords}`;
      
      if (navigator.share) {
        navigator.share({ title: name || 'Landmark', text }).catch(() => {});
      } else {
        navigator.clipboard.writeText(text).then(() => {
          showInfo('Details copied to clipboard.', 'Copied');
          setTimeout(hideMessages, 2500);
        }).catch(() => {
          showError('Could not copy to clipboard.', 'Error');
        });
      }
    });
  }

  // ---------------- tabs ----------------
  function wireTabs() {
    const tabs = document.querySelectorAll('.tg-tab');
    const panels = document.querySelectorAll('.tg-panel');
    tabs.forEach((tab) => {
      tab.onclick = () => {
        tabs.forEach((t) => t.setAttribute('aria-selected', String(t === tab)));
        panels.forEach((p) => { p.hidden = p.dataset.panel !== tab.dataset.tab; });
        if (tab.dataset.tab === 'location' && map) setTimeout(() => map.invalidateSize(), 50);
      };
    });
  }

  // ---------------- helpers ----------------
  function showError(msg, title) {
    if (errorTitle) errorTitle.textContent = title || 'Error';
    if (errorText) errorText.textContent = msg;
    if (errorMessage) errorMessage.style.display = 'flex';
    if (infoMessage) infoMessage.style.display = 'none';
  }
  
  function showInfo(msg, title) {
    if (infoTitle) infoTitle.textContent = title || 'Note';
    if (infoText) infoText.textContent = msg;
    if (infoMessage) infoMessage.style.display = 'flex';
    if (errorMessage) errorMessage.style.display = 'none';
  }
  
  function hideMessages() {
    if (errorMessage) errorMessage.style.display = 'none';
    if (infoMessage) infoMessage.style.display = 'none';
  }
  
  function escapeHtml(str) {
    return String(str).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && previewContainer.style.display !== 'none') resetUpload();
  });
})();