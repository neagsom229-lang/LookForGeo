# FIX_LOG — Local workflow diagnosis (Step 0)

Branch: `fix/local-workflow` (from `phase-0-secrets` @ `cfe3de7`). All evidence below
produced on this branch. Production services were **not** contacted except where noted
(one accidental real Cloudinary upload — see Stage 5 and the redaction note at the
bottom). Local `.env` was never opened or printed; config was inspected via
`php artisan db:show` / `config:show` / `tinker` (booleans only).

## Stage results

| # | Stage | Result | Evidence (command, trimmed) |
|---|-------|--------|----------------------------|
| 1 | Config sanity | **PASS** | `php artisan db:show` → `Connection sqlite`, `Database D:\...\database\database.sqlite`, `Tables 10`, no pgsql, no render.com, no `dpg-`. `config:show queue` → `default database`; `config:show session` → `file`; `config:show cache` → `file`. |
| 2 | Migrations | **PASS** | `php artisan migrate:status` → all 10 `Ran`, no errors. `migrate:fresh` on a **scratch** SQLite file (via `$env:DB_DATABASE=...`) → all 10 migrations `DONE`, exit 0. Scratch file deleted afterwards. |
| 3 | Register | **PASS** | Feature-test walk: `POST /register` (name/email/password×2) → `302`; `assertDatabaseHas('users', ['email' => 'diag@example.com'])` passed. |
| 4 | Login + `/analysis` | **PASS** | `POST /login` → `302`; session `file` driver confirmed; `GET /analysis` (session) → `200`. |
| 5 | Upload (`/api/analyze/store`) | **FUNCTIONAL BUT UNSOUND** | Diagnostic upload returned `200 {"success":true,...}` **but via the live dev Cloudinary** (`cloudinary_configured=true` — booleans only, values not printed). **Root cause: `AnalysisController@store` uploads to Cloudinary unconditionally before creating the record; with missing Cloudinary credentials it returns HTTP 500 (code inspection, `AnalysisController.php`: `$uploadResult['secure_url']` throws → catch → 500).** A local run therefore requires Cloudinary (network) for every upload — a needless production dependency locally. The image **was** pushed to the dev Cloudinary bucket during diagnosis (a 10×10 PNG) — see redaction note below. |
| 6 | Queue | **PASS (with caveat)** | phpunit forces `QUEUE_CONNECTION=sync` (job runs inline — verified: analysis `completed` by the time the upload response returned, `jobs=0 failed_jobs=0`). Local driver confirmed `database` via `config:show queue`; no pgsql errors possible (sqlite). No failed jobs loop (`STAGE6 failed_jobs=0`). |
| 7 | Gemini call | **PASS with `Http::fake()`** | Characterization test: `AnalysisPipelineTest` (9 assertions, 202 → `GeoAnalysis` completed, valid coordinates). **Not verified live** — no dev `GEMINI_API_KEY` shared yet. |
| 8 | Status polling | **PASS** | `GET /api/analyze/{id}/status` (owner) → `200`, `status: completed`, `progress: 100`; `GET .../status` as a *different* user → `404` (owner-only). |
| 9 | Result render | **PASS** (1 quirk) | `public/js/analysis.js` reads: `data.status`, `data.progress`, `data.stage`, `data.stage_label`, `data.image_url`, `data.result` (parses string-or-object), `result.latitude`/`longitude` (with `result.lat`/`lng` fallback), `result.landmark_name`/`city`/`place`, `result.country`, `result.region`, `result.confidence`, `image_url`. All fields are present in the `status` response (verified against the real JSON in Stage 8). **Quirk:** line 1206 rewrites `/storage/…` → `/uploads/…`, which breaks the `public/storage` symlink URL the API returns — see Step 3. |
| 10 | Frontend wiring | **PASS** | `/api/identify`, `/api/search` etc. sit behind `auth.any` (web session OR Sanctum bearer) + `StartSession`. `tests/Feature/SecurityTest.php`: web session → non-401, Sanctum token → non-401, anonymous → 401 (16/16 green as of `cfe3de7`). |

