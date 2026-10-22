# Deployment checklist (live server)

Things to check when putting Atlas Drive on a server (Hostinger or other), and to keep doing after.

## Server
- [ ] PHP 8.4+ with extensions: **gd**, **exif**, **fileinfo**, pdo_mysql, mbstring, openssl.
- [ ] `upload_max_filesize` and `post_max_size` at least **12M** (uploads are limited to 10 MB in the app).
- [ ] HTTPS only (SSL certificate), `APP_ENV=production`, `APP_DEBUG=false`.
- [ ] The production domain in `SANCTUM_STATEFUL_DOMAINS` and `SESSION_DOMAIN`.
- [ ] `CACHE_STORE=database` (or redis) — not `array`: the "a 2FA code works only once" lock and the login limits live in the cache.
- [ ] Cron every minute: `php artisan schedule:run` (overdue checks, cleanups).
- [ ] The server can reach `https://api.pwnedpasswords.com` (leaked-password check for new passwords; only a 5-character prefix of the password's SHA-1 fingerprint is sent).
- [ ] **Keep PHP updated** (security + timezone rules: if Morocco changes its clock, see CLAUDE.md).

## ⚠️ APP_KEY — never lose it, never change it
- All uploaded files (ID cards, passports, contracts...) **and the two-step verification secrets** are **encrypted with `APP_KEY`** from `.env`.
- **If APP_KEY is lost or changed, every file becomes unreadable forever, and everyone with two-step verification is locked out.**
- Keep a copy of `.env` (with APP_KEY) somewhere safe and **separate from the backups** (e.g. a password manager).
- Never run `php artisan key:generate` on the live server once it has data.

## Backups (daily)
- [ ] The **database** (MySQL dump).
- [ ] The **files**: `storage/app/private/` (already encrypted).
- [ ] Stored **off the server** (another provider / cloud storage), and test a restore once.
