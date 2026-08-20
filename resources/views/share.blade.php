@php
  $landmark = \App\Models\Landmark::find($landmark_id);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{ $landmark ? $landmark->landmark_name . ' — TraceGeo' : 'Shared landmark — TraceGeo' }}</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<style>
  :root{
    --bg-deep:#0b0a17; --bg-panel:#14121f; --bg-panel-2:#1a1729;
    --border:rgba(255,255,255,0.08); --indigo:#4f46e5; --violet:#7c3aed; --magenta:#c026d3;
    --cyan:#22d3ee; --text-primary:#ede9fe; --text-muted:#8f89ab; --text-faint:#5c5678;
  }
  *{box-sizing:border-box;margin:0;padding:0;}
  body{
    background:var(--bg-deep);color:var(--text-primary);font-family:'Inter',sans-serif;
    min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;
    background-image:radial-gradient(circle at 20% 20%, rgba(79,70,229,0.15), transparent 40%),
                      radial-gradient(circle at 80% 80%, rgba(192,38,212,0.12), transparent 45%);
  }
  .card{
    max-width:480px;width:100%;background:linear-gradient(180deg, var(--bg-panel), var(--bg-panel-2));
    border:1px solid var(--border);border-radius:20px;overflow:hidden;box-shadow:0 30px 80px rgba(0,0,0,0.5);
  }
  .card img{width:100%;height:240px;object-fit:cover;display:block;}
  .card-body{padding:26px;}
  .logo{display:inline-flex;align-items:center;gap:8px;font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:14px;color:var(--text-muted);margin-bottom:16px;}
  .logo-mark{width:16px;height:16px;border-radius:50%;background:conic-gradient(from 180deg, var(--indigo), var(--violet), var(--magenta), var(--indigo));}
  h1{font-family:'Space Grotesk',sans-serif;font-size:24px;font-weight:700;letter-spacing:-0.01em;margin-bottom:6px;}
  .location{color:var(--text-muted);font-size:14px;margin-bottom:18px;}
  .desc{font-size:13.5px;line-height:1.65;color:var(--text-primary);margin-bottom:20px;}
  .coords{display:flex;gap:10px;margin-bottom:20px;}
  .chip{flex:1;background:var(--bg-panel-2);border:1px solid rgba(255,255,255,0.05);border-radius:10px;padding:10px 12px;}
  .chip .lbl{font-size:10px;color:var(--text-faint);text-transform:uppercase;letter-spacing:0.05em;font-weight:600;margin-bottom:4px;}
  .chip .val{font-family:'JetBrains Mono',monospace;font-size:12.5px;color:var(--cyan);}
  .conf{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:var(--text-muted);
    background:rgba(124,58,237,0.08);border:1px solid rgba(124,58,237,0.2);padding:5px 12px;border-radius:100px;}
  .cta{display:block;text-align:center;margin-top:22px;padding:12px;border-radius:10px;font-size:13.5px;font-weight:600;
    background:linear-gradient(135deg, var(--indigo), var(--violet));color:#fff;text-decoration:none;}
  .expired{padding:60px 32px;text-align:center;}
  .expired h1{font-size:20px;}
  .expired p{color:var(--text-muted);font-size:13.5px;margin-top:8px;}
</style>
</head>
<body>
  <div class="card">
    @if($landmark)
      @if($landmark->image_path)
        <img src="{{ asset('storage/' . $landmark->image_path) }}" alt="{{ $landmark->landmark_name }}">
      @endif
      <div class="card-body">
        <div class="logo"><span class="logo-mark"></span> TraceGeo</div>
        <h1>{{ $landmark->landmark_name }}</h1>
        <p class="location">{{ $landmark->full_location ?: 'Location unavailable' }}</p>

        @if($landmark->description)
          <p class="desc">{{ $landmark->description }}</p>
        @endif

        @if($landmark->latitude && $landmark->longitude)
          <div class="coords">
            <div class="chip"><div class="lbl">Latitude</div><div class="val">{{ $landmark->latitude }}</div></div>
            <div class="chip"><div class="lbl">Longitude</div><div class="val">{{ $landmark->longitude }}</div></div>
          </div>
        @endif

        <span class="conf">{{ $landmark->confidence }}% confidence</span>

        <a href="/" class="cta">Identify your own photo on TraceGeo →</a>
      </div>
    @else
      <div class="expired">
        <h1>Share link expired or invalid</h1>
        <p>This landmark may no longer be available, or the share link has expired.</p>
        <a href="/" class="cta" style="margin-top:20px;">Go to TraceGeo →</a>
      </div>
    @endif
  </div>
</body>
</html>