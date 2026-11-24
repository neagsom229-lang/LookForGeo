# Privacy, EXIF and retention (Phase G)

## What we store

- **Account data**: name, email, password hash (`users`).
- **Analyses**: uploaded image (local disk in dev, Cloudinary in production),
  the Gemini result JSON, stage/progress, timestamps and an optional `image_hash`
  (sha256 of the image bytes) used only for your own repeat-upload cache.
- **EXIF GPS**: `AnalyzeImageJob::extractMetadata()` reads GPS coordinates from
  the *stored original* image when present and passes them to the calibrated
  confidence calculator. EXIF GPS is **not** sent to Gemini as prompt text and
  is **not** stored as a separate column; it lives only in the image file and
  in the transient job metadata used for that one analysis.
- **Logs**: no image bytes, no EXIF, no API keys. `SecretLogProcessor` redacts
  `key=`, `AIza…`, `AQ…` from every message and context, including production
  JSON logs.

## Google Gemini tier and data use

Google's **free tier** may use submitted prompts and images to improve its
products. TraceGeo therefore requires a **billed Gemini tier in production**;
the free tier must not be used for real user photos. This is also recorded in
`.env.example` as "Production must use a billed Gemini tier." Verify the tier
on the Google AI Studio billing page before enabling analysis in production.

## Who sees the image

- You (the owner) and the services required to produce a result:
  Cloudinary (image hosting) and Google Gemini (image analysis).
- Results are owner-scoped: `GET /api/analyze/{id}/status`,
  `GET /api/results/{id}`, `POST /api/analyze/{id}/retry` and `DELETE`
  all return 404 for another user's id. The sha256 cache is keyed by user id,
  so identical bytes from different users never share a result.

## Cloudinary uploads are signed

`AnalysisController@store()` uses the Cloudinary SDK with
`cloud_name`, `api_key` **and** `api_secret` from server-side config. There is
no unsigned preset and no client-uploaded signature: the upload request is
signed by the server. See `app/Http/Controllers/AnalysisController.php` and
`app/Jobs/AnalyzeImageJob.php`.

## Retention and deletion

- **Retention target**: 90 days. An `analyses:reap-stuck` command fails stuck
  processing rows so they do not linger forever; the 90-day purge job can be
  scheduled separately if you require automatic expiry.
- **User-initiated delete**: `DELETE /api/analyze/{id}` removes the database
  row, deletes the local file when present, and dispatches
  `DeleteCloudinaryImageJob` to remove the Cloudinary asset asynchronously.
- **Right to erasure**: delete the account and all analyses through the
  application; no image bytes are kept in logs or caches after deletion.

## Cookies and transport

- Production session cookies are `Secure` (`SESSION_SECURE_COOKIE` defaults to
  true when `APP_ENV=production`), `HttpOnly`, `SameSite=Lax`.
- Production responses carry HSTS `max-age=31536000; includeSubDomains`
  (only over HTTPS).
- CSP `connect-src` is derived from `APP_URL`, never a hardcoded host.