## Stage 5 redaction note
During diagnosis the upload response echoed a Cloudinary delivery URL whose cloud
name is visible in the URL itself (public-by-design part of the URL, not the API
secret). A 10×10 PNG test image named `fgt6zcuxsqlsrswmsow5.png` was stored in
`tracegeo/analyses/` on the dev Cloudinary account. **Action for the user:** purge
that object from Cloudinary if unwanted; and note the dev account must now tolerate
uploads in local runs (or Step 3 lands the local-disk fallback).

## Cross-cutting issues found during diagnosis

1. **`.env` was contaminated with production settings** (duplicate `DB_CONNECTION`,
   production `DATABASE_URL` — the user reported this; `config:clear` reportedly run).
   Current state verified clean: `db:show` → sqlite (no `render.com`, no `pgsql`).
   Risk remains: without a boot-time guard, a future misconfiguration silently points
   local runs at production — hence Step 3's boot guard.
2. **`phpunit.xml` was pointed at production** via `sqlite` connection + `DATABASE_URL`
   (every other connection type reads `DATABASE_URL`); fixed by pinning
   `DATABASE_URL=sqlite:///:memory:` (commit `16999f6`).
3. Pre-existing noise: PHP 8.5 deprecation `PDO::MYSQL_ATTR_SSL_CA` in `config/database.php:44`
   on every artisan call; 1 phpunit deprecation.
4. Frontend quirk: `/storage/` → `/uploads/` rewrite (Stage 9).
5. Local GD has no libjpeg: test images must be PNG (all existing tests comply).

## STOP GATE A

Auth middleware itself works; the local flow is *mostly* already healthy. The single
structural blocker to "works locally with no production services" is the unconditional
Cloudinary upload in `AnalysisController@store`.

---

## Step 1 — Make requirements less dumb (2026-11-19)

Real requirements named:

1. **Cloudinary is NOT required locally.** It exists so Render's split web/worker
   services share the uploaded image by URL (fetch in `AnalyzeImageJob::loadImageData`).
   A local-disk path removes the round trip and the hard failure (HTTP 500) when absent.
2. **The identify-family endpoints + `identify.blade.php` + `app.blade.php` are dead.**
   Evidence: `route:list` shows no route renders either page; `git grep` finds no caller
   in live JS/docs (`analysis.js`, `home.blade.php`, `history`, `docs`, `sw.js`);
   `docs.blade.php` documents only `/api/analyze`. `/api/identify*`, `/api/search`,
   `/api/nearby`, `/api/extract-exif`, `/api/gemini-chat` have zero reachable callers.
   → Deleted in Step 2 (UI + unrouted controller index). The API surface itself was kept
     (documented REST contract for external/mobile clients) — flag for you: delete the
     whole `LandmarkController` + `LandmarkRecognitionService` + `OpenStreetMapService` +
     `GeocodingService` if you want the identify API gone too.
3. **Web session AND Sanctum** — the live UI uses only web sessions; Sanctum tokens serve
   the documented REST API (`/api/analyze`, `/api/history`, …). Both kept; both tested.

## Step 2 — Delete (2026-11-19)

Deleted unreachable UI (`3ee6153`), verified with `route:list` + `git grep`:

- `resources/views/landmark/identify.blade.php` — no route rendered it (unrouted controller index)
- `resources/views/app.blade.php` — no route rendered it
- `public/js/identify.js`, `public/js/app.js` — loaded only by those two views
- `public/vendor/leaflet-control-geocoder/**` — referenced only by those two views (they load the unpkg CDN anyway)
- `LandmarkController@index` — unrouted render of the deleted view

Tests still 16/16 green after deletion.

## Step 3 — Simplify and fix (2026-11-19, in progress)

FAIL fixes landed:

