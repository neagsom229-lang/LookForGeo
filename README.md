<div align="center">

# 🌍 TraceGeo — AI-Powered Image Geolocation

<p align="center">
  <strong>Upload a photo. Get the landmark, city, country, and coordinates — powered by Google Gemini.</strong>
</p>

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

- **PHP 8.2+** with extensions enabled: `pdo_sqlite`, `pdo_pgsql`, `curl`, `mbstring`, `openssl`
- **Composer 2.x**
- **Node.js 18+** & **npm** (for compiling frontend assets)

---

### Installation Steps

#### 1. Clone & Install Dependencies

```bash
# Clone the repository
git clone [https://github.com/neagsom229-lang/LookForGeo.git](https://github.com/neagsom229-lang/LookForGeo.git)
cd LookForGeo

# Install PHP dependencies
composer install

# Install & compile Node dependencies (if editing assets)
npm install
npm run build

# Copy example environment configuration
cp .env.example .env

# Generate application key
php artisan key:generate

# For SQLite: create the database file if it does not exist
touch database/database.sqlite

# Run all migrations
php artisan migrate

# Seed baseline landmarks (optional)
php artisan db:seed --class=LandmarkSeeder

## Terminal 1: Application Server

php artisan serve

##Terminal 2: Background Queue Worker

php artisan queue:work --tries=1 --timeout=180

🧭 Architecture & Lifecycle

Image Input (File / URL)
       │
       ▼
Controller Validation & Storage
       │
       ▼
Dispatch ImageAnalysisJob ──▶ [Database Queue] ──▶ Worker Process
                                                          │
   ┌──────────────────────────────────────────────────────┴──────────────────────────────────────────────────────┐
   ▼                                                      ▼                                                      ▼
1. Extract Features                               2. Multi-Model Inference                              3. Generate Coordinate Output
   • Edge & structural cues                          • Gemini 3.6 Flash                                    • Latitude / Longitude
   • Text / signage detection                        • Fallback to Gemini 3.5 Flash                        • Confidence level
   • Climate & environmental factors                 • Contextual reasoning                                • Evidence dossier
   └──────────────────────────────────────────────────────┬──────────────────────────────────────────────────────┘
                                                          ▼
                                            Store Result in Database
                                                          │
                                                          ▼
                                            Frontend Polling / Render Map

📄 License
This project is licensed under the MIT License.

