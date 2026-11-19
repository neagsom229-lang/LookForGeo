# FIX PROMPT — Get the TraceGeo workflow working end to end

Save as `docs/FIX.md`, then tell the agent: "Read docs/FIX.md and execute it. Stop at each STOP gate."

---

## GOAL
On a Windows laptop (PowerShell 5/7, PHP 8.5, SQLite, no pdo_pgsql), this flow must work locally with no production services:

register → login → upload image on /analysis → queued job runs → Gemini result stored → status goes pending → processing → completed → map + result shown.

Then make it provable with one command, so it can never silently break again.

## HARD SAFETY RULES (read first, never break)
1. **Never open, print, cat, or quote the contents of any `.env*` file.** To check config, use `php artisan config:show database` or `php artisan db:show` only, and redact any value to 6 characters.
2. **Never connect to a production service.** Do not enable `pdo_pgsql`. Do not use any `DATABASE_URL`. If any command output shows `render.com`, `dpg-`, or `pgsql`, stop and tell me.
3. Use only dev credentials in the local `.env`. If a key is needed, tell me the variable name and I will paste it myself.
4. PowerShell syntax only (no `&&`, `grep`, `touch`, `cp`). Use `;`, `Select-String`, `Copy-Item`.
5. Every claim needs evidence: the command you ran and its real, trimmed output. If you could not run something, say so. Do not say "fixed" without a passing test or a reproduced run.
6. One branch `fix/local-workflow`, small commits. Do not push or merge.

## KNOWN FACTS (from my machine; verify, do not assume)
- The local `.env` was found **contaminated with production settings**: duplicate `DB_CONNECTION` lines (sqlite and pgsql) and a production `DATABASE_URL`. `php artisan migrate` then tried to reach the Render Postgres host. I was told to delete the Postgres lines and run `config:clear`.
- With that contamination, the queue worker looped on `could not find driver (pgsql)`. Sessions and queue were pointing at the wrong database, so login and upload could not work.
- `artisan serve` shows `/`, `/login`, `/register` loading, but I never reached `/analysis`. I do not know whether the register/login POSTs succeeded.
- The original upload path sends the image to Cloudinary first and returns HTTP 500 if that fails. A local `.env` with no Cloudinary credentials will break uploads.
- Branch `phase-0-secrets` has 29 commits with 16 tests passing. Earlier refactors (GeminiService split) once broke every analysis and were caught only by a new characterization test. Do not trust old "complete" claims.
- A local tool probes `/health` and `/v1/models` on port 8000 every ~30s. Ignore it.
- Local GD may lack libjpeg: use PNG for test images.

---

## METHOD: MUSK'S 5 STEPS, IN ORDER

### Step 0 — Diagnose with evidence (no fixes yet)
Walk the flow one stage at a time and record PASS/FAIL with evidence in `docs/FIX_LOG.md`. Stop at the first FAIL, root-cause it, then continue.

| # | Stage | How to check |
|---|-------|--------------|
| 1 | Config sanity | `php artisan db:show` must say sqlite. `php artisan config:show queue`, `session`, `cache` must show non-prod drivers. No `pgsql`/`render` anywhere. |
| 2 | Migrations | `php artisan migrate:status`: all Ran, no errors. Check that a fresh `migrate:fresh` on an empty SQLite file succeeds (the migrations were edited and deleted during cleanup; add-column migrations that reference foreign keys must work on SQLite). |
| 3 | Register | Feature test or `curl.exe` against the running app: `POST /register` with CSRF returns 302 and creates a user. |
| 4 | Login and session | After login, `GET /analysis` returns 200 (not a redirect). Confirm session driver works. |
| 5 | Upload | Authenticated `POST /api/analyze/store` with a small PNG returns success with an id. Check the Cloudinary dependency (see Step 1). |
| 6 | Queue | A `jobs` row is created, and `php artisan queue:work --once` processes it. No pgsql errors. |
| 7 | Gemini call | With `Http::fake()` in tests, and then once live with a dev key if I provide one. Confirm structured output parses and validates. |
| 8 | Status polling | `GET /api/analyze/{id}/status` moves pending → processing → completed. Owner-only (other users get 404). |
| 9 | Result render | `/analysis` JS (public/js/analysis.js) can read the result shape the API returns: field names must match. Check against the real JSON, not the code comments. |
| 10 | Frontend wiring | Browser calls to `/api/identify` and `/api/search` with a session cookie return non-401 (the `auth.any` middleware on stateless api routes). Fix by moving the routes into the web group or enabling stateful sessions if they fail. |

