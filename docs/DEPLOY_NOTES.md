# Deploy notes — `fix/local-workflow` pre-deploy gate

Branch: `fix/local-workflow`. Base: `origin/main`. Nothing here has been pushed
or deployed. This document is the handover for the deploy owner.

## 1. New and changed environment variables

Secrets must be set in the Render dashboard (`sync: false` in `render.yaml`).
Values below are the non-secret defaults committed in `render.yaml`.

| Variable | Web | Worker | Kind | Purpose |
|---|---|---|---|---|
| `DATABASE_URL` | sync:false | sync:false | secret | Postgres connection |
| `GEMINI_API_KEY` | sync:false | sync:false | secret | Gemini inference |
| `GOOGLE_MAPS_EMBED_KEY` | sync:false | sync:false | secret | Map embed |
| `CLOUDINARY_URL` | sync:false | sync:false | secret | Image hosting |
| `CACHE_STORE` | `database` | `database` | default | Shared cache/invalidations |
| `SESSION_DRIVER` | `database` | `file` | default | Worker has no sessions |
| `QUEUE_CONNECTION` | `database` | `database` | default | Shared job queue |
| `DB_QUEUE_RETRY_AFTER` | `240` | `240` | default | Must exceed 180 s job timeout |
| `GEMINI_TIMEOUT` | `50` | `50` | default | Per-request timeout |
| `GEMINI_TOTAL_BUDGET` | `110` | `110` | default | Shared retry/fallback budget (fallback skipped when <15 s remain) |
| `GEMINI_RETRIES` | `1` | `1` | default | One primary retry, then fallback |
| `GEMINI_CIRCUIT_FAILURES` | `3` | `3` | default | Failures before the breaker opens |
| `GEMINI_CIRCUIT_OPEN_SECONDS` | `60` | `60` | default | Breaker open window; 429 `Retry-After` can extend it |
| `GEMINI_DAILY_PER_USER` | `50` | `50` | default | Per-user daily quota |
| `GEMINI_DAILY_LIMIT` | `0` | `0` | default | Global kill switch, 0 = off |
| `ANALYSIS_REAP_MINUTES` | `10` | `10` | default | Reap-on-read window |
| `GEMINI_CONFIDENCE_REVERSE_GEOCODE` | `true` | `true` | default | Country-consistency signal |
| `GEMINI_HEALTH_PROBE` | `true` | `true` | default | 60 s cached reachability check |
| `GEMINI_THINKING_LEVEL` | `low` | `low` | default | Lowest sensible Gemini 3.x level |
| `GEMINI_MODEL` | `gemini-3.5-flash-lite` | `gemini-3.5-flash-lite` | default | Primary; change only after probing |
| `GEMINI_FALLBACK_MODEL` | `gemini-3.1-flash-lite` | `gemini-3.1-flash-lite` | default | Fallback; configurable via env |
| `GEMINI_ESCALATION_MODEL` | `""` | `""` | default | Optional second pass; empty = off |
| `GEMINI_ESCALATE_BELOW` | `60` | `60` | default | Escalate when calibrated confidence is below this |
| `GEMINI_ESCALATION_TIMEOUT` | `25` | `25` | default | Bounded timeout for the single second pass |

**Reported live behaviour (not measured here):** the lite models
(`gemini-3.5-flash-lite`, `gemini-3.1-flash-lite`) intermittently return 503.
No latency figures are asserted in this repository: no live before/after
measurements have been supplied. This variability is why hedging exists: one
model being slow must not hold up the other.

**Why hedging exists:** `GEMINI_HEDGE_AFTER=18` gives the primary an eighteen-second
window. If it has not answered, the fallback starts immediately and the first
valid response wins; the loser is ignored. A primary that merely timed out in
the hedge window is **not** counted as a breaker failure. Only genuine 429 /
503 / timeout failures of a model count toward its breaker. Hedges are
disabled automatically when the global daily cap is more than 80 % used, to
avoid doubling spend near the limit.

**Per-model live results (fill from `gemini:probe --repeat=10 --models=...` and
`geo:eval --live --image=<real photo>`; never invent these):**

| model | HTTP | ms min/median/max | category | thinking level | live 200 recorded? |
|---|---|---|---|---|---|
| gemini-3.5-flash-lite | (pending) | (pending) | (pending) | low | no |
| gemini-3.1-flash-lite | (pending) | (pending) | (pending) | low | no |

Only replace `no` with `yes` after a real HTTP 200 is observed. `minimal`
thinking stays disabled until that row says `yes`.

