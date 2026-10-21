# Deployment checklist (live server)

Things to check when putting Atlas Drive on a server (Hostinger or other), and to keep doing after.

## Server
- [ ] PHP 8.4+ with extensions: **gd**, **exif**, **fileinfo**, pdo_mysql, mbstring, openssl.
- [ ] `upload_max_filesize` and `post_max_size` at least **12M** (uploads are limited to 10 MB in the app).
- [ ] HTTPS only (SSL certificate), `APP_ENV=production`, `APP_DEBUG=false`.
- [ ] The production domain in `SANCTUM_STATEFUL_DOMAINS` and `SESSION_DOMAIN`.
- [ ] Cron every minute: `php artisan schedule:run` (overdue checks, cleanups).
- [ ] **Keep PHP updated** (security + timezone rules: if Morocco changes its clock, see CLAUDE.md).

## ⚠️ APP_KEY — never lose it, never change it
- All uploaded files (ID cards, passports, contracts...) are **encrypted with `APP_KEY`** from `.env`.
- **If APP_KEY is lost or changed, every file becomes unreadable forever.**
- Keep a copy of `.env` (with APP_KEY) somewhere safe and **separate from the backups** (e.g. a password manager).
- Never run `php artisan key:generate` on the live server once it has data.

## Backups (daily)
- [ ] The **database** (MySQL dump).
- [ ] The **files**: `storage/app/private/` (already encrypted).
- [ ] Stored **off the server** (another provider / cloud storage), and test a restore once.
