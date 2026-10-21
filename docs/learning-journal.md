# Atlas Drive — Learning Journal

My notes while building the Atlas Drive backend (Laravel 13 + MySQL), step by step.
Each entry: what we built, why, which files, the Laravel concepts, and my own notes.

---

## Decisions (before coding)

### Money format (2026-10-20)
**Decision:** store money as `decimal(10,2)` (e.g. `350.00`); users type whole dirhams only (100, 150, 307).
**Why:** nobody types cents, but calculations create them (VAT: 100 / 1.2 = 83.33; weekly price split: 1000 / 7 = 142.86). So the database must hold cents.
**Options we compared:**
| Option | 350 MAD stored as | Holds 83.33? | Divide by 100? |
|---|---|---|---|
| A. Integer centimes | `35000` | yes | yes, always |
| B. `decimal(10,2)` ✅ | `350.00` | yes | no |
| C. Integer whole MAD | `350` | no, VAT breaks | no |
**Key idea:** two separate questions — what the user can TYPE (whole dirhams) and what the database can STORE (decimals).
**My notes:**

### Laravel Boost (2026-10-20)
**What:** official Laravel dev package that gives the AI assistant Laravel 13 guidelines and access to the app (database, routes, logs). Dev-only, never on the live server.
**Files:** `boost.json`, `.mcp.json`, `.claude/skills/`, guidelines appended to `CLAUDE.md`.
**My notes:**

### Two databases (2026-10-20)
- `atlas_drive` → the app's real data (look at it in phpMyAdmin: http://phpmyadmin.test, user `root`).
- `atlas_drive_test` → only for `php artisan test`; wiped on every test run so tests never destroy real data. Set in `phpunit.xml`.
**My notes:**

---

## Module 1 — Foundation

### Step 1: Agencies & users (2026-10-20)
**What:** `agencies` table (name, contact, currency, timezone + agency rules: grace 60 min, fuel 150 MAD/quarter, driver age 21, licence 2 years, documents "expiring soon" 30 days). Users got `agency_id` and `role` (owner / staff).
**Why:** later we sell to many agencies (SaaS), so every user belongs to an agency, and each agency has its own rules (no hardcoded values).
**Files:**
- `database/migrations/..._create_agencies_table.php`, `..._add_agency_and_role_to_users_table.php`
- `app/Models/Agency.php`, `app/Models/User.php`, `app/Enums/Role.php`
- `database/factories/AgencyFactory.php`, `UserFactory.php`, `database/seeders/DatabaseSeeder.php`
- `tests/Feature/Models/AgencyTest.php`
**Laravel concepts:**
- **Migration** = a versioned file that creates/changes a table.
- **Model** = PHP class for a table (like a Mongoose model). **Casts** convert DB values to the right PHP type.
- **Enum** = fixed list of allowed values (`owner`, `staff`).
- **Factory** = generates fake records for tests. **Seeder** = fills the database with starting data.
- **Fillable / mass assignment** = which fields can be copied from form input. `role` and `agency_id` are NOT fillable, so nobody can send `role: owner` to promote themselves.
**Commands:** `php artisan migrate:fresh --seed` (rebuild DB + sample data). Local logins: `owner@atlasdrive.test` / `staff@atlasdrive.test`, password `password`.
**My notes:**

### Step 2: Keeping agencies separate (2026-10-21)
**What:** a trait `BelongsToAgency` that every tenant model (Vehicle, Customer, Rental...) will use in one line.
**Why:** in the SaaS, agency A must never see agency B's cars or customers, even if a developer forgets a filter.
**Explained simply:** a security guard. When saving, it puts YOUR agency's sticker (`agency_id`) on the row. When reading, it only shows rows with your sticker.
**When nobody is logged in** (seeders, terminal commands, the server's automatic morning checks, background jobs): no guard, the code sees all agencies — because that code works for every agency. Strangers can't use this: back-office routes require login.
**Files:** `app/Models/Concerns/BelongsToAgency.php`, `app/Models/Scopes/AgencyScope.php`, `tests/Fixtures/TenantItem.php`, `tests/Feature/Models/BelongsToAgencyTest.php`
**Laravel concepts:**
- **Trait** = a block of methods you plug into a class (like a mixin).
- **Global scope** = a condition added automatically to every query of a model (`WHERE agency_id = ...`).
- **Model events** (`creating`) = code that runs automatically before a row is saved.
- **actingAs($user)** in tests = pretend this user is logged in.
**Lesson:** I turned the filter off on purpose → 3 tests failed (data leaked). A test is only useful if it can fail.
**My notes:**

### Step 3: Login with Sanctum (2026-10-21)
**What:** `POST /api/login`, `GET /api/me` (user + agency + rules), `POST /api/logout`. Max 5 login attempts per minute.
**How it works:**
1. Vue calls `GET /sanctum/csrf-cookie` → gets a secret token (CSRF protection: stops other websites from sending requests in your name).
2. Vue sends `POST /api/login` → Laravel checks email/password, creates a **session** (cookie), like `express-session`.
3. Every next request sends the cookie automatically → Laravel knows who you are and your agency.
4. `POST /api/logout` destroys the session.
**Files:** `routes/api.php`, `app/Http/Controllers/Api/AuthController.php`, `app/Http/Requests/Auth/LoginRequest.php`, `app/Http/Resources/UserResource.php`, `AgencyResource.php`, `app/Providers/AppServiceProvider.php` (rate limit), `bootstrap/app.php` (`statefulApi()`), `tests/Feature/Auth/AuthTest.php`
**Laravel concepts:**
- **Sanctum** = official login package (cookie session for our Vue app).
- **Controller** = class with the code for routes. **Middleware** = checks before the controller runs (`auth:sanctum`, `throttle:login`).
- **Form Request** = class that validates input before the controller runs.
- **API Resource** = chooses which fields are sent as JSON (like a `toJSON`); the password is never sent.
- **Rate limiting** = blocks password guessing.
**Lesson:** tests passed, but testing with a real server found a bug (login from an unknown website crashed with error 500). Fixed → now refused with 403, and a test was added.
**Status codes:** 200 OK, 204 OK no content, 401 not logged in, 403 forbidden, 419 CSRF token missing, 422 invalid input, 429 too many tries.
**My notes:**

### Step 4: API conventions (2026-10-21)
**What:**
- `WholeDirhams` rule: typed amounts must be whole dirhams (`307` ok, `150.50` refused).
- `Money::round()`: every calculated amount rounded the same way (`100 / 1.2` → `"83.33"`).
- Every API 404 answers `{"message": "Not found."}` (before, it revealed internal names like `App\Models\User`).
- The conventions written in `CLAUDE.md` so every module follows them.
**Files:** `app/Rules/WholeDirhams.php`, `app/Support/Money.php`, `bootstrap/app.php`, `tests/Unit/Support/MoneyTest.php`, `tests/Unit/Rules/WholeDirhamsTest.php`, `tests/Feature/Api/ApiErrorsTest.php`
**Laravel concepts:**
- **Validation rule class** = a reusable check: `'daily_price' => ['required', new WholeDirhams]`.
- **Exception rendering** = customize the JSON returned when something goes wrong.
- **Data provider** (tests) = run the same test with many inputs.
**My notes:**

**Foundation finished:** 44 tests passing.

---

## Module 2 — Vehicles
_(next)_
