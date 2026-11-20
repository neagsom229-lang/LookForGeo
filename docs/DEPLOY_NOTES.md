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
| `GEMINI_TIMEOUT` | `30` | `30` | default | Per-request timeout |
| `GEMINI_MAX_TOTAL_TIME` | `90` | `90` | default | Shared retry/fallback budget |
| `GEMINI_RETRIES` | `1` | `1` | default | One primary retry, then fallback |
| `GEMINI_DAILY_PER_USER` | `50` | `50` | default | Per-user daily quota |
| `GEMINI_DAILY_LIMIT` | `0` | `0` | default | Global kill switch, 0 = off |
| `ANALYSIS_REAP_MINUTES` | `10` | `10` | default | Reap-on-read window |
| `GEMINI_CONFIDENCE_REVERSE_GEOCODE` | `true` | `true` | default | Country-consistency signal |
| `GEMINI_HEALTH_PROBE` | `true` | `true` | default | 60 s cached reachability check |
| `GEMINI_THINKING_LEVEL` | `low` | `low` | default | Lowest sensible Gemini 3.x level |
| `GEMINI_MODEL` | `gemini-3.6-flash` | `gemini-3.6-flash` | default | Primary |
| `GEMINI_FALLBACK_MODEL` | `gemini-3.5-flash` | `gemini-3.5-flash` | default | Fallback |

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

Do not run this from a developer machine against production data. With the
real `GEMINI_API_KEY` in the environment, run:

```powershell
$env:GEMINI_API_KEY = '<your key>'
php artisan gemini:probe
```

Pass criteria:

- Both configured models (`gemini-3.6-flash`, `gemini-3.5-flash`) print
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
