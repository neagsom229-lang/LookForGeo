<div align="center">

# 🌍 TraceGeo — AI-Powered Image Geolocation

**Upload a photo. Get the landmark, city, country, and coordinates — powered by Google Gemini.**

[![Laravel Version](https://img.shields.io/badge/Laravel-12.x-FF2D20?style=for-the-badge&logo=laravel&logoColor=white)](https://laravel.com)
[![PHP Version](https://img.shields.io/badge/PHP-8.2+-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://php.net)
[![Gemini API](https://img.shields.io/badge/Google%20Gemini-Flash-8E75B2?style=for-the-badge&logo=google&logoColor=white)](https://aistudio.google.com/)
[![License](https://img.shields.io/badge/License-MIT-green.svg?style=for-the-badge)](LICENSE)

</div>

---

## 📌 Overview

**TraceGeo** is an open-source OSINT (Open Source Intelligence) investigation tool designed to identify the exact geographic origin of photos. By analyzing visual features, matching patterns against known landmarks, and cross-referencing contextual indicators, TraceGeo returns precise coordinates, confidence estimates, interactive maps, and shareable dossier reports.

---

## ✨ Key Features

- **🧠 Multi-Tier AI Geolocation** — Leverages `gemini-3.5-flash-lite` (with `gemini-3.1-flash-lite` fallback) to parse subtle geographic, architectural, and ecological indicators.
- **📂 Flexible Input Methods** — Upload local image files directly or paste remote image URLs (Wikimedia, Wikipedia, Imgur, etc.).
- **⚡ Real-Time Pipeline Progress** — Live visual breakdown as the pipeline traverses stages: `Features` → `Reasoning` → `Locate`.
- **🗺️ Interactive Mapping Engine** — Integrated Leaflet.js with Esri World Imagery and OpenStreetMap overlays (zero paid API keys required).
- **🔎 Transparent OSINT Reasoning** — Inspect the specific visual context and chain of thought used to infer coordinates.
- **🌐 3D Globe & Street View Links** — Direct external links to inspect identified coordinates via ground-level views and 3D globe models.
- **📑 OSINT Dossier Export** — Generate structured, shareable reports detailing confidence metrics and evidentiary breakdowns.
- **🔒 Session-Based Authentication** — Secure account handling powered by Laravel Sanctum.

---

## 🏗️ Tech Stack

| Layer | Technology |
|---|---|
| **Framework** | [Laravel 12](https://laravel.com) |
| **Language** | PHP 8.2+ |
| **Frontend** | Blade + static assets (`public/css`, `public/js`), Font Awesome 6 |
| **Database** | PostgreSQL (Production) / SQLite (Local development) |
| **Cache & Queue** | Database driver (`CACHE_STORE=database`, `QUEUE_CONNECTION=database`) |
| **Mapping Engine** | Leaflet.js + Esri World Imagery + OpenStreetMap |
| **AI Inference** | Google Gemini API (`gemini-3.5-flash-lite` / `gemini-3.1-flash-lite`) |
| **Media Hosting** | Cloudinary (optional) |
| **Authentication** | Laravel Sanctum |
| **Offline / PWA** | Custom Service Worker (`public/sw.js`) |

---

## 🚀 Getting Started

### Prerequisites

Ensure the following tools are installed on your host system:

- **PHP 8.2+** with extensions: `pdo_sqlite`, `pdo_pgsql`, `curl`, `mbstring`, `openssl`, `gd`, `exif`
- **Composer 2.x**

### Installation (fast path)

Two commands bootstrap a fresh clone. `composer install` fetches PHP dependencies;
`composer setup-local` then copies `.env.example` → `.env`, generates the app key,
creates `database/database.sqlite`, links storage, runs migrations and seeds a demo
user (`demo@example.com` / `password`):

```bash
composer install        # PHP dependencies (required before any artisan command)
composer setup-local    # .env, APP_KEY, sqlite, migrate, demo user
# add your GEMINI_API_KEY to .env when you have one
composer dev
# open http://127.0.0.1:8000 — register or use the demo account
```

To prove the whole flow works end-to-end offline:

```bash
composer smoke     # register → login → upload → completed analysis (fake Gemini)
```

### Installation (manual)

**1. Clone & install dependencies**

```bash
# Clone the repository
git clone https://github.com/neagsom229-lang/LookForGeo.git
cd LookForGeo

# Install PHP dependencies
composer install
```

**2. Environment configuration**

```bash
# Copy example environment configuration
cp .env.example .env

# Generate application key
php artisan key:generate
```

Open `.env` in your editor and configure your credentials:

```env
APP_NAME=TraceGeo
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

# Database (SQLite recommended for local setup)
DB_CONNECTION=sqlite
# For PostgreSQL:
# DB_CONNECTION=pgsql
# DATABASE_URL=postgresql://user:password@host:5432/tracegeo

# Session & Queue Configuration (MUST NOT be "array")
SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=database

# Google Gemini API
GEMINI_API_KEY=your_google_ai_studio_key_here
GEMINI_MODEL=gemini-3.5-flash-lite
GEMINI_FALLBACK_MODEL=gemini-3.1-flash-lite
GEMINI_TIMEOUT=50
GEMINI_TOTAL_BUDGET=110

# Cloudinary (Optional - local fallback supported)
CLOUDINARY_CLOUD_NAME=
CLOUDINARY_API_KEY=
CLOUDINARY_API_SECRET=
```

> 🔑 Get a free API key at [Google AI Studio](https://aistudio.google.com/).

**3. Database setup & seeding**

```bash
# For SQLite: create the database file if it does not exist
touch database/database.sqlite

# Run all migrations
php artisan migrate

# Seed baseline landmarks (optional)
php artisan db:seed --class=LandmarkSeeder
```

### Running the Application

TraceGeo dispatches analysis workflows asynchronously. You must run both processes simultaneously.

**Terminal 1 — Application server**

```bash
php artisan serve
```

**Terminal 2 — Background queue worker**

```bash
php artisan queue:work --tries=1 --timeout=180
```

> ⚠️ **Queue worker is required.** Analysis jobs execute via the database queue. If the queue worker is not active, requests will remain stuck in `pending` status and the UI will poll indefinitely.

Access the dashboard in your browser at **http://localhost:8000**.

---

## 🔒 Security

- **CSP Middleware (`ForceCsp`)** — Content Security Policy headers enforced on all application responses.
- **SSRF Protection** — Outbound image fetchers validate target destinations; private IP blocks (`10.0.0.0/8`, `192.168.0.0/16`, `127.0.0.0/8`) and localhost endpoints are strictly blocked, with a re-check after redirects.
- **Protected Gemini Endpoints** — Implemented interactive endpoints (`/api/gemini-chat`, `/api/identify`, `/api/search`) require active authentication (web session or Sanctum token). The method-less `/api/identify-gps`, `/api/extract-exif` and `/api/nearby` routes were removed in the pre-deploy gate (they returned 500 for authenticated callers); their paths now return 404.
- **Ownership scoping** — Read/poll/delete/retry of an analysis are scoped to their owner.
- **Strict Rate Limiting** — Configured at `throttle:10,1` on `/api/gemini-chat` and `throttle:60,1` on `/api/analyze`.
- **Secret hygiene** — `gitleaks` scans every commit locally (`core.hooksPath .githooks`) and runs in CI on every push; see `docs/SECURITY_INCIDENT.md`.
- **Persistent Sessions** — Session driver strictly configured to `database` or `file` (the `array` driver is barred in production).
- **Legitimate User-Agent** — Outbound asset fetchers provide a fully-formed User-Agent header to comply with Wikimedia and CDN upstream policies.

🛡️ **Reporting security issues:** please use the process in [SECURITY.md](SECURITY.md); do not open public issues for vulnerabilities.

---

## 🔌 API Endpoints

Session routes (web middleware, browser clients) live under `/api/*` in `routes/web.php`.
Stateless routes (Bearer tokens) live in `routes/api.php`.
Unauthenticated clients may only reach the health check and the auth endpoints.

| Method | Endpoint | Auth | Purpose |
|---|---|---|---|
| GET | `/api/health` | Public | Health check |
| POST | `/api/login` | Public | Authenticate & issue Sanctum token |
| POST | `/api/register` | Public | Register a new user account |
| GET | `/api/verify/{token}` | Public | Verify email token |
| POST | `/api/logout` | Sanctum | Revoke current token |
| GET | `/api/history` | Sanctum | List completed analyses |
| POST | `/api/analyze` | Sanctum + throttle | Upload image, enqueue analysis (60/min) |
| POST | `/api/identify` | Web session or Sanctum | Identify a landmark (cost-bearing) |
| GET | `/api/analyze/{id}/status` | Web session | Poll analysis status (owner-scoped) |
| GET | `/api/results/{id}` | Web session | Fetch result JSON (owner-scoped) |
| POST | `/api/gemini-chat` | Sanctum + throttle | Direct Gemini chat (10/min) |

---

## 🐛 Troubleshooting

**"Login works, then immediately logs out"**
Your `SESSION_DRIVER` is configured as `array`, which does not persist state across requests. Update your `.env`:
```env
SESSION_DRIVER=database
# or SESSION_DRIVER=file
```

**"Analysis never completes, spinner spins forever"**
The background queue worker is not running to process the queued analysis job. Run the worker in a separate terminal:
```bash
php artisan queue:work --tries=1 --timeout=180
```

**"cURL error 28: Connection timed out"**
Analysis can take longer under upstream load. No latency figures are asserted here; measure before changing timeouts. If attempts are timing out, increase the Gemini timeout in `.env`:
```env
GEMINI_TIMEOUT=50
GEMINI_TOTAL_BUDGET=110
```
Then refresh the configuration cache:
```bash
php artisan config:clear
```

**"AI analysis failed: HTTP 400 Unknown name thinking_level"**
The Gemini API configuration received an unaccepted field in `generationConfig`. Ensure `thinking_level` is removed from your payload config — use standard sampling parameters (`temperature`, `topP`, `topK`).

**"Could not fetch image from URL: HTTP 403"**
Wikimedia and certain CDNs block generic automated HTTP clients. While `fetchImage` attaches an outbound User-Agent, some hosts block datacenter IPs completely. If this occurs, download the image locally and upload it directly.

**"API KEY REQUIRED watermark on the map"**
The map view attempted to load CARTO tiles, which require paid tokens. The tile layer in `public/js/analysis.js` points to free OpenStreetMap and Esri endpoints:
- OSM tiles: `https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png`
- Esri satellite: `https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}`

---

## 📂 Project Structure

```
app/
├── Http/
│   ├── Controllers/
│   │   ├── AnalysisController.php    # Upload handling, SSRF-guarded fetch, polling status
│   │   ├── AuthController.php        # User registration, login, session states
│   │   ├── GeminiController.php      # Direct Gemini chat interface
│   │   ├── HomeController.php        # Dashboard stats & history
│   │   ├── LandmarkController.php    # Landmark identify/search API surface
│   │   └── Api/AnalysisApiController.php # Stateless API (health, history, analyze)
│   ├── Middleware/
│   │   ├── AuthenticateAny.php       # Web session OR Sanctum guard (registered alias: auth.any)
│   │   └── ForceCsp.php              # Content Security Policy header injector
│   ├── Requests/
│   │   └── StoreAnalysisImageRequest.php # Shared image-upload validation
├── Jobs/
│   └── AnalyzeImageJob.php           # Asynchronous Gemini vision processing pipeline
├── Models/
│   ├── GeoAnalysis.php               # Analysis records ($appends-serialized)
│   └── … (User, Landmark, Favorite)
├── Providers/
│   ├── AppServiceProvider.php        # Service bindings, rate limiter, prod-DB boot guard
│   └── GeminiServiceProvider.php     # Gemini dependency registration
├── Services/
│   ├── Gemini/
│   │   ├── GeminiClient.php          # HTTP transport, retries, model fallback, generateText
│   │   ├── GeminiPrompt.php          # Prompt builder + EXIF formatting
│   │   ├── GeminiResultEnhancer.php  # Enrichment & field validation
│   │   └── GeminiResultValidator.php # Strict schema validation of AI output
│   ├── GeminiService.php             # Thin orchestrator
│   ├── LandmarkRecognitionService.php # /api/identify pipeline
│   ├── OpenStreetMapService.php      # Free OSM enrichment
│   └── GeocodingService.php          # Shared geocode helper
└── Support/
    └── LocalDatabaseGuard.php        # Refuses local runs pointed at prod database

resources/views/
├── home.blade.php                    # Landing layout & direct upload form
├── analysis.blade.php                # Analysis screen shell (assets in public/)
├── auth/
│   ├── login.blade.php               # Login interface
│   └── register.blade.php            # Registration interface
├── docs.blade.php                    # Public API documentation
├── history.blade.php                 # History list
├── how-it-works.blade.php            # Explainer page
└── partials/how-it-works.blade.php   # Shared explainer partial

public/
├── js/analysis.js                    # Map, 3D globe, polling, UI logic
├── css/analysis.css                  # Styles for the analysis screen
├── sw.js                             # Dev-safe Service Worker (network-first)
└── …

scripts/
├── dev.ps1                           # One-command local dev (server + worker + logs)
├── setup.ps1                         # Bootstrap fresh clone (idempotent)
└── smoke.ps1                         # End-to-end smoke test (fake Gemini, offline)

routes/
├── web.php                           # Web interface & session-authenticated routes
└── api.php                           # Token & Sanctum-authenticated endpoints
```

---

## 📋 Post-Setup Verification

After setting up the project, verify the app boots and the test suite passes:

```bash
# Run the test suite (PHPUnit, SQLite in-memory, offline)
composer test

# End-to-end smoke test (register → login → upload → completed, fake Gemini)
composer smoke

# Sanity-check the route table
php artisan route:list
```

The test suite covers security behaviour (auth gating, ownership scoping) and
should be green before you ship any change.

---

## 🙏 Credits

- [Google AI](https://ai.google.dev/) — Gemini Multimodal Vision API
- [Leaflet](https://leafletjs.com/) — Volodymyr Agafonkin & contributors
- [Esri](https://www.esri.com/) — World Imagery Basemaps
- [OpenStreetMap](https://www.openstreetmap.org/) — Map data © OpenStreetMap contributors
- [Cloudinary](https://cloudinary.com/) — Media management & image hosting
- [Laravel](https://laravel.com/) — Taylor Otwell and the Laravel ecosystem

## 📜 License

This project is open-source software licensed under the [MIT License](LICENSE).