Implementation note: this is a **staged "primary timeout + fallback"** hedge,
not two simultaneously in-flight HTTP sockets. The primary request is bounded
to the hedge window; when that window expires its elapsed time is recorded and
the fallback request starts. This is deliberate: a true concurrent Guzzle
promise race could not be made deterministic with Laravel's HTTP fakes, so the
code and `Gemini primary timeout + fallback` log line name the behaviour
honestly. `analyses:stats` reports the abandoned-primary count and elapsed
time so the 18 s default can be tuned from evidence.

**Cloudinary and critical path:** the follow-up `UploadAnalysisImageJob`
moves Cloudinary **out of the request path for local runs only**, where the
worker and web process share the local disk. Production (`render.yaml`) still
uploads to Cloudinary in `AnalysisController@store()` *before* dispatch,
because the separate worker container has no shared local file. The follow-up
job is a safety net there, not the primary upload path. Do not claim a
production latency improvement from this change.

**No latency claims without live data:** no p50/p95 numbers are stated here.
`analyses:stats` prints token p50 and `output_share` so you can measure once
live data exists. The single full schema always requests coordinates and
candidates. The 1024 px downscale only runs for images
larger than `GEMINI_COMPRESS_THRESHOLD_BYTES` (default 1 MB). Smaller images are
sent **byte-for-byte unchanged**, so `image_bytes` in the log equalling the
original file size is expected, not proof that downscaling is broken. For
larger images `image_bytes` is the length of the downscaled/re-encoded payload.

**Automatic requeue:** when both models fail with `overload` or `timeout`, the
job is released once after 8 s with `stage_label = "Retrying"`. It never
requeues `bad_request`, never more than once (`ANALYSIS_AUTO_REQUEUE=false`
disables it), and is skipped when the elapsed time plus the 8 s delay would
exceed the 60 s total user-wait cap. If the second attempt also fails the row
becomes `failed` with the usual generic message. Job-level `tries=2` is baked
into the queue payload so this `release()` is not killed by `--tries=1`;
`RequeueWorkerTest` proves the second run with the database driver and worker
semantics. `model_used` stays null on failure, and `analyses:stats` excludes
failures from the per-model latency table (failures get their own count and
category breakdown).

**Calibration bug (0 % for famous landmarks) — proposal only, weights UNCHANGED:**
`ConfidenceCalibrationMatrixTest` prints an inputs-to-score table with realistic
outputs and no EXIF. Two consistent answers score 0 %: Statue of Liberty when
reverse geocoding reports the wrong country (-20) on a low-res image (-10), and
Phnom Kulen with tied candidates (-15) on a low-res image (-10). A third finding
was that Nominatim nests country under `address.country`, which the reader
ignored, so the signal was silently 0.

FIXED (weights unchanged): the reader now accepts real Nominatim payloads,
prefers `address.country_code` (ISO 3166 alpha-2), and normalises model-side
names such as `USA` / `United States of America` / `UK` / `Cambodia` to codes
before comparing. NominatimCountryTest covers real-shaped responses; the
matrix row for Statue of Liberty with a Nominatim payload rises from 35 % to
55 % because the country signal is now detected, not because weights changed.

Still proposed (NOT applied), pending operator review:
- cap country mismatch at -10 and low-resolution/tied-candidate penalties at
  -5 each, so no single weak signal can erase a named landmark;
- add a +10 "specific landmark named with in-country coordinates" baseline;
- never display 0 % when the answer is internally consistent - floor at 15.

**Slim schema removed:** the opt-in slim prompt/schema is gone from code,
config, env, tests and docs. Evidence: real analyses 20-21 (slim on) stored
`latitude`/`longitude` null and `coordinate_sources=0`, while 9-19 (full
schema) had `coordinate_sources=1`. The single full schema always requests
coordinates and candidates; `ResultShapeTest` verifies an end-to-end completed
analysis stores them.

**Why country_consistency was "unavailable" on real analyses:** the signal is
computed inside `GeminiResultEnhancer` → `ConfidenceCalculator::checkCountry`.
It needs **model coordinates and a country name**; with null coordinates (the
slim-schema analyses) it returns `unavailable` before calling Nominatim. It is
also `unavailable` when `GEMINI_CONFIDENCE_REVERSE_GEOCODE=false`, or when
reverse geocoding is skipped by the 1 req/s limit or returns rate_limit /
timeout / bad_response. The reverse-geocoded country was never persisted
before; results now store `geocode_attempt` (coordinates used, outcome
category, reverse country/code) and `analyses:explain` prints it. A new
`forward_geocode_distance` signal records the distance between the named place
and the model coordinates with **points = 0** until real-photo `geo:eval`
justifies a weight. `NOMINATIM_CONTACT` supplies the policy-required contact
in the User-Agent; no personal address is committed.

