<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>TraceGeo — Where was this taken?</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  :root{
    --bg-deep:#0b0a17;
    --bg-panel:#14121f;
    --bg-panel-2:#1a1729;
    --border:rgba(255,255,255,0.08);
    --border-soft:rgba(255,255,255,0.05);
    --indigo:#4f46e5;
    --violet:#7c3aed;
    --magenta:#c026d3;
    --cyan:#22d3ee;
    --text-primary:#ede9fe;
    --text-muted:#8f89ab;
    --text-faint:#5c5678;
    --success:#34d399;
    --warn:#fbbf24;
  }
  *{box-sizing:border-box;margin:0;padding:0;}
  html,body{background:var(--bg-deep);color:var(--text-primary);font-family:'Inter',sans-serif;min-height:100vh;overflow-x:hidden;}
  ::selection{background:var(--violet);color:#fff;}

  /* ---------- background texture: topographic contour lines ---------- */
  .contour-bg{
    position:fixed;inset:0;z-index:0;pointer-events:none;opacity:0.35;
    background-image:
      radial-gradient(circle at 20% 20%, rgba(79,70,229,0.18), transparent 40%),
      radial-gradient(circle at 80% 70%, rgba(192,38,212,0.15), transparent 45%),
      radial-gradient(circle at 50% 100%, rgba(124,58,237,0.15), transparent 50%);
  }
  .contour-lines{
    position:fixed;inset:0;z-index:0;pointer-events:none;opacity:0.25;
    background-image: repeating-radial-gradient(circle at 15% 30%, transparent 0, transparent 38px, rgba(255,255,255,0.025) 39px, transparent 40px),
                       repeating-radial-gradient(circle at 85% 75%, transparent 0, transparent 52px, rgba(255,255,255,0.02) 53px, transparent 54px);
  }

  a{color:inherit;text-decoration:none;}
  .mono{font-family:'JetBrains Mono',monospace;}
  .display{font-family:'Space Grotesk',sans-serif;}

  /* ---------- header ---------- */
  header{
    position:relative;z-index:10;
    display:flex;align-items:center;justify-content:space-between;
    padding:20px clamp(20px,5vw,56px);
    border-bottom:1px solid var(--border-soft);
    backdrop-filter:blur(12px);
    background:rgba(11,10,23,0.6);
  }
  .logo{display:flex;align-items:center;gap:10px;font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:19px;letter-spacing:-0.02em;}
  .logo-mark{
    width:30px;height:30px;border-radius:50%;
    background:conic-gradient(from 180deg, var(--indigo), var(--violet), var(--magenta), var(--indigo));
    display:flex;align-items:center;justify-content:center;position:relative;
    box-shadow:0 0 20px rgba(124,58,237,0.5);
  }
  .logo-mark::after{content:'';width:9px;height:9px;border-radius:50%;background:var(--bg-deep);}
  nav.actions{display:flex;align-items:center;gap:10px;}
  .btn{
    font-family:'Inter',sans-serif;font-size:13.5px;font-weight:600;
    padding:9px 18px;border-radius:8px;border:1px solid var(--border);
    background:transparent;color:var(--text-primary);cursor:pointer;
    transition:all .2s ease;
  }
  .btn:hover{border-color:rgba(255,255,255,0.2);background:rgba(255,255,255,0.04);}
  .btn-primary{
    background:linear-gradient(135deg, var(--indigo), var(--violet));
    border:none;color:#fff;
    box-shadow:0 4px 16px rgba(124,58,237,0.35);
  }
  .btn-primary:hover{box-shadow:0 6px 22px rgba(124,58,237,0.5);transform:translateY(-1px);}
  .btn-ghost{color:var(--text-muted);}
  .btn:disabled{opacity:0.5;cursor:not-allowed;transform:none !important;}

  /* ---------- hero / layout ---------- */
  main{position:relative;z-index:5;max-width:1280px;margin:0 auto;padding:clamp(32px,5vw,64px) clamp(20px,5vw,56px) 100px;}
  .hero-head{text-align:center;max-width:640px;margin:0 auto 48px;}
  .eyebrow{
    display:inline-flex;align-items:center;gap:8px;
    font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;
    color:var(--cyan);background:rgba(34,211,238,0.08);border:1px solid rgba(34,211,238,0.2);
    padding:6px 14px;border-radius:100px;margin-bottom:20px;
  }
  .eyebrow .dot{width:6px;height:6px;border-radius:50%;background:var(--cyan);box-shadow:0 0 8px var(--cyan);}
  h1{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:clamp(32px,5vw,52px);line-height:1.08;letter-spacing:-0.02em;
    background:linear-gradient(135deg,#fff 20%, var(--text-muted));-webkit-background-clip:text;background-clip:text;color:transparent;}
  .hero-sub{color:var(--text-muted);font-size:16px;line-height:1.6;margin-top:16px;}

  .workspace{display:grid;grid-template-columns:minmax(320px,420px) 1fr;gap:28px;align-items:start;}
  @media(max-width:900px){.workspace{grid-template-columns:1fr;}}

  /* ---------- upload panel ---------- */
  .panel{
    background:linear-gradient(180deg, var(--bg-panel), var(--bg-panel-2));
    border:1px solid var(--border);border-radius:20px;padding:28px;
    box-shadow:0 20px 60px rgba(0,0,0,0.35);
  }
  .upload-circle{
    position:relative;width:100%;aspect-ratio:1;border-radius:50%;
    border:2px dashed rgba(124,58,237,0.35);
    display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;
    cursor:pointer;transition:all .25s ease;overflow:hidden;
    background:radial-gradient(circle, rgba(124,58,237,0.06), transparent 70%);
  }
  .upload-circle::before, .upload-circle::after{
    content:'';position:absolute;inset:0;border-radius:50%;border:1px solid rgba(124,58,237,0.15);
    animation:ping-ring 3s ease-out infinite;
  }
  .upload-circle::after{animation-delay:1.5s;}
  @keyframes ping-ring{
    0%{transform:scale(0.78);opacity:0.8;}
    100%{transform:scale(1.08);opacity:0;}
  }
  .upload-circle:hover, .upload-circle.dragover{
    border-color:var(--violet);
    background:radial-gradient(circle, rgba(124,58,237,0.14), transparent 70%);
  }
  .upload-circle.dragover{border-style:solid;}
  .upload-icon{
    width:56px;height:56px;border-radius:50%;
    background:linear-gradient(135deg, var(--indigo), var(--magenta));
    display:flex;align-items:center;justify-content:center;
    box-shadow:0 8px 24px rgba(124,58,237,0.4);position:relative;z-index:1;
  }
  .upload-icon svg{width:26px;height:26px;stroke:#fff;}
  .upload-text{position:relative;z-index:1;text-align:center;padding:0 20px;}
  .upload-text strong{display:block;font-size:15px;font-weight:600;margin-bottom:4px;}
  .upload-text span{font-size:12.5px;color:var(--text-muted);}

  .mode-row{display:flex;align-items:center;justify-content:space-between;margin-top:22px;padding-top:20px;border-top:1px solid var(--border-soft);}
  .mode-row label{font-size:12.5px;color:var(--text-muted);font-weight:600;}
  select#analysisModeSelect{
    background:var(--bg-deep);color:var(--text-primary);border:1px solid var(--border);
    border-radius:8px;padding:7px 12px;font-size:13px;font-family:'Inter',sans-serif;cursor:pointer;
  }

  .staged-panel{display:none;margin-top:20px;padding-top:20px;border-top:1px solid var(--border-soft);}
  .staged-preview-wrap{position:relative;border-radius:14px;overflow:hidden;border:1px solid var(--border);margin-bottom:14px;}
  #stagedImagePreview{width:100%;display:block;max-height:220px;object-fit:cover;}
  .staged-analyze-btn{width:100%;padding:13px;font-size:14px;border-radius:10px;}

  .free-note{margin-top:18px;font-size:11.5px;color:var(--text-faint);text-align:center;line-height:1.5;}

  /* ---------- results panel ---------- */
  .results-panel{min-height:520px;position:relative;}
  #noResults{
    height:100%;min-height:480px;display:flex;flex-direction:column;align-items:center;justify-content:center;
    border:1px solid var(--border-soft);border-radius:20px;background:rgba(255,255,255,0.015);
    text-align:center;padding:40px;
  }
  #noResults .globe{
    width:84px;height:84px;border-radius:50%;
    background:conic-gradient(from 0deg, rgba(79,70,229,0.15), rgba(192,38,212,0.15), rgba(79,70,229,0.15));
    border:1px solid var(--border);margin-bottom:20px;
    display:flex;align-items:center;justify-content:center;
  }
  #noResults .globe svg{width:34px;height:34px;stroke:var(--text-muted);}
  #noResults h3{font-family:'Space Grotesk',sans-serif;font-size:18px;font-weight:600;margin-bottom:8px;}
  #noResults p{color:var(--text-muted);font-size:13.5px;max-width:280px;line-height:1.6;}

  #skeletonResults{display:none;}
  .skel-block{background:linear-gradient(90deg, var(--bg-panel) 0%, var(--bg-panel-2) 50%, var(--bg-panel) 100%);
    background-size:200% 100%;animation:shimmer 1.6s ease infinite;border-radius:14px;}
  @keyframes shimmer{0%{background-position:200% 0;}100%{background-position:-200% 0;}}
  .skel-img{width:100%;height:220px;border-radius:16px;margin-bottom:20px;}
  .skel-line{height:14px;margin-bottom:10px;}

  #resultsData{display:none;}
  .result-card{background:var(--bg-panel);border:1px solid var(--border);border-radius:20px;overflow:hidden;}
  .result-image-wrap{position:relative;height:240px;overflow:hidden;}
  #uploadedImage{width:100%;height:100%;object-fit:cover;display:block;}
  .result-image-fade{position:absolute;inset:0;background:linear-gradient(180deg, transparent 40%, var(--bg-panel) 100%);}
  .result-image-badge{
    position:absolute;bottom:16px;left:20px;right:20px;display:flex;align-items:flex-end;justify-content:space-between;gap:12px;
  }
  .result-title h2{font-family:'Space Grotesk',sans-serif;font-size:22px;font-weight:700;color:#fff;letter-spacing:-0.01em;}
  .result-title p{font-size:13px;color:var(--text-muted);margin-top:3px;}
  #country{color:var(--text-muted);}

  .gauge-wrap{display:flex;flex-direction:column;align-items:center;flex-shrink:0;}
  .gauge-svg{width:68px;height:68px;}
  .gauge-track{fill:none;stroke:rgba(255,255,255,0.1);stroke-width:8;}
  .gauge-fill{fill:none;stroke:url(#gaugeGradient);stroke-width:8;stroke-linecap:round;
    stroke-dasharray:326.73;stroke-dashoffset:326.73;transform:rotate(-90deg);transform-origin:60px 60px;
    transition:stroke-dashoffset 1.1s cubic-bezier(.16,1,.3,1);}
  .gauge-label{font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--text-muted);margin-top:4px;text-align:center;}
  #confScoreVal{font-family:'JetBrains Mono',monospace;font-weight:600;color:#fff;}

  .result-body{padding:24px;}
  .coord-row{display:flex;gap:10px;margin-bottom:22px;}
  .coord-chip{
    flex:1;background:var(--bg-panel-2);border:1px solid var(--border-soft);border-radius:12px;padding:12px 14px;
  }
  .coord-chip .lbl{font-size:10.5px;color:var(--text-faint);text-transform:uppercase;letter-spacing:0.06em;font-weight:600;margin-bottom:5px;}
  .coord-chip .val{font-family:'JetBrains Mono',monospace;font-size:13px;color:var(--cyan);word-break:break-all;}

  .ai-explanation{
    background:rgba(124,58,237,0.06);border:1px solid rgba(124,58,237,0.18);border-radius:14px;
    padding:16px 18px;margin-bottom:20px;font-size:13.5px;line-height:1.65;color:var(--text-primary);
  }

  #evidenceTagsContainer{display:none;flex-wrap:wrap;gap:8px;margin-bottom:22px;}
  .evidence-tag{
    font-size:11.5px;font-weight:600;padding:5px 12px;border-radius:100px;
    background:rgba(34,211,238,0.08);border:1px solid rgba(34,211,238,0.25);color:var(--cyan);
  }

  .tabs{display:flex;gap:4px;background:var(--bg-panel-2);border-radius:10px;padding:4px;margin-bottom:18px;}
  .tab-btn{flex:1;text-align:center;font-size:12.5px;font-weight:600;padding:9px 8px;border-radius:8px;cursor:pointer;
    color:var(--text-muted);transition:all .2s ease;border:none;background:none;font-family:'Inter',sans-serif;}
  .tab-btn.active{background:linear-gradient(135deg, var(--indigo), var(--violet));color:#fff;}
  .tab-panel{display:none;}
  .tab-panel.active{display:block;}

  .intel-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
  .intel-item{
    display:flex;flex-direction:column;gap:4px;background:var(--bg-panel-2);border:1px solid var(--border-soft);
    border-radius:12px;padding:12px 14px;
  }
  .intel-label{font-size:10.5px;color:var(--text-faint);text-transform:uppercase;letter-spacing:0.05em;font-weight:600;}
  .intel-value{font-family:'JetBrains Mono',monospace;font-size:13.5px;color:var(--text-primary);}

  #landmarksList .intel-item{grid-column:1 / -1;flex-direction:row;align-items:center;justify-content:space-between;}
  #landmarksList .intel-value{color:var(--cyan);font-size:12.5px;}

  /* ---------- toast ---------- */
  #toast{
    position:fixed;bottom:28px;left:50%;transform:translateX(-50%) translateY(120%);
    background:var(--bg-panel-2);border:1px solid var(--border);border-radius:12px;
    padding:14px 22px;box-shadow:0 12px 40px rgba(0,0,0,0.5);z-index:100;
    display:flex;align-items:center;gap:10px;transition:transform .35s cubic-bezier(.16,1,.3,1);
    max-width:90vw;
  }
  #toast.show{transform:translateX(-50%) translateY(0);}
  #toast .dot{width:8px;height:8px;border-radius:50%;background:var(--magenta);flex-shrink:0;box-shadow:0 0 8px var(--magenta);}
  #toastMsg{font-size:13.5px;color:var(--text-primary);}

  /* ---------- auth modal ---------- */
  .modal-overlay{
    position:fixed;inset:0;background:rgba(11,10,23,0.75);backdrop-filter:blur(6px);
    z-index:200;display:none;align-items:center;justify-content:center;padding:20px;
  }
  .modal-overlay.show{display:flex;}
  .modal{
    background:linear-gradient(180deg, var(--bg-panel), var(--bg-panel-2));
    border:1px solid var(--border);border-radius:20px;padding:32px;width:100%;max-width:380px;
    box-shadow:0 30px 80px rgba(0,0,0,0.5);
  }
  .modal h3{font-family:'Space Grotesk',sans-serif;font-size:20px;font-weight:700;margin-bottom:20px;}
  .field{margin-bottom:14px;}
  .field label{display:block;font-size:12px;color:var(--text-muted);font-weight:600;margin-bottom:6px;}
  .field input{
    width:100%;padding:10px 12px;background:var(--bg-deep);border:1px solid var(--border);
    border-radius:8px;color:var(--text-primary);font-size:13.5px;font-family:'Inter',sans-serif;
  }
  .field input:focus{outline:none;border-color:var(--violet);}
  .modal-switch{text-align:center;font-size:12.5px;color:var(--text-muted);margin-top:14px;}
  .modal-switch a{color:var(--cyan);font-weight:600;cursor:pointer;}
  .modal-close{position:absolute;top:18px;right:18px;background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:20px;line-height:1;}
  .modal{position:relative;}

  @media(prefers-reduced-motion: reduce){
    .upload-circle::before, .upload-circle::after{animation:none;}
    .skel-block{animation:none;}
  }
</style>
</head>
<body>

<div class="contour-bg"></div>
<div class="contour-lines"></div>

<header>
  <div class="logo">
    <span class="logo-mark"></span>
    TraceGeo
  </div>
  <nav class="actions" id="authNav">
    <button class="btn btn-ghost" id="loginNavBtn">Log in</button>
    <button class="btn btn-primary" id="registerNavBtn">Sign up</button>
  </nav>
</header>

<main>
  <div class="hero-head">
    <div class="eyebrow"><span class="dot"></span> Landmark identification</div>
    <h1>Where was this taken?</h1>
    <p class="hero-sub">Upload a travel photo and get the landmark, city, and country — with weather, elevation, sun data, and nearby public points of interest.</p>
  </div>

  <div class="workspace">
    <!-- Upload panel -->
    <div class="panel">
      <input type="file" id="fileInput" accept="image/*" style="display:none;">
      <div class="upload-circle" id="uploadCircle">
        <div class="upload-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V4M12 4l-4 4M12 4l4 4"/><path d="M4 16v3a2 2 0 002 2h12a2 2 0 002-2v-3"/></svg>
        </div>
        <div class="upload-text">
          <strong>Drop a photo here</strong>
          <span>or tap to browse — JPG, PNG, up to 16MB</span>
        </div>
      </div>
      <div style="margin-top:14px;">
        <button class="btn" id="browseBtn" style="width:100%;">Browse files</button>
      </div>

      <div class="mode-row">
        <label>Analysis mode</label>
        <select id="analysisModeSelect">
          <option value="fast">Fast</option>
          <option value="detailed">Detailed</option>
        </select>
      </div>

      <div class="staged-panel" id="stagedImagePanel">
        <div class="staged-preview-wrap">
          <img id="stagedImagePreview" src="" alt="Staged photo preview">
        </div>
        <button class="btn btn-primary staged-analyze-btn" id="stagedAnalyzeBtn">Identify landmark</button>
      </div>

      <p class="free-note">Free to use, no account needed — 10 analyses/day. Sign in for 50/day and saved history.</p>
    </div>

    <!-- Results panel -->
    <div class="results-panel">
      <div id="noResults">
        <div class="globe">
          <svg viewBox="0 0 24 24" fill="none" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2c2.5 2.8 4 6.3 4 10s-1.5 7.2-4 10c-2.5-2.8-4-6.3-4-10s1.5-7.2 4-10z"/></svg>
        </div>
        <h3>No photo analyzed yet</h3>
        <p>Upload a travel photo on the left and its location, weather, and surroundings will appear here.</p>
      </div>

      <div id="skeletonResults">
        <div class="result-card" style="padding:24px;">
          <div class="skel-block skel-img"></div>
          <div class="skel-block skel-line" style="width:60%;"></div>
          <div class="skel-block skel-line" style="width:40%;"></div>
          <div class="skel-block skel-line" style="width:85%;height:60px;margin-top:16px;"></div>
        </div>
      </div>

      <div id="resultsData">
        <div class="result-card">
          <div class="result-image-wrap">
            <img id="uploadedImage" src="" alt="Analyzed photo">
            <div class="result-image-fade"></div>
            <div class="result-image-badge">
              <div class="result-title">
                <h2 id="city">--</h2>
                <p id="country">--</p>
              </div>
              <div class="gauge-wrap">
                <svg class="gauge-svg" viewBox="0 0 120 120">
                  <defs>
                    <linearGradient id="gaugeGradient" x1="0%" y1="0%" x2="100%" y2="100%">
                      <stop offset="0%" stop-color="#4f46e5"/>
                      <stop offset="50%" stop-color="#7c3aed"/>
                      <stop offset="100%" stop-color="#c026d3"/>
                    </linearGradient>
                  </defs>
                  <circle class="gauge-track" cx="60" cy="60" r="52"/>
                  <circle class="gauge-fill" id="confGaugeFill" cx="60" cy="60" r="52"/>
                  <text x="60" y="66" text-anchor="middle" font-family="JetBrains Mono" font-size="20" font-weight="600" fill="#fff" id="confScoreVal">0</text>
                </svg>
                <div class="gauge-label"><span id="confGaugeLevel">--</span> confidence</div>
              </div>
            </div>
          </div>

          <div class="result-body">
            <div class="coord-row">
              <div class="coord-chip">
                <div class="lbl">Latitude</div>
                <div class="val" id="latitude">--</div>
              </div>
              <div class="coord-chip">
                <div class="lbl">Longitude</div>
                <div class="val" id="longitude">--</div>
              </div>
            </div>
            <p style="font-size:11px;color:var(--text-faint);margin:-14px 0 20px;line-height:1.5;">
              Pin location is approximate — sourced from free open map data, which can occasionally
              place a landmark a few hundred meters from its real position.
            </p>

            <p class="ai-explanation" id="aiExplanation"></p>

            <div id="evidenceTagsContainer"></div>

            <div class="tabs">
              <button class="tab-btn active" data-tab="location">Location</button>
              <button class="tab-btn" data-tab="weather">Weather</button>
              <button class="tab-btn" data-tab="environment">Environment</button>
              <button class="tab-btn" data-tab="nearby">Nearby</button>
            </div>

            <div class="tab-panel active" data-panel="location">
              <div class="intel-grid">
                <div class="intel-item"><span class="intel-label">DMS coordinates</span><span class="intel-value" id="coordDMS">--</span></div>
                <div class="intel-item"><span class="intel-label">Geohash</span><span class="intel-value" id="coordGeohash">--</span></div>
              </div>
            </div>

            <div class="tab-panel" data-panel="weather">
              <div class="intel-grid">
                <div class="intel-item"><span class="intel-label">Temperature</span><span class="intel-value" id="weatherTemp">--°C</span></div>
                <div class="intel-item"><span class="intel-label">Conditions</span><span class="intel-value" id="weatherDesc">--</span></div>
                <div class="intel-item"><span class="intel-label">Humidity</span><span class="intel-value" id="weatherHumidity">--%</span></div>
                <div class="intel-item"><span class="intel-label">Wind</span><span class="intel-value" id="weatherWind">-- km/h</span></div>
                <div class="intel-item"><span class="intel-label">Cloud cover</span><span class="intel-value" id="weatherClouds">--%</span></div>
              </div>
            </div>

            <div class="tab-panel" data-panel="environment">
              <div class="intel-grid">
                <div class="intel-item"><span class="intel-label">Elevation</span><span class="intel-value" id="elevMeters">-- m</span></div>
                <div class="intel-item"><span class="intel-label">In feet</span><span class="intel-value" id="elevFeet">-- ft</span></div>
                <div class="intel-item"><span class="intel-label">Terrain</span><span class="intel-value" id="elevCategory">--</span></div>
                <div class="intel-item"><span class="intel-label">Timezone</span><span class="intel-value" id="locTimezone">--</span></div>
                <div class="intel-item"><span class="intel-label">Sunrise</span><span class="intel-value" id="sunRise">--</span></div>
                <div class="intel-item"><span class="intel-label">Sunset</span><span class="intel-value" id="sunSet">--</span></div>
                <div class="intel-item" style="grid-column:1/-1;"><span class="intel-label">Sun altitude</span><span class="intel-value" id="sunAltitude">--°</span></div>
              </div>
            </div>

            <div class="tab-panel" data-panel="nearby">
              <div id="landmarksList" class="intel-grid"></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</main>

<!-- Toast -->
<div id="toast"><span class="dot"></span><span id="toastMsg"></span></div>

<!-- Auth modal -->
<div class="modal-overlay" id="authModalOverlay">
  <div class="modal">
    <button class="modal-close" id="authModalClose">&times;</button>
    <h3 id="authModalTitle">Log in</h3>
    <form id="authForm">
      <div class="field" id="nameField" style="display:none;">
        <label>Name</label>
        <input type="text" id="authName" autocomplete="name">
      </div>
      <div class="field">
        <label>Email</label>
        <input type="email" id="authEmail" autocomplete="email" required>
      </div>
      <div class="field">
        <label>Password</label>
        <input type="password" id="authPassword" autocomplete="current-password" required>
      </div>
      <div class="field" id="confirmField" style="display:none;">
        <label>Confirm password</label>
        <input type="password" id="authPasswordConfirm" autocomplete="new-password">
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%;margin-top:6px;" id="authSubmitBtn">Log in</button>
    </form>
    <div class="modal-switch">
      <span id="authSwitchText">Don't have an account? <a id="authSwitchLink">Sign up</a></span>
    </div>
  </div>
</div>

<script src="/js/app.js"></script>
<script>
  // --- Tabs ---
  document.querySelectorAll('.tab-btn').forEach((btn) => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.tab-btn').forEach((b) => b.classList.remove('active'));
      document.querySelectorAll('.tab-panel').forEach((p) => p.classList.remove('active'));
      btn.classList.add('active');
      document.querySelector(`.tab-panel[data-panel="${btn.dataset.tab}"]`).classList.add('active');
    });
  });

  // --- Auth modal (register / login against /api/register and /api/login) ---
  (function () {
    const overlay = document.getElementById('authModalOverlay');
    const title = document.getElementById('authModalTitle');
    const form = document.getElementById('authForm');
    const nameField = document.getElementById('nameField');
    const confirmField = document.getElementById('confirmField');
    const submitBtn = document.getElementById('authSubmitBtn');
    const switchText = document.getElementById('authSwitchText');
    const switchLink = document.getElementById('authSwitchLink');
    let mode = 'login';

    function openModal(m) {
      mode = m;
      if (mode === 'login') {
        title.textContent = 'Log in';
        nameField.style.display = 'none';
        confirmField.style.display = 'none';
        submitBtn.textContent = 'Log in';
        switchText.innerHTML = "Don't have an account? <a id=\"authSwitchLink\">Sign up</a>";
      } else {
        title.textContent = 'Create account';
        nameField.style.display = 'block';
        confirmField.style.display = 'block';
        submitBtn.textContent = 'Sign up';
        switchText.innerHTML = 'Already have an account? <a id="authSwitchLink">Log in</a>';
      }
      document.getElementById('authSwitchLink').addEventListener('click', () => openModal(mode === 'login' ? 'register' : 'login'));
      overlay.classList.add('show');
    }

    document.getElementById('loginNavBtn').addEventListener('click', () => openModal('login'));
    document.getElementById('registerNavBtn').addEventListener('click', () => openModal('register'));
    document.getElementById('authModalClose').addEventListener('click', () => overlay.classList.remove('show'));
    overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.classList.remove('show'); });

    function toast(msg) {
      const el = document.getElementById('toast');
      const msgEl = document.getElementById('toastMsg');
      msgEl.textContent = msg;
      el.classList.add('show');
      setTimeout(() => el.classList.remove('show'), 3500);
    }

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const email = document.getElementById('authEmail').value;
      const password = document.getElementById('authPassword').value;

      try {
        let res, data;
        if (mode === 'login') {
          res = await fetch('/api/login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ email, password }),
          });
        } else {
          const name = document.getElementById('authName').value;
          const password_confirmation = document.getElementById('authPasswordConfirm').value;
          res = await fetch('/api/register', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ name, email, password, password_confirmation, terms: true }),
          });
        }
        data = await res.json();
        if (!res.ok) {
          const firstError = data.errors ? Object.values(data.errors)[0][0] : data.message;
          toast(firstError || 'Something went wrong.');
          return;
        }
        localStorage.setItem('tg_token', data.token);
        toast(mode === 'login' ? `Welcome back, ${data.user.name}` : `Account created — welcome, ${data.user.name}`);
        overlay.classList.remove('show');
        updateAuthNav();
      } catch (err) {
        toast('Network error — please try again.');
      }
    });

    function updateAuthNav() {
      const nav = document.getElementById('authNav');
      const token = localStorage.getItem('tg_token');
      if (token) {
        nav.innerHTML = '<button class="btn btn-ghost" id="logoutBtn">Log out</button>';
        document.getElementById('logoutBtn').addEventListener('click', () => {
          localStorage.removeItem('tg_token');
          location.reload();
        });
      }
    }
    updateAuthNav();
  })();
</script>

</body>
</html>