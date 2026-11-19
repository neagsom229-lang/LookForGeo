# Requirements — TraceGeo / LookForGeo

- **Owner:** Chheangsamnang (TraceGeo)
- **Source:** ULTRA PROMPT — Phase 1 (Musk step 1: "Make requirements less dumb")
- **Status:** Draft for Phase 1 review · created 2026-11-19

## Purpose of this file

Single source of truth for **what the product must do**, for whom, and why.
Every route / service / model / view / config key must be justified here before
it is deleted or kept (Phase 2 deletes; Phase 3 simplifies; Phase 5 researches).

If a row here is wrong, this document is wrong — fix it in Phase 1 before
deleting anything in Phase 2.

## How to read

Each row: **ID · Area · Item · Purpose (who needs it + what breaks if it is gone) · Evidence (file:line) · Decision (Keep / Delete / Defer) · Challenge**

---

## 1. Routes

### 1.1 Web routes — `routes/web.php`

| ID | Route | Handler | Consumer | Evidence | Decision | Challenge |
|---|---|---|---|---|---|---|
| R1 | `GET /` | `HomeController@index` → `home` | End user (web) | `routes/web.php:32` | Keep | — |
| R2 | `GET /login` | `AuthController@showLogin` → view `auth.login` | End user (web) | `routes/web.php:21` | Keep | — |
| R3 | `POST /login` | `AuthController@webLogin` | End user (web) | `routes/web.php:25` | Keep | — |
| R4 | `GET /register` | `AuthController@showRegister` → view `auth.register` | End user (web) | `routes/web.php:22` | Keep | — |
| R5 | `POST /register` | `AuthController@webRegister` | End user (web) | `routes/web.php:26` | Keep | — |
| R6 | `GET /docs` | `view('docs')` | End user / API consumer | `routes/web.php:29` | Keep | — |
| R7 | `GET /analysis` | `AnalysisController@index` → view `analysis` | Logged-in user (web) | `routes/web.php:42` | Keep | — |
| R8 | `GET /history` | `AnalysisController@history` → view `history` | Logged-in user (web) | `routes/web.php:43` | Keep | — |
| R9 | `POST /logout` | `AuthController@webLogout` | Logged-in user (web) | `routes/web.php:46` | Keep | — |
| R10 | `GET /how-it-works` | `view('how-it-works')` | End user (web) | `routes/web.php:51` | Keep | — |
| R11 | `GET /api/dashboard-data` | `HomeController@dashboardData` | Logged-in user (web) | `routes/web.php:49` | Keep | — |
| R12 | `GET /api/analyze/{id}/status` | `AnalysisController@status` | Logged-in user (web, session) | `routes/web.php:55` | **Keep (post Phase 0 hardening)** | Was: no ownership check — fixed in Phase 0 (user-scoped). |
| R13 | `GET /api/results/{id}` | `AnalysisController@getResults` | Logged-in user (web, session) | `routes/web.php:56` | **Keep (post Phase 0 hardening)** | Was: no ownership check — fixed in Phase 0 (user-scoped). |
| R14 | `DELETE /api/analyze/{id}` | `AnalysisController@destroy` | Logged-in user (web, session) | `routes/web.php:57` | Keep | Already user-scoped (verified). |
| R15 | `POST /api/analyze/{id}/retry` | `AnalysisController@retry` | Logged-in user (web, session) | `routes/web.php:58` | Keep | Already user-scoped (verified). |
| R16 | `GET /api/fetch-image` | `AnalysisController@fetchImage` | Logged-in user (web, session) | `routes/web.php:59` | Keep | Auth + SSRF-guarded (Phase 0). |
| R17 | `GET /api/street-view` | `AnalysisController@streetView` | Frontend | `routes/web.php:60` | Keep | — |
| R18 | `POST /api/clear-cache` | `AnalysisController@clearCache` | Frontend / dev | `routes/web.php:61` | Keep | — |
| R19 | `GET /debug-jobs` | closure | Admin (auth:web) | `routes/web.php:68` | **Keep (auth-gated)** | Debug — likely delete in Phase 2. |
| R20 | `GET /debug-file/{filename}` | closure | Admin (auth:web) | `routes/web.php:82` | **Keep (auth-gated)** | Debug — likely delete in Phase 2. |
| R21 | `GET /test-queue` | closure → dispatch job 1 | Dev | `routes/web.php:90` | **Keep (auth-gated)** | Debug — likely delete in Phase 2. |
| R22 | `GET /test-gemini` | closure | Dev/Admin (auth:web) | `routes/web.php:99` | **Keep (auth-gated)** | Debug — likely delete in Phase 2. |

