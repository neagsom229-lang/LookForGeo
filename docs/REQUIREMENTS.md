# Requirements — TraceGeo / LookForGeo

- **Owner:** Chheangsamnang (TraceGeo)
- **Source:** ULTRA PROMPT — Phase 1 (Musk step 1: "Make requirements less dumb")
- **Status:** Re-verified 2026-11-19 against `fix/local-workflow` (53 commits ahead of `origin/main`) · evidence in §9

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
| R10 | `GET /how-it-works` | `Route::view` → `view('how-it-works')` | Logged-in user (web) | `routes/web.php:47` | Keep | **Was listed as public; it sits inside the `auth:web` group.** No page links to it (home uses the on-page `#how-it-works` anchor). |
| R11 | `GET /api/dashboard-data` | `HomeController@dashboardData` | Logged-in user (web) | `routes/web.php:49` | Keep | — |
| R12 | `GET /api/analyze/{id}/status` | `AnalysisController@status` | Logged-in user (web, session) | `routes/web.php:55` | **Keep (post Phase 0 hardening)** | Was: no ownership check — fixed in Phase 0 (user-scoped). |
| R13 | `GET /api/results/{id}` | `AnalysisController@getResults` | Logged-in user (web, session) | `routes/web.php:56` | **Keep (post Phase 0 hardening)** | Was: no ownership check — fixed in Phase 0 (user-scoped). |
| R14 | `DELETE /api/analyze/{id}` | `AnalysisController@destroy` | Logged-in user (web, session) | `routes/web.php:57` | Keep | Already user-scoped (verified). |
| R15 | `POST /api/analyze/{id}/retry` | `AnalysisController@retry` | Logged-in user (web, session) | `routes/web.php:58` | Keep | Already user-scoped (verified). |
| R16 | `GET /api/fetch-image` | `AnalysisController@fetchImage` | Logged-in user (web, session) | `routes/web.php:59` | Keep | Auth + SSRF-guarded (Phase 0). |
| R17 | `GET /api/street-view` | `AnalysisController@streetView` | Frontend | `routes/web.php:60` | Keep | — |
| R18 | `POST /api/clear-cache` | `AnalysisController@clearCache` | Frontend / dev | `routes/web.php:61` | Keep | — |
| R19 | `GET /debug-jobs` | — | — | — | **DELETED (Phase 2)** | Returns 404 — `SecurityTest::test_removed_debug_and_test_routes_return_404`. |
| R20 | `GET /debug-file/{filename}` | — | — | — | **DELETED (Phase 2)** | Returns 404 (same test). |
| R21 | `GET /test-queue` | — | — | — | **DELETED (Phase 2)** | Returns 404 (same test). |
| R22 | `GET /test-gemini` | — | — | — | **DELETED (Phase 2)** | Returns 404 (same test); README no longer references it. |

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
| A13 | `GET /api/test-apis` | — | — | — | **DELETED (Phase 2)** | `TestController` no longer exists; route returns 404 (`SecurityTest`). |
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
| A24 | `GET /api/history` | `AnalysisApiController@history` | auth:sanctum | Mobile | `routes/api.php:64` | **Keep — FIXED 2026-11-19** | `routes/web.php` also declared `GET /api/history`. Web routes register *after* api routes and `RouteCollection::addToCollections` **assigns**, so the web route silently replaced this one and Sanctum clients got 401. Duplicate deleted; contract pinned by `tests/Feature/ApiContractTest.php`. |
| A25 | `POST /api/analyze` | `AnalysisApiController@analyze` | auth:sanctum + throttle:60,1 | Mobile | `routes/api.php:75` | Keep | — |

> Note: `/api/analyze` also exists in web layer at `routes/web.php:54-62` (`api.analyze.*`) for session-authenticated web clients. Two parallel route sets (web + api) exist — known duplication (see Phase 2 / D-items).

### 1.3 Requirement statements

