# TraceGeo Requirements v2 (Musk step 1 — every requirement has an owner)

Status: Phase C draft. Every line below is **owned by you**. Where a number is
marked PROPOSED you must confirm or replace it; the code does not yet claim it.
Measured numbers come from real commands in this repository, not estimates.

## 1. Numbers (owner: you)

| ID | Requirement | Number | Status / evidence |
|---|---|---|---|
| R1 | p50 time-to-result (upload → result visible) | **<= 12 s** | Target from `docs/PRO.md` Phase D. Not yet measured on live Gemini. Historical observation you reported: ~27 s/image on a 13 KB thumbnail. |
| R2 | p95 time-to-result | **<= 40 s** | Target from `docs/PRO.md` Phase D. Not yet measured. |
| R3 | Repeat upload of identical bytes by the same user | **< 1 s** | Definition of Done in `docs/PRO.md`. Not implemented: sha256 cache is Phase D. |
| R4 | Max failure visibility (AI error → user sees it) | **< 120 s** | Designed, not yet measured end-to-end. Evidence: 30 s/request, 1 primary retry, 1 fallback, 90 s shared budget (`app/Services/Gemini/GeminiClient.php`), stage label via retry callback (`app/Jobs/AnalyzeImageJob.php`), retry button (`public/js/analysis.js:408`). |
| R5 | Cost per analysis | **PROPOSED ceiling $0.01** | No billing data available. You must set this from Google AI Studio pricing and the September 2026 invoice. |
| R6 | Daily cap per user | **PROPOSED 50 analyses/day** | Not implemented (Phase F per-user throttle/quota). You must confirm the number. |
| R7 | Accuracy: median geolocation error | **PROPOSED <= 25 km** | Not measured; `geo:eval` is Phase E. You must confirm. |
| R8 | Accuracy: share within 200 km | **PROPOSED >= 80%** | Not measured; Phase E. You must confirm. |
| R9 | Confidence honesty | **No stated confidence of 100 on low-quality input** | You observed "Golden Gate Bridge, 100" on a 13 KB thumbnail. Fix is Phase E (calibration from checkable signals). |
| R10 | Global kill switch | `GEMINI_DAILY_LIMIT` stops all analysis with a clear message | Not implemented (Phase F). You must set the number. |
| R11 | Test suite | `composer test` < 30 s | **Measured**: `OK (66 tests, 215 assertions)` in 9.84 s; raw phpunit 6.34 s. Slow process test in `slow` group (`--group=slow`) run by CI. |
| R12 | Local flow | no worker or server death without a loud error | `scripts/dev.ps1` refuses duplicate serve/worker, reaps stuck analyses every 60 s, kills the process tree on exit; not yet soak-tested for 30+ minutes. |

Measured baseline from this checkout (evidence: `php vendor\bin\phpunit`):
`OK (67 tests, 219 assertions)`. Live Gemini latency and cost are still
unmeasured because no `GEMINI_API_KEY` is available to this agent.

## 2. Inventory — keep / delete with grep evidence

Format: item — who uses it (file:line) — decision.

### 2.1 Routes

**Web (`routes/web.php`)**

| Route | Evidence | Decision |
|---|---|---|
| GET `/` | `resources/views/home.blade.php` (page itself) | KEEP |
| GET `/analysis`, `/history` | `analysis.blade.php`, `history.blade.php`; `AnalysisController@index/history` | KEEP |
| GET `/login`, `/register`; POST `/login`, `/register`, `/logout` | `auth/login.blade.php`, `auth/register.blade.php`; `AuthController` | KEEP |
| GET `/docs` | `resources/views/docs.blade.php` | KEEP |
| POST `/api/analyze/store` | `home.blade.php:2072,2204`; `SmokeTest.php:68` | KEEP |
| GET `/api/analyze/{id}/status` | `public/js/analysis.js:1116`; `QueueHintTest`, `SecretLeakTest` | KEEP |
| POST `/api/analyze/{id}/retry` | `public/js/analysis.js:408`; `AnalysisRetryTest.php:38,59,75` | KEEP |
| GET `/api/results/{id}` | `SecurityTest.php:71,158` (ownership contract) | KEEP |
| DELETE `/api/analyze/{id}` | `SecurityTest.php:72` | KEEP |
| GET `/api/fetch-image` | `home.blade.php:2154` | KEEP |
| GET `/api/dashboard-data` | `home.blade.php:2289` | KEEP |
| GET `/how-it-works` | Only `partials/how-it-works.blade.php` exists, included at `home.blade.php:1786`; view `how-it-works` missing → 500 | **DELETED** |
| GET `/street-view`, POST `/clear-cache` | No UI, test or doc caller; controller methods deleted | **DELETED** |

**API (`routes/api.php`)**