**Real-photo `geo:eval` datasets:** use
`php artisan geo:eval --live --labels=<csv> --images=<dir>`. `--labels` and
`--images` must be given together. The CSV header is
`file,landmark,city,country,latitude,longitude,license` (`licence` is also
accepted). **Real photos must stay outside this public repository**: EXIF GPS
and private property are a privacy risk, so keep them in an untracked directory
and pass it via `--images`. `.gitignore` excludes `tests/fixtures/eval/real/`,
`**/real-photos/`, `**/real_photos/`, `**/eval-real/` and
`storage/app/eval-real/`. Only the synthetic fixtures under
`tests/fixtures/eval/images/` are committed. `file` is relative to `--images`; `latitude`/`longitude` are the
ground truth in decimal degrees; `license` must name the rights holder/source
(e.g. `Photo by A, CC BY 4.0`). Rows whose licence mentions `synthetic` are
not counted as real photos, and the command warns when fewer than 30 real
photos are present. The report adds a `dataset: real-photo` field and an
accuracy table by predicted-confidence band (`n`, within 25 km, within
200 km, median error km) in addition to the overall median error. Without
`--live`, the fake returns each label as the prediction and the command warns
that accuracy numbers are not meaningful.

**Local vs production worker:** `scripts/dev.ps1` uses
`php artisan queue:listen --tries=1 --timeout=180` locally because `listen`
boots a fresh worker process per job, so code edits take effect immediately.
Production keeps `queue:work` (a long-running daemon that loads code once),
where deploys restart workers. Do not switch local dev to `queue:work`: it
silently runs stale code after every edit.

Tuning: lower `GEMINI_HEDGE_AFTER` (e.g. 2) if p95 is dominated by slow
primaries; raise it (or set `0`) if duplicate requests are too costly. Use
`composer latency:live -- --iterations=10` and `php artisan gemini:probe
--repeat=10` to compare before changing. Use `php artisan analyses:stats
--hours=24` to see hedge win rate and per-model processing percentiles.

**Choosing models (no guessing):** run `php artisan gemini:models` to list
flash models that support `generateContent`, then
`php artisan gemini:probe --models=gemini-3.5-flash-lite,gemini-3.1-flash-lite` to see
HTTP status, seconds and any sanitized Google error/quota details for the real
payload shape. Pick a model only if it returns HTTP 200 (no 400 from the
thinking/schema config). Set the chosen ids as `GEMINI_MODEL` and
`GEMINI_FALLBACK_MODEL`. The current defaults (`gemini-3.5-flash-lite` /
`gemini-3.1-flash-lite`) are interim choices based on the reported hangs and
overloads of 3.8/3.7/3.6 flash; they remain in place until real probe numbers
are supplied. They are starting points, not a guarantee.

`gemini:probe --repeat=N` reports min/median/max seconds per model, and
`--thinking=<level>` experiments with a thinking override. `minimal` is never
sent by default: to enable it for one model, first run a live probe that
returns HTTP 200, then record here a line containing `200`, the exact model id
and `minimal`, e.g.:

```
LIVE 200 gemini-3.5-flash-lite thinkingLevel=minimal (2026-11-20)
```

Only after that line exists will `gemini:probe --thinking=minimal --models=...`
accept the override for that model.

`SESSION_SECURE_COOKIE` is not set: `config/session.php` defaults it to true
when `APP_ENV=production`. HSTS is emitted by middleware only over HTTPS.

## 2. Migration and deploy order

- New migration: `2026_11_20_000000_add_image_hash_to_geo_analyses.php` adds
  `geo_analyses.image_hash` (nullable string, 64) plus a composite
  `(user_id, image_hash)` index.
- The migration is **additive and nullable**. The worker can start against the
  previous schema; it does not read the new column.
- The web container's `entrypoint.sh` runs `php artisan migrate --force` in a
  retry loop **before** Apache starts. `healthCheckPath: /api/health` is set on
  the web service, so Render only marks the deploy live after migrations and
  HTTP are healthy.
- Order: deploy web first (it migrates), then the worker. If they deploy in
  parallel, the worker is still safe because nothing it reads changed shape.
- CI proves the upgrade path in the `pgsql-upgrade` job: migrate `origin/main`
  on `postgres:16`, then check out the PR commit, migrate again and run the full
  suite against Postgres via `phpunit.pgsql.xml`.

## 3. Behaviour changes to expect

- **Pending-first uploads**: `POST /api/analyze/store` creates the row as
  `pending` (10%) and dispatches the job; the old synchronous "completed on
  upload" response is gone. The UI polls `/api/analyze/{id}/status`.