### 1.2 API routes — `routes/api.php` (post Phase 0)

| ID | Route | Handler | Auth | Who uses it | Evidence | Decision | Challenge |
|---|---|---|---|---|---|---|---|
| A1 | `GET /api/health` | `AnalysisApiController@health` | **Public** | Status checks / uptime monitor | `routes/api.php:14` | Keep | Must stay public (no paid API). |
| A2 | `POST /api/web-login` | `AuthController@webLogin` | Public | Web clients | `routes/api.php:20` | Keep | — |
| A3 | `POST /api/web-register` | `AuthController@webRegister` | Public | Web clients | `routes/api.php:21` | Keep | — |
| A4 | `POST /api/web-logout` | `AuthController@webLogout` | auth | Web clients | `routes/api.php:22` | Keep | — |
| A5 | `POST /api/register` | `AuthController@apiRegister` | Public | Mobile / 3rd party | `routes/api.php:25` | Keep | — |
| A6 | `POST /api/login` | `AuthController@apiLogin` | Public | Mobile / 3rd party | `routes/api.php:26` | Keep | — |
| A7 | `GET /api/verify/{token}` | `AuthController@verifyEmail` | Public | Mobile | `routes/api.php:27` | Keep | — |
| A8 | `POST /api/identify` | `LandmarkController@identify` | **auth:any (web\|sanctum)** | Logged-in user / API client (cost-bearing: Gemini + paid enrich) | `routes/api.php:36-42` | **Keep, hardened in Phase 0** | Was: public — anyone could burn Gemini quota. Now 401 when anonymous. |
| A9 | `POST /api/identify-gps` | `LandmarkController@identifyGpsOnly` | **auth:any** | Logged-in user (cost-bearing) | `routes/api.php:37` | **Keep, hardened in Phase 0** | Was: public — quota risk. |
| A10 | `POST /api/extract-exif` | `LandmarkController@extractExif` | **auth:any** | Logged-in user | `routes/api.php:38` | **Keep, hardened in Phase 0** | Was: public. |
| A11 | `GET /api/nearby` | `LandmarkController@nearby` | **auth:any** | Logged-in user | `routes/api.php:40` | **Keep, hardened in Phase 0** | Was: public — OSM/Places enrichment cost. |
| A12 | `GET /api/search` | `LandmarkController@search` | **auth:any** | Logged-in user | `routes/api.php:41` | **Keep, hardened in Phase 0** | Was: public — Google Places (paid). |
| A13 | `GET /api/test-apis` | `TestController@testAll` | **auth:any** | Dev/admin | `routes/api.php:47-49` | **Keep (auth-gated)** | Was: public — probes paid APIs. |
| A14 | `GET /api/user` | `AuthController@user` | auth:sanctum | Mobile | `routes/api.php:57` | Keep | — |
| A15 | `PUT /api/profile` | `AuthController@updateProfile` | auth:sanctum | Mobile | `routes/api.php:58` | Keep | — |
| A16 | `POST /api/logout` | `AuthController@apiLogout` | auth:sanctum | Mobile | `routes/api.php:59` | Keep | — |
| A17 | `GET /api/landmarks/{id}` | `LandmarkController@show` | auth:sanctum | Mobile | `routes/api.php:62` | Keep | — |
| A18 | `GET /api/my-landmarks` | `LandmarkController@myLandmarks` | auth:sanctum | Mobile | `routes/api.php:63` | Keep | — |
| A19 | `GET /api/favorites` | `LandmarkController@favorites` | auth:sanctum | Mobile | `routes/api.php:64` | Keep | — |
| A20 | `POST /api/favorites/{id}` | `LandmarkController@toggleFavorite` | auth:sanctum | Mobile | `routes/api.php:65` | Keep | — |
| A21 | `POST /api/share/{id}` | `LandmarkController@share` | auth:sanctum | Mobile | `routes/api.php:66` | Keep | — |
| A22 | `GET /api/export/{id}` | `LandmarkController@export` | auth:sanctum | Mobile | `routes/api.php:67` | Keep | — |
| A23 | `POST /api/gemini-chat` | `GeminiController@chat` | auth:sanctum + throttle:10,1 | Mobile / debug | `routes/api.php:70` | Keep | — |
| A24 | `GET /api/history` | `AnalysisApiController@history` | auth:sanctum | Mobile | `routes/api.php:73` | Keep | — |
| A25 | `POST /api/analyze` | `AnalysisApiController@analyze` | auth:sanctum + throttle:60,1 | Mobile | `routes/api.php:75` | Keep | — |

