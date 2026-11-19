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
