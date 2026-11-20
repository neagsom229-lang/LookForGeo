# TraceGeo Production-Grade Plan (ULTRA PROMPT v3)

## MISSION

TraceGeo: a logged-in user uploads a photo, a queued job asks Gemini where it was taken, and the app shows city, country, coordinates, confidence and a map.
The local flow now works end to end with a real Gemini call (about 27 s per image). The goal now is **ultra professional**: safe to deploy, fast, honest about confidence, observable, and automated. Measured, not claimed.

## HARD RULES (never break)

1. **Never open, print or quote any `.env*` file or any secret.** Redact to 6 characters. Only key names.
2. **No requests to production** (`onrender.com`) except the read-only `scripts/probe-prod.ps1` that I approve. No POST bodies, no credentials, no load.
3. No `pdo_pgsql` locally. Local DB is SQLite. If any output shows `render.com`, `dpg-` or `pgsql`, stop.
4. PowerShell 5.1 syntax, ASCII-only scripts. Run `scripts/Lint.ps1` after editing any `.ps1`.
5. Every claim needs evidence: the command and its real, trimmed, redacted output. No "fixed" without a passing test or a reproduced run.
6. Small commits on a branch per phase. **Never push to `main`.** Commit everything in logical groups before starting (36+ files are currently uncommitted).

## MUSK'S 5 STEPS (order is the rule)

1. **Make requirements less dumb:** every requirement gets an owner and a number.
2. **Delete** what no requirement needs.
3. **Simplify/optimize** only what survived.
4. **Accelerate** cycle time (user-visible and developer).
5. **Automate** last, and only the simplified process.

## FACTS FROM MY LATEST RUN (verify, don't assume)

- **Live Gemini worked:** analyses 4 and 5 completed, each ~27 s (Gemini ~25 s, then Cloudinary ~2 s in the critical path). Both results were "Golden Gate Bridge, confidence 100" from a 13 KB thumbnail: **overconfident**.
- **The same image was uploaded twice and analyzed twice** (27 s each). No result cache.
- **`composer dev` still dies at exactly 300 s** ("exceeded the timeout of 300 seconds"), once while job 6 was mid-Gemini-call. Job 4 sat pending ~3 minutes until a worker was restarted, then recovered correctly.
- `GeminiService initialized` is logged on **every status poll** (log noise). Config still `timeout 60, retries 2, max_total 120`.
- `config/queue.php` database `retry_after` = 90 s, but a job may run up to 120-180 s. **Retry_after must exceed the job timeout** or a second worker can run the same job.
- Original code had `throttle` on API routes but none on the web upload route `POST /api/analyze/store`. Verify; each upload costs a paid Gemini call.
- **Production `lookforgeo.onrender.com` runs old `main` (53 commits behind):** anonymous `POST /api/identify` returns 422 (reachable, no auth), anonymous `GET /api/search` returns 500. The old leaked keys are in `main`'s history.
- Earlier fix prompt items not evidenced in my logs: Gemini key in header plus redaction of `key=` in logs/DB/API errors, `disableProcessTimeout`, single retry layer with budget, models in config, smoke with empty Cloudinary, lazy GeminiService.

## PHASE A: Ship the security fix safely (STOP gate)

1. Commit all pending work in logical commits (security, config, tests, docs, formatting separate). Show `git status` clean.
2. **Split a hotfix branch** `hotfix/auth-gates` from `origin/main` containing ONLY: auth on `/api/identify`, `/identify-gps`, `/extract-exif`, `/nearby`, `/search` (and `/api/test-apis` removal), IDOR ownership scoping on status/results, SSRF re-check, gitleaks. Smallest diff that closes the public cost-bearing endpoints. Tests prove: anonymous returns 401 (no Accept header), cross-user returns 404.
3. Write `docs/RELEASE.md`: order of operations (rotate secrets, hotfix PR, CI green, merge, deploy, run `scripts/probe-prod.ps1`, rollback plan = Render "rollback to previous deploy").
4. `scripts/probe-prod.ps1` (read-only): GET `/api/health`, GET `/api/search` anonymous must be 401, HTML contains no `AIza`/`AQ.` patterns, response headers include CSP and no stack trace. Exit non-zero on failure.
5. Tell me exactly which secrets to rotate and where (Gemini, Maps keys, Cloudinary, Render Postgres password, plus restrict Postgres external access to my IP or internal-only). You cannot do this for me.

**STOP: show me the hotfix diff stat and the release checklist.**

## PHASE B: Finish what is still open (evidence for each)

1. **Secrets in errors:** Gemini key sent only in header `x-goog-api-key`; one central sanitizer redacts `key=...`, `AIza...`, `AQ....` in exceptions, logs, `GeoAnalysis.error` and API JSON; users see a generic message. Tests prove no key in log file, DB, or API JSON.
2. **`composer dev` survives:** prepend `Composer\Config::disableProcessTimeout`; startup refuses if another serve/worker exists; cleanup kills the process tree even on abnormal exit.
3. **One retry layer, with a budget:** per-request timeout 30 s, job budget 90 s, at most one retry on primary, then one fallback model, never call a model with under 15 s left, remove `release(45)`. Failure visible in under 2 minutes with a Retry button and a stage label ("Waiting for AI service, retry n").
4. **Queue correctness:** set `DB_QUEUE_RETRY_AFTER` greater than the job timeout; add `analyses:reap-stuck` (analyses in `processing` longer than N minutes become `failed` or are re-queued); dev.ps1 runs it on an interval; add a failed-job message path to the UI.
5. **Models in config:** `GEMINI_MODEL`, `GEMINI_FALLBACK_MODEL`; confirm requests send no `temperature/top_p/top_k` (deprecated on Gemini 3.x) and use the lowest sensible thinking level for extraction. `smoke.ps1 -Live` prints HTTP status and seconds per model, key never printed.
6. Smoke runs with `CLOUDINARY_URL` empty and asserts no Cloudinary call. Lazy-resolve GeminiService (no per-poll construction, no per-instantiation INFO log).
7. `composer test` under 30 s (slow process-spawning tests go in a `slow` group run by smoke/CI).