> Note: `/api/analyze` also exists in web layer at `routes/web.php:54-62` (`api.analyze.*`) for session-authenticated web clients. Two parallel route sets (web + api) exist — known duplication (see Phase 2 / D-items).

### 1.3 Requirement statements

1. Unauthenticated users may only hit: `GET /`, `GET /login`, `POST /login`, `GET /register`, `POST /register`, `GET /docs`, `GET /how-it-works`, `GET /api/health`.
2. Authenticated users (web session or sanctum token) may hit: analysis core, debug (admin-only intent), protected API.
3. A user may only read/delete/retry their own `GeoAnalysis` rows (IDOR) — enforced in Phase 0 (status/getResults/destroy/retry).
4. Cost-bearing endpoints must be throttled (api group: 60/min per user/IP) and authenticated.

---

## 2. Services

| ID | Service | Purpose (who/why, blast radius if deleted) | Evidence | Decision | Challenge |
|---|---|---|---|---|---|
| S1 | `GeminiService` | Core: Gemini vision+geolocation; retries/quota. Split into `GeminiClient` / `GeminiPrompt` / `GeminiResultEnhancer` (Phase 3, P1). | `app/Services/GeminiService.php`, used by `AnalyzeImageJob`, `LandmarkController`, `GeminiController` | **Keep (refactored)** | — |
| S2 | `LandmarkRecognitionService` | Orchestrates identify → enrich (OSM, what3words, wikipedia, cache). | used by `LandmarkController`, `AnalyzeImageJob`, `GeminiController` | **Keep** | — |
| S3 | `WeatherService` | OpenWeather enrichment — never injected anywhere; weather is fetched inline via Open-Meteo (`LandmarkController@getWeatherData`). | `app/Services/WeatherService.php` | **DELETED (commit 6e16593)** | Re-audited pre-merge 2026-11-19: confirmed unused, removed. |
| S4 | `OpenStreetMapService` | Free OSM enrichment (reverse geocode, roads). Deleting = weaker results, no hard break. | used by `LandmarkController`, `LandmarkRecognitionService`; registered `AppServiceProvider:22` | **Keep** | — |
| S5 | `ElevationService` | Proved unused: only registered in provider, never injected. | `app/Providers/AppServiceProvider.php` | **DELETED (Phase 2, commit dd44e25)** | — |
| S6 | `SunService` | Proved unused: only registered in provider, never injected. | `app/Providers/AppServiceProvider.php` | **DELETED (Phase 2, commit dd44e25)** | — |
| S7 | `GeocodingService` | Used by `LandmarkRecognitionService` (constructor-injected). | `app/Services/LandmarkRecognitionService.php:12` | **Keep** | — |
| S8 | `PlacesService` | OSM-based places enrichment — never injected anywhere; Google Places is called inline via raw HTTP with `services.google.maps_key` (`LandmarkController@getGooglePlace`). | `app/Services/PlacesService.php` | **DELETED (commit 6e16593)** | Re-audited pre-merge 2026-11-19: confirmed unused, removed. |
| S9 | `IpGeolocationService` | Never injected anywhere. | `app/Services/IpGeolocationService.php` | **DELETED (commit 6e16593)** | Re-audited pre-merge 2026-11-19: confirmed unused, removed. |

## 3. Models