1. Unauthenticated users may only hit: `GET /`, `GET /login`, `POST /login`, `GET /register`, `POST /register`, `GET /docs`, `GET /api/health`. (`GET /how-it-works` is authenticated — see R10.)
2. Authenticated users (web session or sanctum token) may hit: the analysis core and the protected API. (Debug/test routes were deleted in Phase 2 — see R19–R22.)
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
| V6 | `resources/views/landmark/identify.blade.php` + `public/js/identify.js` | Identify UI (`/api/identify` POST, session+CSRF) | — | **DELETED (Phase 2, commit `3ee6153`)** | No route rendered the view and no live JS referenced it (both files verified absent). `/api/identify` itself is kept as a documented API. |
| V7 | `public/js/Control.Geocoder.js` + `public/vendor/leaflet-control-geocoder/**` | Map geocoder | — | **DELETED (both, Phase 2)** | Follow-up resolved: `public/vendor/leaflet-control-geocoder/` is gone too (directory verified absent); nothing references it. |
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
| `public/js/app.js`, `public/js/identify.js` | loaded via `<script>` in `app.blade.php` / `identify.blade.php` | **DELETED (Phase 2, commit `3ee6153`)** — both views were deleted |

---

## 9. Verification pass — 2026-11-19 (branch `fix/local-workflow`)

Musk order applied: 1 requirements → 2 delete → 3 simplify → 4 accelerate → 5 automate.
Environment: PHP 8.5.12 / Composer 2.8.12 / SQLite, offline (Gemini faked).

### 9.1 Step 1 — Make requirements less dumb

| Finding | Evidence | Action |
|---|---|---|
| `GET /api/history` is documented (README, `docs.blade.php`, A24) as a Sanctum endpoint, but `routes/web.php` also declared it and **overwrote** the API route (`RouteCollection::addToCollections` assigns, and web routes register *after* api routes) | `route:list` → `AnalysisController@getHistory`; a Sanctum bearer request returned **401**; `ApiContractTest` failed 2 of 3 | duplicate route + dead handler deleted |
| R10 `/how-it-works` was listed as public | it sits inside the `auth:web` group (`routes/web.php:47`) | requirement corrected |
| R19–R22 and A13 were listed as "Keep (auth-gated)" | all return 404 (`SecurityTest::test_removed_debug_and_test_routes_return_404`); `TestController` no longer exists | requirements corrected |
| V6 / V7 listed as kept | `identify.blade.php`, `identify.js`, `public/vendor/leaflet-control-geocoder/` all verified absent | requirements corrected |
| `docs.blade.php` history example used the key `coords` | code returns `coordinates` (`AnalysisApiController.php:45`) | doc corrected |

### 9.2 Step 2 — Delete

- `routes/web.php`: dead duplicate `GET /api/history` (zero callers anywhere).
- `AnalysisController::getHistory()`: zero callers once the route was gone.

### 9.3 Step 3 — Simplify & optimize

- `Pint` applied to the 28 files failing the style gate.
- `render.yaml`: `CACHE_DRIVER` (a key Laravel 11+ ignores — `config/cache.php:18` reads `CACHE_STORE`) → `CACHE_STORE: database`, matching the web service so worker cache invalidation works.
- `entrypoint.sh`: comment claimed "Web + Queue Worker"; `supervisord.conf` runs Apache only (the queue worker is the separate Render service in `render.yaml`).

### 9.4 Step 4 — Accelerate cycle time

- `composer test`: **1.3 s** for 50 tests / 161 assertions.
- `composer smoke`: one command → offline PHPUnit stage + real-HTTP stage, **exit 0** in ~20 s.

### 9.5 Step 5 — Automate

- **CI was broken**: the `phpunit` and `smoke` jobs ran `composer install --no-dev` and then PHPUnit. Proven locally — after `--no-dev`, `composer test` fails with `'phpunit' is not recognized as an internal or external command` (exit 1). Both jobs now install dev dependencies.
- `.dockerignore` added: the Dockerfile's `COPY .` previously baked `.git` history, `.env*`, `vendor/` and local bootstrap caches into the image.
- `tests/Feature/ApiContractTest.php` added: pins the documented `/api/history` contract (handler, Sanctum 200, anonymous 401).

### 9.6 Gates after the pass

