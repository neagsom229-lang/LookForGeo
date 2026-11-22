# Hosting: Render is the only host

This application is deployed **only on Render**. `render.yaml` is the source
of truth for services, env defaults and health checks; `.github/workflows/deploy.yml`
triggers the Render deploy hook and runs the read-only probe. Do not add a
second production host: a split deployment would diverge on secrets, queue
workers and migrations.

## Disconnect other hosts (one-time checklist)

- [ ] **Vercel:** in the Vercel dashboard → *Settings → Git*, disconnect the
      repository (or delete the project). Remove any `VERCEL_*` secrets from
      GitHub Actions and from local `.env` copies. Remove any Vercel deploy
      hook from the repository settings. Confirm no `vercel.json` remains in
      the repo.
- [ ] **Railway:** in Railway → *Settings → Git*, disconnect the repository and
      delete the stale service/environment. Remove `RAILWAY_*` tokens from
      GitHub Actions and local `.env` copies. Remove Railway deploy hooks.
- [ ] Remove any DNS record or custom domain that still points at Vercel or
      Railway; point the production domain only at the Render web service.
- [ ] In GitHub → *Settings → Secrets and variables → Actions*, confirm the
      only deploy secret is `RENDER_DEPLOY_HOOK_URL`.
- [ ] Confirm `README.md` and `docs/` mention no host other than Render (the
      README currently contains no Vercel/Railway references).
- [ ] Confirm the queue worker and web services in `render.yaml` are the only
      long-running app services.

## Secrets to rotate before the next public deploy

Rotate these in their provider dashboards and update the Render dashboard
values (never in git):

| Secret | Provider | Notes |
|---|---|---|
| `GEMINI_API_KEY` | Google AI Studio | Old key may have been exposed in prior logs; replace and delete the old one. |
| `CLOUDINARY_URL` / `CLOUDINARY_API_KEY` / `CLOUDINARY_API_SECRET` | Cloudinary | Rotate the API secret; update the single `CLOUDINARY_URL` if used. |
| `GOOGLE_MAPS_EMBED_KEY` | Google Cloud | Restrict by HTTP referrer to the production domain. |
| `DATABASE_URL` | Render Postgres | Rotate the database password; redeploy both web and worker. |
| `APP_KEY` | Local/Render | Only if it was ever committed; rotating invalidates existing encrypted sessions. |
| `RENDER_DEPLOY_HOOK_URL` | Render | Rotate if the hook URL was shared outside the team. |
| `NOMINATIM_CONTACT` | Render | Non-secret role address required by the Nominatim policy; verify it is set. |

After rotation: redeploy web + worker, run `composer ci` locally, and confirm
`/api/health` reports `schema_ok: true` and `pending_migrations: 0`.

## Production boot requirements

- `NOMINATIM_CONTACT` must be set (`sync: false` in `render.yaml`); the app logs
  a warning at boot in production when it is empty.
- `entrypoint.sh` retries migrations at most 10 times, prints the real
  migration error and exits non-zero so Render shows a failed deploy.
- `/api/health` exposes `started_at`, `pending_migrations`, `schema_ok`,
  `commit` and the dependency checks for the deploy probe.