## PHASE C: Requirements, then delete (Musk 1 and 2)

Write `docs/REQUIREMENTS.md` v2 with numbers, each owned by me:

- p50 time-to-result, p95 time-to-result, max failure visibility time, cost per analysis, daily cap per user, accuracy target (see Phase E).
- For every route/service/model/table/JS file: who uses it, evidence (file:line), keep/delete. The `Landmark` model/`landmarks` table: prove whether the live pipeline uses it (cross-reference in the Gemini enhancer) before any deletion; if used, keep.

Delete only with grep evidence. Net lines must go down.

## PHASE D: Speed (Musk 4). Target p50 <= 12 s, p95 <= 40 s

Measure first (`smoke -Live` x 10 images), then in this order, stopping when the target is met:

1. **Cache by sha256(image bytes) per user** (and global for identical bytes if privacy policy allows): repeat upload returns instantly with zero Gemini cost.
2. **Move Cloudinary upload out of the critical path:** mark `completed` right after Gemini, upload in a follow-up job; result page uses the local/CDN URL when ready.
3. **Downscale before sending:** client-side resize to max 1024 px long edge (keep EXIF GPS parsed client or server before resize), server fallback resize. Smaller payload, faster inference.
4. Try the lowest thinking level, and a Flash-Lite class model for the first pass if accuracy holds (decide by the eval in Phase E, not by guess).
5. Polling backoff already exists; add `queued_seconds` hint; consider SSE only if polling cost matters.

## PHASE E: Quality and honest confidence (the part that makes it professional)

1. `php artisan geo:eval`: 30+ labeled images (licence notes in `tests/fixtures/eval/labels.csv`), live and mock modes. Report median error km, % within 1 / 25 / 200 / 750 / 2500 km, latency p50/p95, failure rate, **calibration** (stated confidence vs actual accuracy per bucket). Save JSON so regressions can be diffed.
2. **Stop trusting the model's self-reported confidence** (it said 100 on thumbnails). Compute a confidence from checkable signals: EXIF GPS agreement, coordinates actually inside the stated country/city (reverse geocode), number of independent visual cues returned, image resolution, model agreement between primary and fallback on disagreement. Show "why" evidence in the UI and a "low confidence" state.
3. Return top-3 candidates when confidence is below a threshold.
4. Wire `geo:eval` into CI in mock mode and a manual workflow in live mode.

## PHASE F: Reliability, cost control, observability

- **Cost guard:** per-user throttle on the web upload route, per-user daily quota, global `GEMINI_DAILY_LIMIT` kill switch with a clear message. Test each.
- Structured JSON logs in production, request ID on every log line, no emoji in prod logs, no secrets.
- Health: `/api/health` checks DB, queue backlog age, Gemini reachability (cached 60 s). Alert-ready failed-job count.
- Evaluate (do not blindly add) `laravel/pulse` or `spatie/laravel-health` for monitoring; Sentry-compatible error reporting. Check license, last release date, Laravel 12 support first; prefer copying a pattern over a new dependency.

## PHASE G: Security hardening (beyond hotfix)

Secure cookies and HSTS in production, session fixation checks, upload validation by magic bytes and max dimensions, SSRF re-test, CSP from `APP_URL` (already done: keep), privacy note on EXIF GPS handling and a retention/delete policy, Cloudinary signed uploads, dependency audit in CI.

## PHASE H: Automate (Musk 5)

CI (PHP 8.2 + 8.5): install WITH dev deps, Pint, Larastan (level 5, ratchet up), tests, `composer audit`, gitleaks, PowerShell lint, mock smoke, mock eval. Dependabot. Deploy workflow: after merge, Render deploy, then `probe-prod.ps1`, auto-notify on failure. Preview/staging service if Render plan allows.

## PHASE I: Product polish (only after A to H)

Every state has a next action (pending, processing, failed, retry, empty history). Clear error copy. Mobile layout check. Accessibility basics (labels, focus, contrast). Result page: map, confidence with explanation, share/export.

## OPEN-SOURCE LEARNING (as evidence for decisions, not decoration)

Review `docs/OPEN_SOURCE_REVIEW.md` items you adopt or reject, with license, last commit, why. Candidates: Larastan, Pest, Rector, Pulse, spatie/laravel-health; for evaluation method, the published geolocation benchmarks (GeoCLIP, PIGEON, OSV-5M) for metric definitions only. If you cannot verify a project exists and is maintained, say so.

## DEFINITION OF DONE (measured)

- [ ] Hotfix merged and `probe-prod.ps1` passes on production; old secrets rotated (I confirm)
- [ ] p50 <= 12 s, p95 <= 40 s on 10 live images; repeat upload under 1 s
- [ ] No key/secret in any log, DB row or API response (tests prove it)
- [ ] `composer dev` runs for 30+ minutes without dying; stuck analyses reaped automatically
- [ ] `geo:eval` baseline report committed; confidence is calibrated (no "100" on low-quality input)
- [ ] CI green in under 5 minutes; deploys are followed by an automatic probe
- [ ] Net lines of code down; docs match code

## REPORT FORMAT (after every phase)

1. Table: item, PASS/FAIL, evidence (command + redacted output)
2. What changed (paths, commit hashes) and what was deleted
3. Numbers (latency, test time, lines) before and after
4. What you could not verify
5. What you need from me
