# Runbook

## Deploy smoke (GitHub Actions: `Deploy and smoke`)
- Workflow: `.github/workflows/deploy.yml`, job `deploy-and-smoke`.
- **Wait for deployed commit**: polls `GET /up`, reads `X-App-Commit`, expects `${GITHUB_SHA:0:7}`. Retries up to 10 min. Timeout = FAIL.
- **Post-deploy smoke /api/health**: retries `GET /api/health` up to 5x. PASS only on HTTP 200 and JSON `{"status":"operational"}`. HTTP 503 or non-JSON = FAIL (status only, no secrets).
- On failure, workflow opens/updates a GitHub issue titled `Deploy smoke failed for <sha7>`.
- Read result in Actions run logs; check both steps before declaring deploy healthy.

## Rotate `HEALTH_ADMIN_TOKEN`
1. Run locally: `powershell -NoProfile -ExecutionPolicy Bypass -File scripts/rotate-token.ps1`
2. Script generates 72 hex chars, copies to clipboard only, never prints token.
3. Render Dashboard > Service > Environment > `HEALTH_ADMIN_TOKEN` > paste value > Save.
4. Redeploy the service. Old token is rejected after deploy (`hash_equals` check).
5. Verify: `GET /api/health` with old token returns minimal public response; new `X-Admin-Token` returns diagnostics.

## Render log lines to search
- `Migrations failed after retries` — entrypoint gave up; container should exit non-zero.
- `php artisan migrate --force` / `attempt=` — migration retry progress in entrypoint.
- `X-Request-ID` / request log JSON with `request_id` — correlate a failing request.
- `/api/health` 503 or `status":"degraded"` — readiness degraded; inspect admin diagnostics with valid token.
- `DEPLOY` / smoke issue text in GitHub Actions — CI-side deploy/smoke failure.

## Roll back a deploy
1. Render Dashboard > Service > Deploys > select last known-good deploy > **Rollback**.
2. Confirm `GET /up` returns 200 and `X-App-Commit` matches the rolled-back commit.
3. Confirm `GET /api/health` returns 200 `{"status":"operational"}`.
4. If migration/schema changed incompatibly, do not rollback blindly; restore DB/schema compatibility first or deploy a forward fix.
5. Record incident, root cause, and whether token/secret rotation is required.

## Safety
- Do not paste tokens/secrets into logs, PRs, or chat. Use `scripts/rotate-token.ps1`.
- `/api/health` public response is minimal `{status}`; admin details require valid `X-Admin-Token`.
