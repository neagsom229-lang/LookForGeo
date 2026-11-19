# Security Incident — Secret Exposure in Git History (Phase 0)

**Date:** 2026-11-19
**Repo:** TraceGeo / LookForGeo
**Severity:** Critical (secrets were committed and pushed to a **public** repository)

---

## 0. Executive summary

This repository was **public**, and several production secrets were committed to
it and pushed. Because git history is permanent (and forks keep old history),
the only reliable remediation is **rotation of the exposed credentials**.
History rewriting (e.g. `git filter-repo`) is optional and does not remove the
credentials from existing clones/forks.

As of the last audit (2026-11-19), **the current working tree and `HEAD` contain
no live secrets** (verified with `git grep` across all tracked files for the
known secret prefixes and connection strings). The exposure is therefore
**historical**.

---

## 1. What was exposed, in which commits

### 1.1 Google Gemini / Google Maps API key — `AIzaSy…` (exposed via `render.yaml`)

- **Commit:** `b28ae6c` — "Fix render.yaml: add actual env vars and storage:link for worker" (2026-08-29)
- **File:** `render.yaml`
- **Key:** `GEMINI_API_KEY` — value starts with `AIzaSyB…` (Google API key, Gemini/Maps class)
- **Removed in:** commit `3f306e9` — "Fix render.yaml: add actual env vars and storage:link" (2026-08-29)
- **Status:** ❌ Compromised — the value was in a file committed to a public repo.

### 1.2 Google Maps Embed API key — `AIzaSy…` (exposed via Blade view)

- **Commit:** `b5dff6b` — "Fix: Google Maps Embed config, Street View integration, and cleanup" (2026-08-28)
- **File:** `resources/views/analysis.blade.php` (line ~1506)
- **Key:** `GOOGLE_MAPS_EMBED_KEY` — value starts with `AIzaSyD8hB…`
- **Removed in:** commit `1dc3603` — "Revert to config-based Google Maps key (remove hardcoded key)" (2026-08-28)
- **Status:** ❌ Compromised — the value was hardcoded in a tracked Blade file.

### 1.3 Cloudinary credentials — `cloudinary://…` (exposed via `render.yaml`)

- **Commits:** `b5dff6b`, `1931693`, `3f306e9`, `b557e05` (2026-08)
- **File:** `render.yaml` — `CLOUDINARY_URL` contained the full URL form with
  cloud name, API key and API secret (e.g. `cloudinary://<cloud_name>:<api_key>:<api_secret>@<cloud>`).
- **Removed in:** commit `b557e05` — "Fix render.yaml indentation, add dump-autoload, remove hardcoded secrets; add debug-jobs route" (2026-08)
- **Status:** ❌ Compromised — credentials were committed.

### 1.4 Render PostgreSQL credentials — `postgresql://…` (exposed via `render.yaml`)

- **Commits:** from the first production release (`e8ab711` "Switch to Render PHP environment", 2026-08) through `edd5df9` ("Priority 0+1: scrub DB creds, clean .gitignore, untrack cache", 2026-08)
- **File:** `render.yaml` — `DATABASE_URL` contained the full Postgres connection
  string, including the password:
  `postgresql://lookforgeo_db_user:<redacted-6-chars>…@dpg-da3h4ogu01pc738n6ing-a:5432/lookforgeo_db`
- **Removed in:** commit `edd5df9`
- **Status:** ❌ Compromised — the password was committed; the commit that removed it
  still remains in history, and history rewriting was not performed.

### 1.5 Secret scan summary

| # | Secret type | File / location | Commit introduced | Commit removed | Value prefix shown |
|---|-------------|-----------------|-------------------|----------------|---------------------|
| 1 | Google Gemini / Maps API key | `render.yaml` → `GEMINI_API_KEY` | `b28ae6c` | `3f306e9` | `AIzaSyB…` |
| 2 | Google Maps Embed API key | `resources/views/analysis.blade.php` → `GOOGLE_MAPS_EMBED_KEY` | `b5dff6b` | `1dc3603` | `AIzaSyD8hB…` |
| 3 | Cloudinary URL (key+secret) | `render.yaml` → `CLOUDINARY_URL` | `b5dff6b` … `3f306e9` | `b557e05` | `cloudinary://…` |
| 4 | Render Postgres URL (incl. password) | `render.yaml` → `DATABASE_URL` | `e8ab711` … | `edd5df9` | `postgresql://…` |

**Redaction note:** secret values are intentionally NOT reproduced here.
Only the first characters needed to identify the credential are shown.

---

## 2. What is clean now

- Current tracked files contain no occurrences of:
  - `AIzaSy…` (Google API keys)
  - `postgresql://…` / `dpg-…` (Render Postgres credentials)
  - `cloudinary://…` (Cloudinary credentials)
  - known JWT / Stripe / OpenAI / Resend / Postmark key prefixes
- `.env` is **not** tracked (only `.env.example` is); local `.env` holds the real
  values only on the developer machine.
- Current `render.yaml` uses `sync: false` for all secrets (Render dashboard).

---

## 3. Required manual remediation (owner action — cannot be done from code)

These steps **must** be performed by the repository owner:

1. **Rotate the Google Gemini API key** (Google AI Studio / GCP console). Regenerate
   and update `GEMINI_API_KEY` in the Render dashboard. Check Google Cloud abuse /
   quota usage logs.
2. **Rotate the Google Maps keys** (Embed + API). Apply an **HTTP-referrer
   restriction** on the Embed key and an **API restriction** (e.g. Maps Embed API only)
   on all Google keys. Regenerate as needed.
3. **Rotate the Cloudinary credentials** (cloud name / API key / API secret) and
   update `CLOUDINARY_URL` in the Render dashboard.
4. **Reset the Render PostgreSQL password** (Render dashboard → Database → Reset
   password / regenerate). Update `DATABASE_URL`.
5. **Review Google Cloud / GCP usage** for signs of abuse (unexpected quota consumption,
   requests from unknown sources).

---

## 4. Detection tooling added in Phase 0

- `.gitleaks.toml` — gitleaks ruleset for this repo.
- `.github/workflows/gitleaks.yml` — CI scan (push/PR).
- `.githooks/pre-commit` + `git config core.hooksPath .githooks` — local pre-commit scan.

---

## 5. Optional but recommended

- **History rewrite** via `git filter-repo` (or BFG) to purge the secrets from all
  commits. **Note:** rotation is what actually fixes the exposure; forks and clones
  retain the old history regardless.

---

## 6. STOP GATE

Phase 0 is complete pending your review. Manual rotation steps above are yours to run.

**Reply `continue` to proceed to Phase 1 (Requirements).**