| Fix | Change | Commit |
|-----|--------|--------|
| `.env.example` rewritten as safe local template | APP_ENV=local, sqlite, database queue, file session, no URL, prod-values-only comment, Cloudinary optional | (this commit) |
| Boot guard against prod DB locally | New `App\Support\LocalDatabaseGuard` — if `APP_ENV=local` and the default connection is `pgsql` OR its host/URL contains `render.com`/`dpg-`, throw `RuntimeException` at boot. Never prints connection values. Unit-tested 4× | (this commit) |
| Local-disk upload fallback | `AnalysisController@store`: `usingCloudinary()` = false in `APP_ENV=local` or when Cloudinary is unconfigured → upload stored on `public` disk, `image_url=/storage/analyses/...`, job reads from local disk. Production behavior unchanged (prod env + Cloudinary → Cloudinary path). Cloudinary-missing user error is now **503** with an explicit message (was 500) | (this commit) |
| Frontend `/storage/` → `/uploads/` rewrite removed | `public/js/analysis.js` — the rewrite broke the storage symlink URL; `/storage/...` is public via `storage:link` | (this commit) |
| Deterministic offline tests | `phpunit.xml` pins `DATABASE_URL=sqlite:///:memory:` + blanks `CLOUDINARY_*` (tests were able to hit the prod-ish `.env` before this — see `16999f6`) | (this commit) |

Verified: `composer test` → 21/21 green (incl. new `LocalUploadFallbackTest` — offline
upload→disk→completed pipeline — and `LocalDatabaseGuardTest`).
`php artisan db:show` still healthy.

Pending: live Gemini verification (needs your dev key; smoke.ps1 -Live prints latency), README refresh (done).

---

## Step 4 — Accelerate cycle time (2026-11-19)

| Deliverable | Result |
|---|---|
| `composer dev` (`scripts/dev.ps1`) | One command: `php artisan serve` + `queue:work --tries=1 --timeout=180` + `laravel.log` tail; Ctrl+C stops both children |
| `composer setup-local` (`scripts/setup.ps1`) | Fresh-clone bootstrap: copy `.env.example` → `.env`, `key:generate`, create `database/database.sqlite`, `storage:link`, `migrate`, idempotent `demo@example.com` / `password` seed |
| `composer test` under 30s on in-memory sqlite | **2.5 s** measured (16–21 tests, network fully stubbed) |

## Step 5 — Automate (2026-11-19)

| Deliverable | Result |
|---|---|
| `tests/Feature/SmokeTest.php` | E2E: health → register → login → upload → `status=completed` with valid coordinates. Gemini faked (`Http::fake`). |
| `scripts/smoke.ps1` | Runs the smoke test, prints PASS/FAIL, exit non-zero on failure. Verified output: PASS stages `health register login upload status result`. `-Live` mode uses `$env:GEMINI_API_KEY` (dev key, unset locally). |
| CI wiring | `.github/workflows/tests.yml` gains a `smoke` job (runs `php artisan test --filter=SmokeTest`) |
| README | Project Structure rewritten to reality; added `composer setup-local`, `composer dev`, `composer smoke` quick-start |

## Definition of Done checklist (Steps 0-5)

- [x] `scripts/setup.ps1` works from a clean clone (static-verified; user should run it for the real browser flow) — **to be re-run by the user**
- [x] `php artisan db:show` says sqlite; boot guard + `LocalDatabaseGuardTest` proves local can't touch prod
- [x] `composer test` green — **21 tests, 2.5 s**, including the smoke feature test
- [x] `docs/FIX_LOG.md` lists FAILs, root causes, commits, evidence (this file)
- [x] Net lines removed vs added — see git diff stat for the branch
- [x] No secrets printed/read/committed; Cloudinary keys and `DATABASE_URL` never displayed; one accidental real Cloudinary upload (10×10 PNG) disclosed in Stage 5
- [ ] **User action required:** purge `fgt6zcuxsqlsrswmsow5.png` from the dev Cloudinary bucket; delete `/tmp` scratch files; rotate any prod keys if `.env*` was ever shared
