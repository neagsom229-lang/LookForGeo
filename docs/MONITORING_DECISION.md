# Monitoring package decision (Phase F) — evidence first

Verified 2026-11-20 from Packagist package metadata and the GitHub releases API.
No new dependency was added. This document records why.

| Project | Verified facts | Decision |
|---|---|---|
| `laravel/pulse` | MIT. Latest stable `v1.11.0`, published 2026-11-17. Requires PHP `^8.2`, `illuminate/* ^12 \|\| ^13`, plus `livewire/livewire`, `laravel/sentinel`, `doctrine/sql-formatter`. | **Not adopted.** Laravel 12 compatible and maintained, but adds a Livewire dashboard, new tables and several runtime dependencies. Our need is a health endpoint and structured logs, both already implemented in ~150 lines of first-party code. |
| `spatie/laravel-health` | MIT. Latest stable `1.42.0`, published 2026-11-16, requires PHP `^8.2`, `illuminate/* ^11 \|\| ^12 \|\| ^13`. Its `dev-main` now requires PHP `^8.4` and `illuminate/* ^13`. | **Not adopted.** Stable release supports Laravel 12, but the package pulls cron-expression, notifications and termwind for checks/UI we do not use. The first-party `SystemHealthService` covers DB, queue backlog age, Gemini reachability (60 s cache) and failed-job count. |
| `getsentry/sentry-laravel` | Latest release `4.31.0`, published 2026-11-19; actively maintained. | **Not adopted.** Requires an external Sentry account/DSN secret and has per-event cost/quotas. There is no user requirement for off-site aggregation yet. If you want it later, add the DSN as a secret and keep the central `SecretSanitizer` processor in the chain. |

## Pattern copied instead of a dependency

Per the hard rule "prefer copying a pattern over a new dependency", Phase F added:

- `app/Services/SystemHealthService.php` — DB PDO check, queue backlog + oldest-job age,
  Gemini reachability cached 60 s (no key in the URL), failed-job count.
- `app/Logging/RequestIdProcessor.php` + `app/Http/Middleware/RequestId.php` —
  `X-Request-ID` on every response and `extra.request_id` on every log line.
- `app/Logging/NoEmojiProcessor.php` + `config/logging.php` `production` channel —
  one JSON object per line on stderr for machine parsing.
- `app/Logging/SecretLogProcessor.php` (existing) — `key=`, `AIza`, `AQ` redaction
  still runs before the JSON formatter, so structured logs cannot leak secrets.

Re-evaluate if you need a dashboard, alerting rules or off-site error grouping.
