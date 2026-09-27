<div align="center">

# 🌍 TraceGeo — AI-Powered Image Geolocation

**Upload a photo. Get the landmark, city, country, and coordinates — powered by Google Gemini.**

[![Laravel Version](https://img.shields.io/badge/Laravel-12.x-FF2D20?style=for-the-badge&logo=laravel&logoColor=white)](https://laravel.com)
[![PHP Version](https://img.shields.io/badge/PHP-8.2+-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://php.net)
[![Gemini API](https://img.shields.io/badge/Google%20Gemini-Flash-8E75B2?style=for-the-badge&logo=google&logoColor=white)](https://aistudio.google.com/)
[![Tailwind CSS](https://img.shields.io/badge/Tailwind_CSS-38B2AC?style=for-the-badge&logo=tailwind-css&logoColor=white)](https://tailwindcss.com)
[![License](https://img.shields.io/badge/License-MIT-green.svg?style=for-the-badge)](#license)

<br />

<img src="public/images/logo.png" alt="TraceGeo Banner" width="750" />

</div>

---

## 📌 Overview

**TraceGeo** is an open-source OSINT (Open Source Intelligence) investigation tool designed to identify the exact geographic origin of photos. By analyzing visual features, matching patterns against known landmarks, and cross-referencing contextual indicators, TraceGeo returns precise coordinates, confidence estimates, interactive maps, and shareable dossier reports.

---

## ✨ Key Features

- **🧠 Multi-Tier AI Geolocation** — Leverages `gemini-3.6-flash` (with `gemini-3.5-flash` fallback) to parse subtle geographic, architectural, and ecological indicators.
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
| **Frontend** | Blade, Tailwind CSS, Font Awesome 6 |
| **Database** | PostgreSQL (Production) / SQLite (Local development) |
| **Cache & Queue** | Database driver (`CACHE_STORE=database`, `QUEUE_CONNECTION=database`) |
| **Mapping Engine** | Leaflet.js + Esri World Imagery + OpenStreetMap |
| **AI Inference** | Google Gemini API (`gemini-3.6-flash` / `gemini-3.5-flash`) |
| **Media Hosting** | Cloudinary (optional) |
| **Authentication** | Laravel Sanctum |
| **Offline / PWA** | Custom Service Worker (`public/sw.js`) |

---

## 🚀 Getting Started

### Prerequisites

Ensure the following tools are installed on your host system:

- **PHP 8.2+** with extensions: `pdo_sqlite`, `pdo_pgsql`, `curl`, `mbstring`, `openssl`
- **Composer 2.x**
- **Node.js 18+** & **npm** (for compiling frontend assets)

### Installation

**1. Clone & install dependencies**

```bash
# Clone the repository
git clone https://github.com/neagsom229-lang/LookForGeo.git
cd LookForGeo

# Install PHP dependencies
composer install

# Install & compile Node dependencies (if modifying assets)
npm install
npm run build
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
GEMINI_MODEL=gemini-3.6-flash
GEMINI_FALLBACK_MODEL=gemini-3.5-flash
GEMINI_MAX_TOTAL_TIME=120

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
- **SSRF Protection** — Outbound image fetchers validate target destinations; private IP blocks (`10.0.0.0/8`, `192.168.0.0/16`, `127.0.0.0/8`) and localhost endpoints are strictly blocked.
- **Protected Gemini Endpoints** — Diagnostic and interactive endpoints (`/api/test-gemini`, `/api/gemini-chat`) require active authentication.
- **Strict Rate Limiting** — Configured at `throttle:10,1` on `/api/gemini-chat` and `throttle:60,1` on `/api/analyze`.
- **Persistent Sessions** — Session driver strictly configured to `database` or `file` (the `array` driver is barred in production).
- **Legitimate User-Agent** — Outbound asset fetchers provide a fully-formed User-Agent header to comply with Wikimedia and CDN upstream policies.

🛡️ **Reporting security issues:** please report any vulnerabilities privately to the project maintainers.

---

## 🔌 API Endpoints

| Method | Endpoint | Auth | Purpose |
|---|---|---|---|
| POST | `/api/analyze/store` | Session | Upload an image for geolocation analysis |
| GET | `/api/analyze/{id}/status` | Session | Poll processing status & retrieve results |
| GET | `/api/fetch-image?url=` | Session | Fetch remote image securely (SSRF-guarded) |
| GET | `/api/dashboard-data` | Session | Retrieve past user analyses & history |
| POST | `/api/gemini-chat` | Sanctum + Throttle | Direct Gemini chat session (rate limit: 10/min) |
| POST | `/api/login` | Public | Authenticate user & issue token / session |
| POST | `/api/register` | Public | Register a new user account |
| POST | `/api/logout` | Sanctum | Revoke current user session / token |

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
High-resolution multimodal analysis can take 20–40 seconds depending on upstream load. Increase the Gemini timeout in `.env`:
```env
GEMINI_MAX_TOTAL_TIME=120
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
The map view is attempting to load CARTO tiles (which now require paid tokens). Ensure your tile layer in `resources/views/analysis.blade.php` points to free OpenStreetMap or Esri endpoints:
- OSM tiles: `https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png`
- Esri satellite: `https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}`

---

## 📂 Project Structure

```
app/
├── Http/
│   ├── Controllers/
│   │   ├── AnalysisController.php    # Upload handling, proxy fetch, polling status
│   │   ├── AuthController.php        # User registration, login, session states
│   │   ├── GeminiController.php      # Direct Gemini chat interface
│   │   └── LandmarkController.php    # Landmark search and coordinate resolution
│   └── Middleware/
│       └── ForceCsp.php              # Content Security Policy header injector
├── Jobs/
│   └── AnalyzeImageJob.php           # Asynchronous Gemini vision processing pipeline
├── Providers/
│   ├── AppServiceProvider.php        # Global rate limiters & forceScheme helpers
│   └── GeminiServiceProvider.php     # Gemini client singleton registration
└── Services/
    └── GeminiService.php             # Upstream client wrapper with fallback logic

resources/views/
├── home.blade.php                    # Landing layout & direct upload form
├── analysis.blade.php                # Interactive coordinates, Leaflet map, reasoning
├── auth/
│   ├── login.blade.php               # Login interface
│   └── register.blade.php            # Registration interface
└── landmark/
    └── identify.blade.php            # Map pinpoint picker

routes/
├── web.php                           # Web interface & session-authenticated routes
└── api.php                           # Token & Sanctum-authenticated endpoints

public/
└── sw.js                             # Dev-safe Service Worker (network-first)
```

---

## 📋 Post-Setup Verification

After setting up the project, verify that your local logo asset exists:

```bash
# Windows (PowerShell)
Test-Path "public\images\logo.png"

# Linux / macOS (Bash)
test -f public/images/logo.png && echo "Logo found" || echo "Logo missing"
```

If the logo image does not exist in your repository, replace it with your own banner or remove the `<img>` reference at the top of this document.

---

## 🙏 Credits

- [Google AI](https://ai.google.dev/) — Gemini Multimodal Vision API
- [Leaflet](https://leafletjs.com/) — Volodymyr Agafonkin & contributors
- [Esri](https://www.esri.com/) — World Imagery Basemaps
- [OpenStreetMap](https://www.openstreetmap.org/) — Map data © OpenStreetMap contributors
- [Cloudinary](https://cloudinary.com/) — Media management & image hosting
- [Laravel](https://laravel.com/) — Taylor Otwell and the Laravel ecosystem

## 📜 License

This project is open-source software licensed under the [MIT License](#license).
