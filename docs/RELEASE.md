# Release runbook: auth-gates hotfix

Branch: `hotfix/auth-gates` (based on `origin/main` at `7142d1d`).

This release closes anonymous access to the cost-bearing API endpoints, removes
`/api/test-apis`, scopes analysis polling/results to the owner, and hardens the
image URL fetcher against SSRF. It is intentionally small. It does not include
the larger local-workflow refactor.

Do not deploy until every item in "Pre-flight" is true. Never push directly to
`main`; use a pull request.

## What changes in production

- `POST /api/identify`, `POST /api/identify-gps`, `POST /api/extract-exif`,
  `GET /api/nearby`, and `GET /api/search` require a web session or a Sanctum
  bearer token. Anonymous requests get `401` JSON, even without an `Accept`
  header.
- `GET /api/test-apis` and `TestController` are removed (`404`).
- `GET /api/analyze/{id}/status` and `GET /api/results/{id}` only return rows
  owned by the authenticated user. Another user's ID returns `404`.
- `/api/fetch-image` rejects private, loopback, link-local, reserved, CGNAT,
  and non-HTTP(S) targets. Redirect targets are checked before they are
  followed, and exception details are no longer echoed to the client.
- CI runs PHPUnit and Gitleaks on this branch.

## Known limitations

- `POST /api/identify-gps`, `POST /api/extract-exif`, and `GET /api/nearby`
  point at controller methods that do not exist on this base. They are now
  auth-gated, so anonymous callers get `401`; an authenticated caller still
  gets the pre-existing `500`. The web UI does not call them. Fixing or
  removing them is a follow-up, not part of this security hotfix.
- `/api/search` is mapped to the existing `searchPlace` handler. The old
  `search` action name never existed.
- `gitleaks` allowlists two historical Google Maps key commits after rotation.
  See "Known historical exposure".

## Pre-flight

1. Confirm the PR diff contains only the intended hotfix files:
   `git diff --stat origin/main...hotfix/auth-gates`
2. Confirm the local test suite is green:
   `php vendor/bin/phpunit`
3. Confirm no secret is staged or committed:
   `git status --short`
4. Have Render dashboard access for both the web service and the worker.
5. Have provider-console access for every secret being rotated.
6. Take a Render PostgreSQL backup/snapshot before changing database
   credentials. If the plan does not expose manual snapshots, record the
   current connection settings in the password manager instead.
7. Do not run `scripts/probe-prod.ps1` against the old `main` deployment. It
   is expected to fail there because `/api/search` is still public/broken.
8. Local secret files such as `.env` and `.env.prod.bak` are ignored by git but
   still exist on disk. Move any values you still need into a password manager,
   then delete the backup files. Never stage them.

## Known historical exposure

Git history contains two old Google Maps keys:

- `render.yaml` in commit `b28ae6c`
- `resources/views/analysis.blade.php` in commit `b5dff6b` (removed in
  `1dc3603`)

The current working tree does not contain those keys. `.gitleaks.toml`
allowlists only those exact path/commit pairs so CI can scan the rest of the
history. Do not widen the allowlists. Rotate both the embed key and the server
key as described below. Removing the keys from git history requires a
coordinated history rewrite and a force-push; that is out of scope for this
hotfix and must be approved separately.

## 1. Rotate secrets first

Rotate a secret if it has ever appeared in a chat, screenshot, issue, log,
commit, or screen share. Treat `GEMINI_API_KEY` as exposed if there is any
doubt. Rotate `APP_KEY` only when it was exposed; rotating it signs everyone
out and invalidates encrypted cookies.

Record only the first 6 characters of any new value when writing evidence,
for example `GEMINI_API_KEY=ABCDEF...`. Never paste a full value into a PR,
issue, chat, or shell history.

### GEMINI_API_KEY

1. Open Google AI Studio, create a new API key in the production project.
2. Restrict the key to the Generative Language API and, if available, to the
   production project's budget/quota.
3. In Render, open the `LookForGeo` web service, then Environment. Replace
   `GEMINI_API_KEY`. Save and let the service redeploy.
4. Repeat for the `queue-worker` service. Both services must use the same key.
5. Verify `/api/health` and the probe below.
6. Delete the old key in Google AI Studio only after the probe passes.

### APP_KEY

1. Generate a candidate without writing it to `.env`:
   `php artisan key:generate --show`
2. In Render, set `APP_KEY` on both the web service and the worker.
3. If Laravel's `APP_PREVIOUS_KEYS` is in use, move the old key there for the
   transition; otherwise remove the old value after verification.
4. Redeploy both services. All browser sessions and encrypted cookies become
   invalid, so users must sign in again.
5. Verify login, `/api/health`, and an authenticated `/api/identify` request.
6. Remove `APP_PREVIOUS_KEYS` after the rollback window closes.

### Database credentials (Render PostgreSQL)

1. Open the Render PostgreSQL instance. Under Access Control (or Users),
   rotate the application user's password.
2. Copy the new internal connection string. Do not use the public/external URL
   if the services can use the private one.
3. In Render, update `DATABASE_URL` on the web service and the worker. If the
   service uses discrete variables instead, update `DB_PASSWORD` and the other
   affected `DB_*` values.
4. Redeploy both services. Confirm migrations are current and `/api/health`
   returns 200.
5. Under Networking, disable public database access or allow only the Render
   services that need it. If the current plan cannot restrict public access,
   keep a strong unique password and require TLS; record this as a residual
   risk.

### CLOUDINARY

1. In the Cloudinary console, create a new API key/secret or rotate the
   existing one.