| Gate | Result |
|---|---|
| `php vendor/bin/phpunit` | OK (50 tests, 161 assertions), exit 0 |
| `php vendor/bin/phpunit --filter=ApiContractTest` | OK (3 tests, 8 assertions) |
| `scripts/smoke.ps1` | REAL HTTP SMOKE PASSED, exit 0 |
| `vendor/bin/pint --test` | clean |
| `composer validate` / `composer audit` | valid / no advisories |
| `php artisan config:cache` / `route:cache` / `view:cache` | all exit 0 |
| `php artisan route:list` | 44 routes; `GET api/history` → `AnalysisApiController@history` |

### 9.7 Deployment gap — user action required

The live site `https://lookforgeo.onrender.com` is **not running this branch**; it matches `origin/main`, which is **53 commits behind** local HEAD.

| Probe | Live | `origin/main` | This branch |
|---|---|---|---|
| `GET /js/analysis.js` | **404** | absent (has `identify.js`, `app.js`, `Control.Geocoder.js`) | present |
| `GET /js/identify.js` | **200** | present | deleted |
| `POST /api/identify` (anonymous) | **422** (public → validation) | no auth middleware | **401** |
| `GET /api/search` (anonymous) | **500** | no auth + pre-`1f25f8a` | **401** |
| `origin/main:bootstrap/app.php` | no 401 renderer | — | 401 for all `api/*` |

`origin/main:routes/api.php` applies **no authentication at all** to `/api/identify`, `/api/identify-gps`,
`/api/extract-exif`, `/api/nearby` and `/api/search` — so the deployed instance lets anyone on the
internet consume the cost-bearing Gemini / paid enrichment APIs.

**Required: push `fix/local-workflow` to `origin/main` and let Render redeploy.**

### 9.8 Fresh-clone bootstrap verification — 2026-11-20

Tested twice in a throwaway clone with **no `vendor/` and no `.env`** (the shape of a CI checkout).

| Check | Before | After |
|---|---|---|
| Runtime dirs in checkout | `bootstrap/cache` and `storage/framework/{views,sessions,cache/data,testing}` absent — git cannot track empty dirs | all present via tracked `.gitignore` placeholders |
| `composer install` | printed `bootstrap/cache directory must be present and writable` and still exited **0** | no error; `packages.php` / `services.php` written |
| `phpunit` with no `.env` | 27 errors; 10 `MissingAppKeyException` remained after creating dirs by hand | **OK (53 tests, 169 assertions)**, exit 0 |
| `composer setup-local` | false success: `key:generate` printed the error, exited 0, `APP_KEY` stayed empty | exits non-zero unless `APP_KEY` is actually written |
| `composer smoke` | n/a (missing `bootstrap/cache` breaks boot) | Stage A + Stage B real HTTP **PASSED**, exit 0 |

Root causes:

- Git cannot store empty directories, and the root `.gitignore` excluded `/storage/framework/*`; only `storage/logs` had a placeholder. Every fresh clone (and CI checkout) therefore lacked `bootstrap/cache`.
- Artisan `package:discover` and `key:generate` **print** the writable-directory exception but return **exit code 0**. `setup.ps1` trusted the exit code, so it reported `Generated APP_KEY` while the value was empty. Reproduced: with `bootstrap/cache` moved away, `key:generate` exits 0, prints the exception, `APP_KEY` length stays 0.
- CI has no `.env` at all, so the suite must not depend on one for `APP_KEY`.

Fixes:

- Tracked placeholders: `bootstrap/cache/.gitignore` and `storage/framework/{cache/data,sessions,testing,views}/.gitignore`; root `.gitignore` narrowed to per-directory patterns with `!` negations.
- `phpunit.xml`: fixed **test-only** `APP_KEY` (never used outside the test process).
- `scripts/setup.ps1`: creates missing runtime dirs (self-heals older clones), verifies `APP_KEY` and `public/storage` after running artisan, and exits non-zero on failure.
- `README.md`: two-command fast path (`composer install`, then `composer setup-local`) — nesting `composer install` inside a Composer script hits Composer's 300 s process timeout on a cold cache (proven).

Re-tested behaviours: deleting the five runtime dirs → setup printed `Created 5 runtime directories` and finished exit 0; making `.env` read-only with a blank key → setup exited **1** with `key:generate FAILED -- APP_KEY is still empty` (no more false success).

