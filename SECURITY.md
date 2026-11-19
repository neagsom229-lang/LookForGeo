# Security Policy

## Reporting a vulnerability

**Do not open a public issue for security vulnerabilities.**

Instead, please report them privately to the maintainers (see the contact on the
repository profile: https://github.com/neagsom229-lang/LookForGeo).

Include:

- A description of the vulnerability and its impact
- Steps to reproduce or a proof of concept
- The affected endpoint, file, or dependency

You will receive an acknowledgement as soon as the report is triaged.

## Scope

- Cost-bearing endpoints are authenticated and throttled (session auth or
  Sanctum bearer tokens; see `routes/api.php`).
- Analysis reads/writes are scoped to their owner.
- Outbound image fetchers validate destination hosts and block private IP ranges.
- Secrets must never be committed to git — `gitleaks` runs on every push and on
  staged commits locally (`core.hooksPath .githooks`).
- **Never print, log, paste or share secret values in chat, logs, PRs or issues.**
  When a secret must be referenced, redact it to at most the first 6 characters
  (e.g. `AIzaSy…`) and otherwise refer to it by key name only (`GEMINI_API_KEY`).
- No credential backup files (`.env`, `.env.*`, `*.bak`) may exist in the
  repository, its history, or be committed. They must stay local and
  untracked. If one appears, delete or quarantine it immediately.
- Full handling of any past credential exposure is tracked in
  `docs/SECURITY_INCIDENT.md`.

## Supported versions

The `main` branch is the supported version. This repository does not backport
fixes.
