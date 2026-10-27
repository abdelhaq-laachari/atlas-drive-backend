# Merging the Atlas Drive backend into the dashboard project (Materio)

**Who this is for:** a future Claude Code session (or a developer) asked to bring this backend into the
**dashboard project**, so that Atlas Drive becomes **one single project folder** (Laravel + Vue 3 SPA).

**The dashboard project (decided 2026-10-27):**
- Template: **Materio — Free Vuetify Vue.js Laravel Admin Template, TypeScript version**, by ThemeSelection
  (https://github.com/themeselection/materio-vuetify-vuejs-laravel-admin-template-free).
- Location: **`/Users/godfather/Desktop/SiyaRent`** (the TypeScript version; the Laravel app is at its root).
  It has its own git repo (first commit "Materio original").
- **Licence: MIT** (free for a commercial SaaS). Keep the `LICENSE` file / copyright notice in the code. The
  ThemeSelection footer link is *requested*, not required by MIT. Colours, logo and design are ours to change.
  **Never copy code from Vuexy or any other paid template** into this project (licence).
- It ships with **Laravel 11** (`laravel/framework ^11.31`, PHP `^8.2`) and **no backend auth** (no Sanctum):
  its login / register pages are UI only, and its demo data is front-end only.
- Atlas Drive is now built as a **generic SaaS** for Moroccan rental agencies (not customised for one client).
- **Brand: SiyaRent** (decided 2026-10-27). Set `APP_NAME=SiyaRent` in `.env`, and use SiyaRent for the app title,
  logo and template branding (replace the template's name/logo). "Atlas Drive" is only the internal project name.

**Read this whole file before doing anything.** Then read, in this folder:
- `files-manifest.txt` — every file of the backend, marked COPY / MERGE / REGENERATE
- `api-routes-snapshot.txt` — the 78 API routes that must exist after the merge

And in the backend repo: `CLAUDE.md` (business rules + conventions) and `docs/learning-journal.md`
(why things are the way they are — the "Lessons" explain traps already hit).

---

## 0. Golden rules (do not break these)

1. **Work on a copy, never on the only copy.** Fresh git repo for the dashboard project, a commit of its untouched
   state first, then a separate branch `merge-backend`. The backend repo stays untouched (read-only source).
2. **Never commit or push** unless the user asks (project rule). Tell the user when a good commit point is reached.
3. **The backend's behavior must not change.** Its test suite (362+ tests) is the referee: it must pass in the
   merged project with the same results. If a test fails, fix the merge, not the test (unless the user agrees).
4. **Never overwrite a dashboard (template) file blindly.** If a COPY file already exists in the dashboard → STOP, treat it as MERGE,
   and show the user the difference.
5. **Do not downgrade our code** to fit an older Laravel / PHP. Upgrade the dashboard project instead (section 2).
6. **Security first** (the user's standing request): keep agency isolation, Sanctum cookie auth, the
   `EnsureAccountIsActive` / `EnsurePasswordIsChanged` middleware, encrypted files, hidden secrets.
   Template demo auth / demo users / fake data must not stay active.
7. **When unsure, stop and ask the user** — explain in simple words (the user is a junior developer, MERN
   background), with a recommendation.
8. **Report before changing**: after the compatibility check (section 2) and the inventory (section 3), write a
   short report to the user and wait for "go".

---

## 1. Before starting (checklist)

In the **backend repo** (`~/Desktop/atlas-drive-backend` or wherever it is now):
- [ ] `git status` is clean (everything committed). If not, ask the user to commit first.
- [ ] `php artisan test` → all green. Note the exact number of tests (it was **362** on 2026-10-22).
- [ ] Regenerate the manifest and the routes snapshot if the backend changed since 2026-10-22:
      - files: `git diff --name-status b333be1 -- . ; git ls-files --others --exclude-standard`
      - routes: `php artisan route:list --path=api` (compare with `api-routes-snapshot.txt`)
- [ ] Suggest a tag: `git tag pre-dashboard-merge` (the user runs it).

In the **dashboard project** (`/Users/godfather/Desktop/SiyaRent`):
- [ ] The Materio **TypeScript** version, downloaded from the official GitHub repo (latest `main`), `LICENSE` = MIT.
- [ ] `git init` + first commit "Materio original" (ask the user), then branch `merge-backend`.
- [ ] Its own install works first, unchanged: `composer install`, `pnpm install` (or npm), `cp .env.example .env`,
      `php artisan key:generate` (new install only), `php artisan serve` + `pnpm dev` — note anything already
      broken **before** our changes.

---

## 2. Compatibility check — must pass BEFORE merging

Fill this table from the dashboard project (`composer.json`, `composer.lock`, `package.json`, `php -v`, `node -v`)
and report it to the user.

| Item | Backend needs (2026-10-22) | Dashboard has | OK? / action |
|---|---|---|---|
| PHP | **≥ 8.3** (composer `^8.3`); developed and tested on **8.5.11** | | |
| Laravel framework | **13.x** (tested 13.38.0) — uses Laravel 13 model attributes `#[Fillable]`, `#[Hidden]`, `#[Scope]` | Materio free: **11.x** | **Upgrade the dashboard to Laravel 13 first** (Step A0) |
| laravel/sanctum | **^4.0** (tested 4.3.3) | | |
| laravel/fortify | **^1.41** (tested 1.41.0) — 2FA only | | |
| pragmarx/google2fa, bacon/bacon-qr-code | come with Fortify (9.1.0 / 3.1.1) | | |
| laravel/boost (dev) | ^2.10 | | reinstall, don't copy |
| Database | **MySQL 8+** (JSON columns, `CONCAT`, temporary tables in tests); tests use a separate DB `atlas_drive_test` | | SQLite is **not** supported by the test suite |
| PHP extensions | **gd, exif, fileinfo**, pdo_mysql, mbstring, openssl (bcmath NOT needed) | | |
| Node / Vite / Vue | whatever the template requires (the backend has no JS); Materio uses pnpm (npm / yarn also work) | | |

Also check, and report:
- [ ] **Login:** Materio free has **no backend auth**; its login page is UI only (and may route with fake data).
      → Our Sanctum **cookie session** auth is the only auth. The Vue app must call `GET /sanctum/csrf-cookie`
      then `POST /api/login` (see "Auth flow" in section 6). Remove / don't use any fake-login logic.
- [ ] **Users / roles:** our roles are `owner` / `staff` (`App\Enums\Role`) + Policies. If the template has a
      front-end ACL (e.g. CASL), map it to these; **do not add a roles package**.
- [ ] **routes/api.php / demo data:** remove demo routes and fake-data plugins (ask the user) and use ours.
- [ ] **routes/web.php:** the SPA catch-all (`{any}`) must **exclude** `api/*`, `sanctum/*` and `up`
      (otherwise our API answers with the Vue page).
- [ ] **Package manager:** keep the template's (pnpm). Don't mix lock files.

---

## 3. Inventory of the dashboard project (report to the user)

List, before changing anything:
- `app/` (models, controllers, providers), `bootstrap/app.php`, `routes/*.php`
- `database/migrations` (names + what they create), `database/seeders`
- `config/*.php` that differ from Laravel defaults (auth, sanctum, cors, session, filesystems)
- `composer.json` require / require-dev, `package.json` dependencies
- `.env.example` keys

Compare with `files-manifest.txt`: every path in **both** projects goes to the MERGE list.

---

## 4. The merge, step by step

Do it in this order and **run the checks after each step**. Keep a running list of what was done to report.

### Step A0 — Upgrade the dashboard's Laravel 11 → 13 (before anything else)
> **✅ DONE on 2026-10-27** (from the backend session). What was done: MIT `LICENSE` copied into the project root
> (it was only in the zip's parent folder); the 24 standard Laravel files replaced by their Laravel 13 versions
> (taken from the backend's first commit `b333be1` = fresh Laravel 13); `composer.json` = Laravel 13's
> (+ `laravel/sail`, `setup` script uses pnpm); `composer update` → Laravel **13.38.0**, no security advisories;
> template customisations kept: `routes/web.php` (SPA catch-all `{any?}` → `application` view) and
> `.env.example` `VITE_*` keys. Verified: `php artisan test` 2/2, `pnpm build` OK, `/`, `/login`, `/dashboard`
> → 200, `/up` → 200 (not swallowed by the SPA route). Why it couldn't run "unchanged": its lock file needed
> PHP ≤ 8.4 (the Mac has 8.5) and Composer blocks **every Laravel 11 release** (security advisories).
> Local notes: `.env` uses a temporary **SQLite** DB (`database/database.sqlite`) — switch to MySQL `atlas_drive`
> during the merge. **pnpm is not installed globally**: use `npx --yes pnpm@9 <command>` (lockfile v9), or ask
> the user before installing pnpm (`corepack enable`). The user commits ("Upgrade to Laravel 13").

1. Read the official upgrade guides **11 → 12** and **12 → 13** (`search-docs` with Laravel Boost, or laravel.com/docs/upgrade).
2. In `composer.json`: `"php": "^8.3"`, `"laravel/framework": "^13.0"`, and bump the other Laravel packages
   (tinker, pint, sail, collision, phpunit...) to versions compatible with Laravel 13; `composer update`.
3. Apply the guide's code/config changes (the template has very little backend code, so this is small).
4. Check the template still runs exactly as before (`php artisan serve` + `pnpm dev`, open the pages).
5. Tell the user — a good commit point: "Upgrade to Laravel 13".

### Step A — Packages
```bash
composer require laravel/sanctum:^4.0 laravel/fortify:^1.41
composer require --dev laravel/boost:^2.10
```
Do **not** copy `composer.lock` or `vendor/`. Do **not** run `php artisan fortify:install` (it adds login /
registration pages and actions we don't use). If the dashboard lacks `config/sanctum.php`: `php artisan install:api`
**only if** it doesn't overwrite the dashboard's `routes/api.php` — otherwise copy our `config/sanctum.php`.

### Step B — COPY files (see `files-manifest.txt`, section COPY)
Copy each one to the **same path**. Groups:
- `app/Casts/`, `app/Console/Commands/CreateAgency.php`, `app/Enums/`, `app/Rules/`, `app/Support/`
- `app/Http/Controllers/Api/`, `app/Http/Middleware/`, `app/Http/Requests/`, `app/Http/Resources/`
- `app/Models/` (all **except** `User.php` → MERGE), `app/Models/Concerns/`, `app/Models/Scopes/`
- `app/Policies/`
- `config/fortify.php`, `config/sanctum.php` (if the dashboard has none)
- `database/migrations/` (ours), `database/factories/` (all except `UserFactory.php`), `database/seeders/`
  (all except `DatabaseSeeder.php`)
- `tests/Feature/`, `tests/Unit/`, `tests/Fixtures/`
- `docs/` (client-questions, deployment-checklist, learning-journal, merge/)

**Migrations:** our migration file names are dated 2026-10-20 → 2026-10-22. If the dashboard's migrations are dated
**later**, rename ours so the order stays: Laravel defaults (users / cache / jobs) → agencies → users changes →
the rest, in the original relative order (foreign keys depend on it). If the dashboard already has
`create_personal_access_tokens_table` or the Fortify `add_two_factor_columns_to_users_table`, keep **one**.
Never add the Fortify passkeys migration (unused on purpose).

### Step C — MERGE files (by hand, show the user each diff)
| File | What must end up in it |
|---|---|
| `app/Models/User.php` | Our whole model: `#[Fillable(['name','email','password'])]`, `#[Hidden([... 'two_factor_secret','two_factor_recovery_codes'])]`, traits `HasFactory, LogsActivity, Notifiable, TwoFactorAuthenticatable`, casts (role enum, must_change_password, deactivated_at, last_login_at, two_factor_confirmed_at), `agency()`, `isOwner()`, `isActive()`, `logoutEverywhere()`, footprint methods. Keep any template-only fields only if the user wants them. **`role` and `agency_id` must never become fillable.** |
| `app/Providers/AppServiceProvider.php` | `Fortify::ignoreRoutes()` in `register()`; in `boot()`: `Relation::enforceMorphMap([...])` (all aliases), `Gate::define('viewReports')`, `Password::defaults(...)`, rate limiters `login` and `two-factor`. Plus whatever the template registers. |
| `bootstrap/app.php` | `api: routes/api.php`; middleware `$middleware->statefulApi()`; exceptions: `shouldRenderJsonWhen(api/* or expectsJson)` + the `NotFoundHttpException` → `{"message":"Not found."}` render for `api/*`. Keep the template's own additions. |
| `routes/api.php` | All our routes (compare with `api-routes-snapshot.txt`), with the middleware group `['auth:sanctum', EnsureAccountIsActive::class, EnsurePasswordIsChanged::class]`. Template demo routes removed (ask). |
| `routes/web.php` | The template's SPA route, excluding `api`, `sanctum`, `up`. |
| `config/filesystems.php` | `local` disk: root `storage_path('app/private')`, **`'serve' => false`**. |
| `database/factories/UserFactory.php` | `agency_id => Agency::factory()`, `role => Role::Staff`, `owner()` state. |
| `database/seeders/DatabaseSeeder.php` | Ours (agency, owner + staff, then Vehicle, VehicleDocument, VehicleDamage, Customer, Maintenance, Rental seeders). **No `WithoutModelEvents`** (it breaks fleet numbers, footprints...). Template demo seeders removed (ask). |
| `phpunit.xml` | `DB_CONNECTION=mysql`, `DB_DATABASE=atlas_drive_test`, `CACHE_STORE=array`, `SESSION_DRIVER=array`; keep the template's front-end test settings if any. |
| `composer.json` | Combined require / require-dev (done by Step A). |
| `CLAUDE.md` | Keep **all** our business rules + conventions; add the dashboard front-end conventions; update paths. Re-run `php artisan boost:install` to re-append Boost guidelines. |

### Step D — REGENERATE (never copy)
- `vendor/`, `composer.lock` → `composer install` / `composer update` as needed
- `.claude/skills/`, `boost.json`, `.mcp.json` → `php artisan boost:install`
- `node_modules/`, front-end build → the template's own `pnpm install` / build
- `storage/` → **never copy development files**. On a live server, real files come from the server backup.

### Step E — `.env` (section 5)

---

## 5. `.env` keys the backend relies on

| Key | Value / rule |
|---|---|
| `APP_KEY` | Local: any (`php artisan key:generate` **only on a brand-new install**). **Live: the existing key must be kept forever** — it encrypts all uploaded files and the 2FA secrets. Losing or changing it = files unreadable + 2FA locked out. |
| `APP_URL` | the app's address (same domain as the Vue SPA) |
| `config/app.php` timezone | **must stay `UTC`** (times stored UTC; agencies have their own timezone in the DB) |
| `DB_CONNECTION=mysql`, `DB_DATABASE=atlas_drive` | tests use `atlas_drive_test` (phpunit.xml) |
| `SESSION_DRIVER=database` | needed: logging someone out everywhere = deleting their session rows |
| `SESSION_DOMAIN`, `SANCTUM_STATEFUL_DOMAINS` | the SPA's domain (and `localhost:5173` or Vite's port in dev) |
| `CACHE_STORE=database` (or redis) | **not `array`** in real use: login limits and the "2FA code used once" lock live in the cache |
| `FILESYSTEM_DISK=local` | private files in `storage/app/private` |

---

## 6. Verification — the merge is done only when ALL pass

1. `php artisan migrate:fresh --seed` → no error; sample agency, users, 10 cars, documents, damage, 12 customers,
   8 rentals, maintenance jobs and plans exist.
2. `php artisan test` → **all green, same number of tests (≥ 362)** as the backend repo.
3. `php artisan route:list --path=api` → **every line of `api-routes-snapshot.txt`** is there (same method,
   URI, controller, middleware). Extra template routes are listed for the user.
4. `vendor/bin/pint --test` → clean.
5. **Real browser-like flow** on `php artisan serve` (curl with a cookie jar, as done during development):
   `GET /sanctum/csrf-cookie` → `POST /api/login` (owner@atlasdrive.test / password) → `GET /api/me` →
   `GET /api/vehicles` → `GET /api/rentals/overview` → `POST /api/logout` → `GET /api/me` = 401.
6. **Security spot checks**: login from an unknown Origin → 403; another agency's id → 404; staff on an
   owner-only route (`/api/settings/users`, `/api/reports/...`, `/api/activity`) → 403; a deactivated user → 401.
7. The Vue app's login page works end to end against our API (CSRF cookie + session cookie, same domain).
8. Report to the user: what was copied, merged, regenerated, any template file removed, test count, route check,
   and the suggested commit message. **Do not commit yourself.**

### Auth flow for the Vue side (for building the pages afterwards)
- axios: `withCredentials: true`, `withXSRFToken: true` (sends the `X-XSRF-TOKEN` header from the cookie).
- Login: `GET /sanctum/csrf-cookie` → `POST /api/login` → either `{ data: user }` or `{ two_factor: true }`
  → then `POST /api/login/two-factor { code }`.
- `GET /api/me` → `data.must_change_password` true ⇒ show "choose your password" only
  (any other call answers 403 `code: password_change_required`).
- 401 anywhere ⇒ back to login (session ended or account deactivated).

---

## 7. Known traps (already hit during development — see learning journal "Lessons")

- `WithoutModelEvents` in seeders switches off fleet / customer / rental numbering and footprints.
- Date-times typed by people use the `App\Casts\UtcDateTime` cast (Eloquent's own cast stores the clock
  reading without converting). Never hardcode a timezone offset (Morocco: UTC+0 since 2026-09-20 per tzdata).
- `Relation::enforceMorphMap` is strict: every model used in footprints / attachments needs an alias.
- Fortify: keep `Fortify::ignoreRoutes()` and the config features list = only `twoFactorAuthentication`
  with `confirm => true` (its default config enables public **registration** — we have none).
- Login always uses `Auth::guard('web')` explicitly.
- In tests, "another agency" data must be created while nobody is logged in (the agency guard).
- A column named `changes` breaks Eloquent (internal property) — the activity log column is `diff`.

---

## 8. After the merge — the plan (SaaS-first, decided 2026-10-26 / 27)

Atlas Drive is a **generic SaaS** (MVP first). Client-specific questions (`docs/client-questions.md`) are asked
**after ~5 paying agencies**; until then every choice is an agency setting with a sensible default.

1. **Design system** in the Vuetify theme + global defaults + one SCSS file (no edits inside the template's core):
   brand palette (primary + warm accent, soft neutrals, status colours), rounder corners (cards 12–16 px,
   inputs 10 px), soft layered shadows, a modern font (Inter / Manrope; Arabic + RTL later), soft "status pills",
   short transitions, skeleton loaders, friendly empty states, one page-header pattern, KPI cards, a tuned dark
   mode. Show the user a **visual preview first**, then apply.
2. **Connect the screens to the API, module by module:** login (+ 2FA step, forced password change), layout and
   menu, dashboard (`/api/rentals/overview`, document alerts, `/api/maintenance/upcoming`), then Vehicles →
   Customers → Rentals → Maintenance → Settings.
   - Use the **v8 prototype** as the reference for screens, rules and data shapes:
     `~/dashboard/Dashboard_maska/vuexy-admin-v8.2.2/vue-laravel-version/vuexy-bootstrapvue/full-version`
     (e.g. `vehicleStore.js`, `rentalStore.js`, `rentalOptions.js`, the car damage drawing).
   - **Licence rule:** reuse only the user's **own** code / logic from it; rebuild the UI with **Materio / Vuetify**
     components. Never copy Vuexy's template code (`@core`, `@layouts`, its components, styles, images).
3. **New modules:** **Super admin** (+ `user:reset-access`) → **Subscriptions** (plans, trial, monthly billing of
   agencies) → **Invoices** (VAT configurable per agency: prices TTC/HT + rate; backend PDFs for invoice, contract,
   DEP receipt) + contract data `GET /api/rentals/{id}/contract`.
4. **Before selling:** lawyer (terms of service, law 09-08, CNDP), VPS hosting with backups and monitoring
   (`docs/deployment-checklist.md`).
5. **Later:** booking-requests inbox (only with public websites we build), companies & drivers, Excel import,
   QR phone photos, notifications, scheduled tasks (overdue, expiring papers, ID-copy retention).