| ID | Model | Purpose | Evidence | Decision | Challenge |
|---|---|---|---|---|---|
| M1 | `User` | Auth + ownership root. | `app/Models/User.php` | **Keep** | — |
| M2 | `GeoAnalysis` | **Live** pipeline result (written by `AnalyzeImageJob`). | `app/Models/GeoAnalysis.php`, migrations `2026_08_23_132200_…`, `2026_08_28_162058_add_user_id…` | **Keep** | Legacy `Analysis` parallel pipeline → **delete candidate (D3)**; `Landmark`/`Favorite` retention TBD. |
| M3 | `Analysis` | Legacy pipeline — unused (live pipeline is `AnalyzeImageJob` → `GeoAnalysis`). | `app/Models/Analysis.php` | **DELETED (Phase 2, commit a3ebce5)** | Proof: grep found no `Analysis::` usage outside the model itself. |
| M4 | `Landmark` | User landmarks / corrections / favorites join. | `app/Models/Landmark.php`, migration `2026_08_20_071143_create_landmarks_table…` | **Keep** | — |
| M5 | `Favorite` | User favorites. | `app/Models/Favorite.php` | **Keep** | — |

## 4. Views / frontend assets

| ID | View / asset | Purpose | Evidence | Decision | Challenge |
|---|---|---|---|---|---|
| V1 | `resources/views/home.blade.php` | Landing + dashboard entry | routed `routes/web.php:32` | Keep | — |
| V2 | `resources/views/analysis.blade.php` | Analysis screen shell; 2,600+ line monolith extracted (P5) into `public/css/analysis.css` + `public/js/analysis.js` (commit 80c51a3). | `routes/web.php:42` | **Keep (simplified)** | — |
| V3 | `resources/views/history.blade.php` | History list | routed `routes/web.php:43` | Keep | — |
| V4 | `resources/views/docs.blade.php` | Public API docs | routed `routes/web.php:29` | Keep | — |
| V5 | `resources/views/auth/login.blade.php`, `auth/register.blade.php` | Auth screens | routed `routes/web.php:21-26` | Keep | — |
| V6 | `resources/views/landmark/identify.blade.php` + `public/js/identify.js` | Identify UI (`/api/identify` POST, session+CSRF) | `public/js/identify.js:139` | Keep | — |
| V7 | `public/js/Control.Geocoder.js` + `public/vendor/leaflet-control-geocoder/**` | Map geocoder. `public/js/Control.Geocoder.js` **DELETED (Phase 2, D8, commit 6a5baf2)** — views load the unpkg CDN copy; `vendor/.../js/Control.Geocoder.js` is also unreferenced (views use unpkg CDN; only `css/identify.css` is used via `asset()`) — flagged for follow-up. | see `resources/views/landmark/identify.blade.php` | **Keep (partial)** | — |
| V8 | `resources/js/*`, `resources/css/app.css`, `vite.config.js`, `package.json`, `package-lock.json` | Entire Vite pipeline — **DELETED (Phase 2, D8, commit 6a5baf2)**. Confirmed unused: no `@vite` directive anywhere; service worker registers via inline blade. | — | **DELETED (D8 confirmed)** | — |

## 5. Config / env keys

| ID | Key | Purpose | Evidence | Decision | Challenge |
|---|---|---|---|---|---|
| C1 | `services.gemini.key` / `.model` / `.fallback_model` / `GEMINI_MAX_TOTAL_TIME` / `GEMINI_RETRIES` / `GEMINI_TIMEOUT` | Gemini core | `config/services.php:41`, `app/Services/GeminiService.php`, `bootstrap/cache`? | Keep | **P1**: split GeminiService. |
| C2 | `services.cloudinary.*` / `CLOUDINARY_URL` | Image storage | `app/Http/Controllers/AnalysisController.php:78-85`, `app/Jobs/AnalyzeImageJob.php:322-336`, `config/cloudinary.php:34` | Keep | — |
| C3 | `services.google_maps.embed_key`, `services.google_maps.key`, `GOOGLE_MAPS_EMBED_KEY` | Maps embed / places | `resources/views/analysis.blade.php` (was hardcoded → removed), `config/services.php`, `LandmarkController@getGooglePlace:388` | Keep | Secret removed in `1dc3603` — must never be re-hardcoded. |
| C4 | `OPENWEATHER_API_KEY`, `TIMEZONEDB_API_KEY`, `OPENAI_API_KEY` | Dead keys — services removed (OPENWEATHER: S3 deleted; TIMEZONEDB: SunService deleted). `WHAT3WORDS_API_KEY` used inline in `LandmarkController` — kept. `OPENAI_API_KEY` has no code usage — flagged, not deleted. `POSTMARK_API_KEY`, `RESEND_API_KEY` used by mail config — kept. | `config/services.php:11-82` | **Keep, with flags** | — |
| C5 | `DB_*`, `CACHE_*`, `QUEUE_*`, `SESSION_*` | Infra | `config/database.php`, `config/cache.php`, `config/queue.php`, `config/session.php`, `phpunit.xml:26-28` | Keep | — |
| C6 | `SANCTUM_*`, `AUTH_*` | Auth | `config/sanctum.php`, `config/auth.php` | Keep | — |