2. In Render, update `CLOUDINARY_URL` and, if present,
   `CLOUDINARY_CLOUD_NAME`, `CLOUDINARY_API_KEY`, and `CLOUDINARY_API_SECRET`
   on both services.
3. Redeploy, upload one test image through the authenticated UI, and confirm
   the analysis reaches a terminal state.
4. Delete the old Cloudinary key.

### GOOGLE_MAPS_EMBED_KEY and GOOGLE_MAPS_KEY

1. In Google Cloud Console, create replacement keys.
2. Restrict the embed key by HTTP referrer to
   `https://lookforgeo.onrender.com/*` and to the Maps Embed API.
3. Restrict the server key by API and, where possible, by IP. Render's egress
   IPs may be dynamic on the free plan; if so, restrict by API only and record
   the limitation.
4. Update the variables in Render, redeploy, verify a map embed, then delete
   the old keys.

### Other provider keys

Rotate any of these that are configured and were exposed:
`OPENWEATHER_API_KEY`, `WHAT3WORDS_API_KEY`, `TIMEZONEDB_API_KEY`,
`OPENAI_API_KEY`, `POSTMARK_API_KEY`, `RESEND_API_KEY`,
`SLACK_BOT_USER_OAUTH_TOKEN`, and any `AWS_*` credentials.

For each one: create the replacement in the provider console, update the
variable on both Render services, redeploy, run the probe, then revoke the old
credential. Do not rotate unused keys just to create churn.

## 2. Open the hotfix PR

```powershell
git switch hotfix/auth-gates
git status --short
git log --oneline origin/main..HEAD
git push -u origin hotfix/auth-gates
```

Open a pull request from `hotfix/auth-gates` into `main`. Use a merge commit
rather than a squash so the security, tests, and docs commits remain
reviewable. Require the PHPUnit and Gitleaks checks before merge.

Do not force-push the branch after review starts. Do not push to `main`.

## 3. CI gates

Run these locally before asking for review:

```powershell
php vendor/bin/phpunit
gitleaks detect --source . --config .gitleaks.toml --redact
git add <intended-files>
gitleaks protect --staged --config .gitleaks.toml --redact
git diff --check origin/main...HEAD
composer validate --no-check-publish
```

Do not run `gitleaks detect --no-git` from the repository root: it will scan
your local, git-ignored `.env` files. Use `protect --staged` for the change you
are about to commit.

Expected PHPUnit result for this hotfix: all tests pass. A PHP deprecation
from `config/database.php` on PHP 8.5 (`PDO::MYSQL_ATTR_SSL_CA`) is pre-existing
and does not fail the suite.

`php vendor/bin/pint --test` is clean for the new and rewritten hotfix files.
The rest of the `origin/main` tree has pre-existing style debt, so do not run
Pint across the whole repository in this hotfix.

## 4. Merge

Merge the PR with a merge commit after both checks pass. Do not enable
auto-merge until the secret rotation above is complete.

## 5. Deploy

Render deploys `main` automatically. Confirm both the `LookForGeo` web service
and the `queue-worker` service are on the same commit. If the worker does not
auto-deploy, trigger a manual deploy.

Check the deploy log for:

- `composer install` succeeds without dev dependencies.
- `php artisan config:cache` succeeds.
- No `APP_KEY` or database connection errors.
- The queue worker starts and stays running.

No new migrations are part of this hotfix.

## 6. Production probe

Run the read-only probe:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/probe-prod.ps1
```

The script only sends `GET` requests. It verifies:

- `/api/health` returns 200 with `status: operational`.
- `/api/search` returns 401 without an `Accept` header, with no redirect.
- `/api/history` returns 401 without an `Accept` header.
- `/api/test-apis` returns 404.
- The CSP header is present.
- No `AIza...`, `AQ....`, private-key, or database-URL pattern is reflected in
  a response body.
- No stack trace or framework path is exposed.

A non-zero exit means stop. Do not continue to cleanup.

Optional manual check, still GET-only:

```powershell
curl.exe -sS -o NUL -w "%{http_code}`n" https://lookforgeo.onrender.com/api/search
```

Expected: `401`. Do not send credentials, request bodies, or load to the
production service.

## 7. Rollback

If the probe fails or the UI breaks:

1. In Render, open the service, go to Deploys, and redeploy the last known
   good commit. Do the same for the worker.
2. Alternatively, revert the merge commit through a new PR. Never force-push
   `main`.
3. Keep the rotated secrets in place. Do not restore a leaked key just to roll
   back code.
4. If the failure is an auth/session problem, confirm `APP_KEY` and
   `APP_PREVIOUS_KEYS` on both services before changing code.
5. If the failure is a database connection problem, confirm the new
   `DATABASE_URL` is present on both services and that the database allows the
   service's network.
6. After rollback, the old code will not pass the full probe (public
   `/api/search`), so verify health, login, and error rates instead. Re-deploy
   the hotfix once the cause is fixed.

## 8. Cleanup

- Delete the old provider credentials only after the probe passes.
- Close the hotfix branch after a successful deploy and probe.
- Record the deploy commit, probe output, and redacted secret prefixes in the
  release notes.
- If any secret could not be rotated, open a follow-up issue with the owner
  and the deadline.

## Evidence template

```text
Release commit: <sha>
Web deploy: <sha> at <time>
Worker deploy: <sha> at <time>
PHPUnit: <n tests, n assertions>
Gitleaks: no leaks
Probe: PASS
GEMINI_API_KEY: ABCDEF... (rotated, old key revoked)
APP_KEY: rotated / not rotated (reason)
DATABASE_URL: password rotated / not rotated (reason)
Residual risk: <none / description>
```
