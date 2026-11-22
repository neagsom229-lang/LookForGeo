# Release notes — next PR (`agent/gemini-hardening` → `main`)

Nothing here has been pushed or deployed. These notes cover the cumulative
change set on top of `origin/main`. Secrets stay in Render only.

## 1. Database migrations (all additive, nullable, reversible)

| Migration | Adds | Down |
|---|---|---|
| `2026_11_20_000000_add_image_hash_to_geo_analyses.php` | `geo_analyses.image_hash` (string 64, nullable) + index `(user_id, image_hash)` | drops index, then column |
| `2026_11_20_000001_add_analysis_telemetry.php` | `model_used`, `processing_ms`, `failure_category` (nullable) | drops the three columns |
| `2026_11_20_000002_add_analysis_timings.php` | `queue_ms`, `gemini_ms`, `total_ms` (nullable integers) | drops the three columns |

All three are `Schema::table` additions with nullable columns; no data
backfill, no type changes, no dropped rows. Tested against SQLite (fast suite)
and Postgres 16 (`phpunit.pgsql.xml`). Rollback order is the exact reverse of
`up()`. Deploy migrations before the new code reads the columns; telemetry
writes are best-effort and skip missing columns rather than fail analysis.

## 2. New / changed environment variables (defaults)

| Variable | Default | Note |
|---|---|---|
| `GEMINI_API_KEY` | *(empty)* | Required for analysis; secret in Render only |
| `GEMINI_MODEL` | `gemini-3.5-flash-lite` | Primary model (was `gemini-3.6-flash`) |
| `GEMINI_FALLBACK_MODEL` | `gemini-3.1-flash-lite` | Fallback (was `gemini-3.5-flash`) |
| `GEMINI_HEDGE_AFTER` | `18` | Was `10`; stage fallback after 18 s (0 disables) |
| `GEMINI_SLIM_SCHEMA` | `false` | Opt-in; keep full prompt/schema until live eval evidence |
| `GEMINI_IMAGE_ENCODER` | `auto` | `auto`/`jpeg`/`png`; tests force `png` |
| `GEMINI_TOTAL_BUDGET` | `110` | Shared retry/fallback budget (s) |
| `GEMINI_TIMEOUT` | `50` | Per-request timeout (s) |
| `GEMINI_CIRCUIT_FAILURES` | `3` | Per-model breaker threshold |
| `GEMINI_CIRCUIT_OPEN_SECONDS` | `60` | Breaker open window (s) |
| `GEMINI_THINKING_LEVEL` | `low` | `minimal` requires a recorded live 200 |
| `GEMINI_ESCALATION_MODEL` | *(empty)* | Escalation off by default |
| `GEMINI_ESCALATE_BELOW` | `60` | Threshold when escalation enabled |
| `GEMINI_ESCALATION_TIMEOUT` | `25` | Bounded second-pass timeout (s) |
| `GEMINI_TIMEOUT_OVERRIDES` / `GEMINI_THINKING_OVERRIDES` | *(empty)* | Per-model overrides |
| `GEMINI_DAILY_PER_USER` | `50` | Per-user daily analysis quota (0 = off) |
| `GEMINI_DAILY_LIMIT` | `0` | Global kill switch (0 = off) |
| `GEMINI_HEALTH_PROBE` | `true` | 60 s cached reachability probe |
| `ANALYSIS_REAP_MINUTES` | `10` | Reap-on-read window for stuck jobs |
| `ANALYSIS_AUTO_REQUEUE` | `true` | One automatic requeue on overload/timeout |
| `ANALYSIS_REQUEUE_DELAY_SECONDS` | `8` | Delay before that requeue |

Production values are in `render.yaml` as non-secret defaults; secrets (`APP_KEY`,
`DATABASE_URL`, `GEMINI_API_KEY`, `GOOGLE_MAPS_EMBED_KEY`, `CLOUDINARY_*`) are
`set in the Render dashboard` (`sync: false`).

## 3. Behaviour changes

- **Quota:** per-user daily cap (default 50) and global kill switch (0 = off).
  Repeat uploads of identical bytes by the same user return the cached analysis
  at zero Gemini cost; the cache is scoped by `user_id`.
- **Requeue:** when both models fail with `overload` or `timeout`, the job is
  released once after 8 s with `stage_label = "Retrying"`; total wait capped at
  60 s; `bad_request` is never requeued; job-level `tries = 2` overrides the
  worker's `--tries=1`. Permanent failures set `status = failed` and are not
  retried. `model_used` stays null on failure.
- **Hedge:** staged "primary timeout + fallback" after `GEMINI_HEDGE_AFTER=18`
  (not two simultaneous sockets). The winner is recorded in `model_used` and
  shown by `analyses:last` / `analyses:stats`. Only genuine 429/503/timeout
  failures count toward the per-model circuit breaker.
- **Models:** default primary `gemini-3.5-flash-lite`, fallback
  `gemini-3.1-flash-lite`; escalation model empty. Choose models only from live
  probe evidence; no latency numbers are asserted in this repository.
- **Schema/prompt:** full schema is default; slim variant behind
  `GEMINI_SLIM_SCHEMA=false` until `geo:eval --live` before/after accuracy and
  p50 evidence is supplied. Confidence weights are unchanged (country +20/-20,
  visual cues +10/+5, resolution +10/+5/-10, candidate gap +15/+8/-15,
  coordinate sources +10). Country comparison now uses ISO 3166 alpha-2 from
  Nominatim with `league/iso3166` behind the alias normaliser.
- **Ops tools:** `analyses:explain {id}` prints stored signals/factors;
  `analyses:stats` excludes failures from latency and shows failure counts;
  `geo:eval --labels=<csv> --images=<dir>` is the real-photo mode (photos stay
  outside the public repo). Local `composer dev` uses `queue:listen`
  (reloads code per job); production keeps `queue:work`.
- **Quality gates:** `composer ci` = test:all + decoy + pint-changed + analyse;
  pre-push is fast (test + pint-changed, ~10 s). PHPStan runs with
  `--memory-limit=1G` in both the composer script and CI.

## 4. Rollback

1. Revert the merge commit: `git revert -m 1 <merge-sha>` (or roll back the
   branch deploy in Render to the previous release).
2. Restore the previous env defaults in Render, chiefly
   `GEMINI_MODEL=gemini-3.6-flash`, `GEMINI_FALLBACK_MODEL=gemini-3.5-flash`,
   `GEMINI_HEDGE_AFTER=10`, and unset `GEMINI_SLIM_SCHEMA`,
   `ANALYSIS_AUTO_REQUEUE`, `ANALYSIS_REQUEUE_DELAY_SECONDS`,
   `GEMINI_IMAGE_ENCODER`. Secrets themselves are not changed by this PR.
3. Roll back the additive migrations in reverse order:
   `php artisan migrate:rollback --step=3` (drops `queue_ms/gemini_ms/total_ms`,
   then `model_used/processing_ms/failure_category`, then `image_hash` and its
   index). No data is lost by the rollback itself; telemetry/hash values are
   dropped with their columns.
4. Restart workers after code rollback. Older code ignores the extra columns
   until step 3, so a code-only rollback is safe as an immediate mitigation.
5. Do not commit real evaluation photos; keep them outside the repository for
   GPS privacy. Re-run `composer ci` after rollback to confirm the prior state.