## 6. Cross-cutting requirements (Phase 0 conclusions feeding Phase 2+)

1. No secret may live in git history (Phase 0: gitleaks added; rotation manual).
2. Cost-bearing endpoints require auth + throttle (Phase 0: done via `auth.any` + api limiter).
3. Analysis read/delete/retry scoped to owner (Phase 0: done).
4. Single pipeline: `AnalyzeImageJob` → `GeoAnalysis` (Phase 2 D3: consolidate away `Analysis`).
5. One auth model per client type (Phase 2 D7: web session vs sanctum — web.php/api.php duplication to resolve).

---

## 7. The Requirement Table (summary view)

The full per-area tables are above. The master list of items requiring a decision:

- **Routes:** R1–R22 (web), A1–A25 (api) — decisions mostly fixed by Phase 0 hardening; debug routes → likely Delete in Phase 2; duplicate web/api `/api/analyze` sets → resolve in Phase 2/3.
- **Services:** S1, S2, S4, S7 kept; S3, S5, S6, S8, S9 **DELETED** (S3/S8/S9 re-audited pre-merge, commit 6e16593).
- **Models:** M1, M2, M4, M5 kept; M3 **DELETED** (Phase 2, commit a3ebce5).
- **Views/assets:** V2 extracted to static assets (commit 80c51a3); V7 `public/js/Control.Geocoder.js` **DELETED**; V8 Vite pipeline **DELETED** (both Phase 2 D8, commit 6a5baf2); vendor geocoder JS flagged.
- **Config:** C1–C6 kept; dead keys flagged (`OPENWEATHER_API_KEY`, `TIMEZONEDB_API_KEY`, `OPENAI_API_KEY` — no code references).

---

## 8. Audit log (pre-merge, 2026-11-19)

### D2 re-audit — "optional" services

| Service | Method of proof | Result |
|---|---|---|
| `WeatherService` | `git grep -i weather` — controller fetches weather **inline** via Open-Meteo; no service injection anywhere | **DELETED** (6e16593) |
| `IpGeolocationService` | `git grep -i ipgeo` — no references outside provider | **DELETED** (6e16593) |
| `PlacesService` | `git grep -i places` — no injection; `getGooglePlace` calls Google Places API inline via `Http::` with `services.google.maps_key` | **DELETED** (6e16593) |
| `GeocodingService` | constructor-injected into `LandmarkRecognitionService` | **KEEP** |
| `OpenStreetMapService` | injected into `LandmarkController` (`osmService`) + `LandmarkRecognitionService` | **KEEP** |

### D8 confirmation — dead frontend pipeline vs live assets

| Candidate | Proof | Result |
|---|---|---|
| `resources/js/*`, `resources/css/app.css`, `vite.config.js`, `package.json`, `package-lock.json` | no `@vite`, no `resources/js` references anywhere; service worker registered via inline blade | **DELETED** (Phase 2, commit 6a5baf2) |
| `public/js/Control.Geocoder.js` | no view references it; views load the unpkg CDN copy | **DELETED** (Phase 2, commit 6a5baf2) |
| `public/vendor/leaflet-control-geocoder/js/Control.Geocoder.js` | unpkg CDN used for JS; only `vendor/.../css/identify.css` referenced via `asset()` | **KEEP (flagged)** |
| `public/js/app.js`, `public/js/identify.js` | loaded via `<script>` in `app.blade.php` / `identify.blade.php` | **KEEP** |