| Route | Evidence | Decision |
|---|---|---|
| GET `/health` | `scripts/probe-prod.ps1`, `scripts/smoke.ps1`, `CspTest.php` | KEEP |
| POST `/register`, `/login`, GET `/verify/{token}` | `AuthController`; external/mobile clients | KEEP |
| POST `/identify` | `LandmarkController.php:31`; `SecurityTest.php:23,89,110,168` | KEEP (Phase A auth gate, cost-bearing) |
| POST `/identify-gps`, `/extract-exif`, GET `/nearby` | No controller methods existed; no UI/test/doc caller (verified by grep) | **DELETED in pre-deploy gate** — paths now return 404; `SecurityTest` pins this. `docs/RELEASE.md` from the hotfix described them as pre-existing 500s; this branch removes them. |
| GET `/search` | `LandmarkController@searchPlace` at :525; `SecurityTest.php:27,94,116` | KEEP |
| GET `/user`, PUT `/profile`, POST `/logout` | `AuthController`; Sanctum clients | KEEP |
| POST `/gemini-chat` | `GeminiController@chat`; no UI caller, external API | KEEP |
| GET `/history` | `AnalysisApiController@history`; `ApiContractTest.php:28,65,77`; `docs.blade.php:432` | KEEP |
| POST `/analyze` | `AnalysisApiController@analyze`; `AnalysisPipelineTest.php:80`; `docs.blade.php:412` | KEEP |
| GET `/landmarks/{id}`, `/my-landmarks`, `/favorites`, `/favorites/{id}`, `/share/{id}`, `/export/{id}` | Controller methods `show`, `myLandmarks`, `favorites`, `toggleFavorite`, `share`, `export` **not defined** (grep: 0 hits); no UI/test/doc caller; no favorites table | **DELETED** |

Framework routes (`sanctum/csrf-cookie`, `storage/{path}`, `up`) are vendor-owned: KEEP.

### 2.2 Services

| Service | Evidence | Decision |
|---|---|---|
| `GeminiService` | `GeminiController.php:26`, `AnalyzeImageJob.php:51` (live pipeline) | KEEP |
| `Gemini\GeminiClient` | `GeminiService.php:21`, `ProbeGemini.php:19`, `GeminiBudgetTest.php` | KEEP |
| `Gemini\GeminiPrompt` | `GeminiService.php:22` | KEEP |
| `Gemini\GeminiResultEnhancer` | `GeminiService.php:23`; uses Landmark model | KEEP |
| `Gemini\GeminiResultValidator` | `GeminiService.php:24`; `GeminiResultValidatorTest.php` | KEEP |
| `Gemini\SecretSanitizer` | `AnalysisController`, `GeminiController`, `AnalyzeImageJob`, `SecretLogProcessor`, tests | KEEP |
| `GeocodingService` | `AppServiceProvider.php:20`, `LandmarkRecognitionService.php:12,44` | KEEP |
| `LandmarkRecognitionService` | `AppServiceProvider.php:21`, `LandmarkController.php:20` (`/api/identify`) | KEEP — legacy but it is the only implementation of the auth-gated `/api/identify` |
| `OpenStreetMapService` | `LandmarkController.php:21`, `SearchPlacesTest.php:27,41,52` | KEEP |

### 2.3 Models and tables

| Model / table | Evidence | Decision |
|---|---|---|
| `GeoAnalysis` / `geo_analyses` | `AnalysisController`, `AnalysisApiController`, `AnalyzeImageJob`, `HomeController`, `ReapStuckAnalyses`, ≥8 tests | KEEP |
| `User` / `users` | `AuthController.php:73,130,167`, `UserFactory` | KEEP |
| `Landmark` / `landmarks` | **`GeminiResultEnhancer.php:106,107,131,187`** (cross-reference in the live pipeline); `LandmarkSeeder.php:68`; `MigrateFresh.php` | **KEEP** (explicitly checked before deletion, as required) |
| `Favorite` / `favorites` | Model had 0 callers; no `favorites` migration exists | **DELETED** |
| `personal_access_tokens`, `jobs`, `failed_jobs`, `cache`, `sessions` | Sanctum / queue / `CACHE_STORE=database` / `SESSION_DRIVER=database` | KEEP |

Four unused static helpers on `Landmark` (`searchByName`, `getNearby`, `getByCountry`, `searchAll`) had 0 callers and were deleted; the model and its two live `Landmark::query()/where()` calls remain.

### 2.4 JavaScript

| File | Evidence | Decision |
|---|---|---|
| `public/js/analysis.js` | `analysis.blade.php` `<script src="{{ asset('js/analysis.js') }}">`; handles polling, stage label, retry button | KEEP |
| Inline scripts in `home.blade.php` | Not a separate file; upload/URL/dashboard fetch at :2072, :2154, :2204, :2289 | KEEP |

## 3. What Phase C deleted (net lines)

`git diff --stat` for the deletion commit: **13 insertions, 116 deletions → net -103 code lines**.
Routes went from 48 to 35. Added documentation is not counted as code.

## 4. What needs your decision

1. Confirm or replace R5 (cost/analysis), R6 (daily cap), R7/R8 (accuracy), R10 (global limit).
2. Resolved in the pre-deploy gate: the three auth-gated but method-less
   endpoints (`identify-gps`, `extract-exif`, `nearby`) were deleted; they now
   return 404 and no longer route into missing controller methods.
3. Confirm that deleting the undocumented landmark/favorites API block is safe
   for any mobile client you run (grep found no in-repo caller).