- **Reap on read**: a `pending`/`processing` row older than
  `ANALYSIS_REAP_MINUTES` (default 10) is failed when its status is read,
  with the message “This analysis waited too long for a worker and was stopped.
  Use Retry analysis to try again.” No Render scheduler is required. The
  `analyses:reap-stuck` command remains for manual/bulk use.
- **Cost guard**: 50 analyses per user per day (configurable) plus a 10/min
  per-user burst throttle on the upload route. Identical repeat uploads by the
  same user are cached by sha256, return instantly and do not consume quota.
  `GEMINI_DAILY_LIMIT=0` leaves the global kill switch off; set a number to
  enable it.
- **Failure classification**: 429 quota, 503 overload, timeout, 400 request
  and other failures map to five distinct generic user messages and stage
  labels (no provider detail). 429 is never retried on the same model; its
  `Retry-After` is carried into the result and can extend the breaker window.
- **Circuit breaker**: after 3 quota/overload failures the shared breaker
  opens for 60 s and subsequent analyses fail fast with the overload message.
  A successful analysis resets the counter. Configure with
  `GEMINI_CIRCUIT_FAILURES` / `GEMINI_CIRCUIT_OPEN_SECONDS`.
- **Confidence**: the model's self-reported number is never used as the score.
  Calibrated confidence from checkable signals is **capped at 95**, below 50 is
  “low confidence”, and up to three alternatives are shown.
- **Removed routes**: `/api/identify-gps`, `/api/extract-exif`, `/api/nearby`
  had no controller methods and returned 500 for authenticated callers; they
  now return 404.
- **Docker**: `--ignore-platform-reqs` was removed from the build; the lock was
  verified to install on `php:8.2` without it.
- **Logs**: production emits JSON on stderr with a request id, no emoji and
  secrets redacted. HSTS + Secure cookies in production.

## 4. Live gate (run by the deploy owner)

Do not run this from a developer machine against production data. Place the
real key in the application's `.env` once (it is already there on the deploy
host) — **do not type it into the shell**. Artisan reads `.env` itself. Then
run:

```powershell
php artisan gemini:probe
```

Pass criteria:

- Both configured models (`gemini-3.5-flash-lite`, `gemini-3.1-flash-lite`) print
  `HTTP 200` and `OK`.
- **No HTTP 400.** A 400 would mean `generationConfig.thinkingConfig.thinkingLevel`
  (`low`) was rejected.
- The key never appears in the output (the command prints only model, status,
  seconds).

Optional deeper checks: `composer eval:live -- --limit=10` and
`scripts/measure-latency.ps1 -Live -Iterations 10`.

## 5. Rebase plan after the hotfix merges

1. `hotfix/auth-gates` contains the minimal Phase A security fix based on
   `origin/main`. Let the owner merge it to `main` first.
2. Rebase the cumulative branch onto the updated `main`:
   `git fetch origin && git rebase origin/main fix/local-workflow`.
3. Expect conflicts in `routes/api.php`, `routes/web.php`,
   `app/Http/Controllers/AnalysisController.php`, `bootstrap/app.php`,
   `.gitleaks.toml`, `phpunit.xml`, `.github/workflows/tests.yml` and
   `scripts/probe-prod.ps1` — the hotfix has narrower variants of the same
   changes.
4. Resolution rule: keep the hotfix's security intent (auth on `/identify` and
   `/search`, 401 JSON without `Accept`, no `/api/test-apis`, ownership checks,
   SSRF guard, narrow gitleaks allowlists), and keep the cumulative branch's
   later features (cache, confidence, cost guard, reaper, CI). Drop the three
   method-less routes again if the hotfix reintroduces them.
5. Re-run the full gate: `composer test:all`, `composer analyse`, `composer test:slow`,
   `scripts/Lint.ps1`, `gitleaks detect`, `scripts/smoke.ps1`, `composer eval`.
6. Never push directly to `main`.

## 6. Rollback

1. Render dashboard → the affected service → **Rollback to previous deploy**
   (web and worker separately; roll back web first if both are unhealthy).
2. Keep rotated secrets — do **not** restore a leaked key.
3. The `image_hash` column is additive and nullable: rolling back code to the
   previous deploy does not require dropping it. If a full schema rollback is
   required later, drop the index and column manually.
4. Verify with the read-only probe:
   `powershell -NoProfile -ExecutionPolicy Bypass -File scripts/probe-prod.ps1`.
   It sends GET requests only, never credentials or bodies.
5. Re-check `GEMINI_DAILY_LIMIT` and `GEMINI_DAILY_PER_USER`; if a bad quota
   caused the rollback, set `GEMINI_DAILY_LIMIT=0` before redeploying.
6. Open an issue with the failed deploy URL, the probe output (redacted) and
   the first relevant log line (request id, no secrets).