**STOP GATE A:** show me the PASS/FAIL table and root causes. Wait for "continue".

### Step 1 — Make requirements less dumb
Answer in `docs/FIX_LOG.md`, naming a real requirement:
- Is Cloudinary required to run locally? Why does the worker need to re-download the image from a URL? A local-disk path in dev removes a network dependency and a failure point.
- Are `/api/identify`, `/api/identify-gps`, `/api/extract-exif`, `/api/nearby`, `/api/search` and the pages that call them reachable from the UI at all? If no route renders `identify.blade.php` or `app.blade.php`, they are dead.
- Do we need both web-session auth and Sanctum token auth right now?

### Step 2 — Delete
Delete whatever Step 1 shows is not needed (dead pages, dead routes, dead JS). Prove with grep/route:list. Commit per deletion. Fewer paths means fewer breaks.

### Step 3 — Simplify and fix
Fix each FAIL from Step 0 at the root cause, smallest change:
- `.env.example` must be a safe, complete local template: `APP_ENV=local`, `DB_CONNECTION=sqlite`, `QUEUE_CONNECTION=database`, `SESSION_DRIVER=file` (or database), no DATABASE_URL, empty placeholders for secrets, and a comment saying production values go only in the Render dashboard.
- Add a guard: on boot, if `APP_ENV=local` and the DB host contains `render.com` or the connection is `pgsql`, throw a clear exception ("refusing to run locally against a production database"). Add a test.
- Local storage fallback: when `APP_ENV=local` or Cloudinary is not configured, store the upload on the `public` disk and have the job read it from disk. Production behavior unchanged.
- Fix any frontend/API field mismatches found in stage 9.
- Make a clear, user-visible error (not a 500) when a required service is missing.

### Step 4 — Accelerate cycle time
- Add a single dev command: `composer dev` that starts `php artisan serve`, `php artisan queue:work --tries=1 --timeout=180` and tails `laravel.log` together (use `concurrently` or a PowerShell script `scripts/dev.ps1`). One command, three processes.
- Add `scripts/setup.ps1`: copy `.env.example` if missing, generate key, create `database\database.sqlite`, migrate, create a demo user. Safe to run twice.
- Make `composer test` run in under 30 seconds on SQLite in memory.

### Step 5 — Automate
- `scripts/smoke.ps1` (and a matching PHPUnit feature test) that performs the whole flow: register, login, upload a PNG fixture, run the job synchronously with `Http::fake()` for Gemini, assert status completed with valid coordinates. It must print PASS/FAIL per stage and exit non-zero on failure.
- Add it to the CI workflow.
- Optional live mode `scripts/smoke.ps1 -Live` that uses a dev `GEMINI_API_KEY` from the environment and a real small image, and prints latency.

---

## DEFINITION OF DONE
- [ ] `scripts/setup.ps1` on a clean clone, then `composer dev`, then I can register, log in, upload an image and see a result with a map, with no errors in `laravel.log`
- [ ] `php artisan db:show` says sqlite; the boot guard test proves local cannot touch prod
- [ ] `composer test` green, including the smoke feature test, in under 30s
- [ ] `docs/FIX_LOG.md` lists every FAIL, its root cause, the commit that fixed it, and the evidence
- [ ] Net lines of code went down or stayed flat; list what was deleted
- [ ] Nothing secret was printed, read, or committed

## REPORT FORMAT AFTER EACH GATE
1. PASS/FAIL table (updated)
2. Root cause per failure (one line each)
3. What changed (file paths and commit hashes)
4. Evidence (real command output, trimmed and redacted)
5. What I still could not verify
6. What I need from you (for example: paste a dev key)

Start with Step 0 now.
