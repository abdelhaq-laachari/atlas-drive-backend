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

**Plan:** Step 1 cars (table, pricing, list/details/add/edit API, plate & VIN rules, manual status) → Step 2 documents (insurance, inspection, vignette, registration; Valid / Expiring soon / Expired / Missing; history) → Step 3 damage record on the car (open / repaired).

### Decisions (2026-10-21)
**Extra-km fee may have cents** (1.50 MAD/km): it's a *rate per km*, not an amount someone pays directly. Every other typed amount stays whole dirhams. The final charge (e.g. 120 km × 1.50) is rounded with `Money::round()`.
**Archive, never delete:** a sold or retired car is *archived* (date + reason). It disappears from lists and can't be rented, but old rentals and invoices still show the right car. Only a car with no history can be fully deleted.
**Why:** deleting a car would break history — an invoice from last year must still say which car was rented.
**My notes:**

### Step 1: Cars (2026-10-21)
**What:** the `vehicles` table and API.
- `GET /api/vehicles` — list (25 per page), filters `?status=` `?category=` `?search=` (fleet code, plate, make, model), `?archived=1` for archived cars.
- `GET /api/vehicles/{id}`, `POST /api/vehicles` (add), `PUT /api/vehicles/{id}` (edit).
- `PATCH /api/vehicles/{id}/status` — Available / Maintenance / Out of service (Rented and Reserved come from rentals).
- `POST /api/vehicles/{id}/archive` (with a reason) and `DELETE /api/vehicles/{id}/archive` (restore). No real delete.
**Who can do what:** owner adds, edits, archives cars (prices!). Staff see the fleet and change the status (e.g. send a car to maintenance).
**Rules:**
- Fleet code V-001, V-002… automatic, numbered per agency, never reused (archived cars keep theirs).
- Plate unique per agency: `30577 b 6` = `30577-B-6`. Stored twice: `plate` (display) and `plate_key` (`30577B6`, for comparing). Works with Arabic letters.
- VIN: 17 letters/digits, no I, O, Q (optional).
- Prices, deposit: whole dirhams. Extra-km fee: up to 2 decimals.
- `status` and `agency_id` can't be sent in the form.
**Files:**
- `database/migrations/..._create_vehicles_table.php`, `app/Models/Vehicle.php`, `app/Enums/VehicleStatus.php`, `VehicleCategory.php`, `FuelType.php`, `Transmission.php`
- `app/Policies/VehiclePolicy.php`, `app/Http/Requests/VehicleRequest.php`, `app/Http/Resources/VehicleResource.php`
- `app/Http/Controllers/Api/VehicleController.php`, `VehicleStatusController.php`, `VehicleArchiveController.php`, `routes/api.php`
- `database/factories/VehicleFactory.php`, `database/seeders/VehicleSeeder.php` (10 sample cars from the prototype)
- `tests/Feature/Vehicles/VehicleApiTest.php`, `VehicleStatusTest.php`, `VehicleArchiveTest.php`
**Laravel concepts:**
- **Policy** = a class that answers "is this user allowed to do this?" (`create`, `update`, `archive`...). Wrong role → 403.
- **Route model binding** = `{vehicle}` in the URL becomes a `Vehicle` object automatically; with the agency guard, another agency's car → 404.
- **`apiResource`** = one line that creates the standard routes (index, show, store, update).
- **Accessor / mutator** (`Attribute`) = computed value when reading (`fleet_code` from `fleet_number`) or extra work when writing (setting `plate` also sets `plate_key`).
- **Local scope** = a reusable query piece: `Vehicle::inFleet()`, `Vehicle::archived()`.
- **`after()` in a Form Request** = extra validation that needs the database (is the plate taken?).
- **Pagination** = the list comes as `{ data, links, meta }` with page numbers and totals.
- **409 Conflict** = the request is fine, but not allowed in the car's current state (e.g. editing an archived car).
**Lessons:**
- Laravel's default seeder had `WithoutModelEvents`, which switched off our automatic fleet number → the seeder crashed. Removed it.
- A test caught that `30577 b 6` and `30577-B-6` were treated as different plates → added `plate_key`.
**Commands:** `php artisan migrate:fresh --seed` → 10 sample cars in phpMyAdmin (`vehicles` table).
**My notes:**

---

## Decisions — Accounts & security (2026-10-21)
**Who creates whom:** me (super admin) → agency + owner; owner → staff; staff → nobody. No "Sign up" page, only login.
**First password:** temporary password + forced change at first login (option A). Later: invitation link by email (option B).
**Everyone can change their password** at any time (must type the old one first).
**Three extra rules:**
1. Staff who leave are **deactivated, not deleted** (history stays correct — same idea as archiving cars).
2. Owner can reset a staff password; I can reset an owner's.
3. An owner can't remove themselves or the last owner (agency never locked out).
**Two-step verification:** authenticator app (Google Authenticator). Free: the phone and the server share a secret (QR code) and both calculate the same 6-digit code from the time — no internet call, no paid API. Laravel Fortify (official) supports it + recovery codes. SMS = paid, avoided.
**Not the same thing:** "Google Authenticator" (6-digit code after password) ≠ "Sign in with Google" (log in with a Google account).
**Open question for my friend:** fixed roles or per-staff permissions → `docs/client-questions.md`.
**My notes:**

---

## Module 1 — Foundation (continued)

### Step 5: Activity log — "footprints" (2026-10-21)
**What:** every action on a car is recorded: who, what, which car, before → after, when. The owner reads it with `GET /api/activity` (filters: `?user_id=`, `?subject_type=vehicle&subject_id=`, `?action=`, `?from=2026-10-01&to=2026-10-31`).
**Example:** `Youssef — updated — V-003 Dacia Logan — daily price 270.00 → 250.00 — today 15:30`
**Rules:**
- Nobody can edit or delete a footprint (not even the owner) → otherwise it can't be trusted.
- Only the owner reads it; each agency sees only its own.
- Done with nobody logged in (seeders, scheduled tasks) → user is empty = "System".
- Sensitive values (CIN, passport) will be logged as "(hidden)".
**Super admin & privacy (law 09-08):** I *can* technically see everything, but only look for support, security or legal reasons, and my access is logged too. Staff must know their actions are recorded. Write it in the contract.
**Files:** `database/migrations/..._create_activity_logs_table.php`, `app/Models/ActivityLog.php`, `app/Models/Concerns/LogsActivity.php`, `app/Policies/ActivityLogPolicy.php`, `app/Http/Controllers/Api/ActivityLogController.php`, `app/Http/Resources/ActivityLogResource.php`, `app/Providers/AppServiceProvider.php` (morph map), `app/Models/Vehicle.php`, `tests/Feature/ActivityLogTest.php`
**Laravel concepts:**
- **Model events** `created` / `updated` = run code automatically after saving (here: write the footprint).
- **Polymorphic relation** (`morphTo`) = one log table can point to different things (a car, later a customer, a rental) with `subject_type` + `subject_id`.
- **Morph map** = store the short name `vehicle` instead of the PHP class name `App\Models\Vehicle`.
- **Invokable controller** = a controller with only one action (`__invoke`).
- **`travelTo()`** in tests = pretend it's another date/time.
**Lessons:**
- I named the column `changes`, but every Eloquent model already has an internal `$changes` property → the data was silently never saved. A test caught it → renamed to `diff`. Lesson: avoid names Laravel already uses.
- The same price was logged as `270` or `"270.00"` → now every value is formatted like the API.
- Date filters use the agency's day (Casablanca), not UTC — otherwise an action at 23:30 would show up on the wrong day.
**My notes:**

### Decision — Super admin access, the safe way (2026-10-21)
**Fear:** "if I see an agency's data by accident, am I in trouble?"
**Design that protects me:**
1. My super admin dashboard shows **only numbers** (cars, rentals, staff, last login). There is **no page** with an agency's customers or logs → seeing them by accident is impossible.
2. If an agency needs help, **the owner clicks "Allow support access"** (e.g. 24 hours). Only then can I open their data.
3. The owner sees "Atlas Drive support accessed your account on 12/03 at 14:00". Access closes automatically.
**Why the access log is good for me:** like a visitor book — it *proves* I came for a good reason (support ticket), it's not a trap.
**What the law punishes:** misuse — selling, sharing, using data for my own purpose, careless leaks. Not a provider fixing a bug with permission.
**To do before selling:** one meeting with a Moroccan lawyer (contract, CNDP) → in `docs/client-questions.md`.
**My notes:**

---

## Module 2 — Vehicles (continued)

### Step 2: Documents (2026-10-21)
**What:** insurance, technical inspection, vignette and registration card for each car, with status **Valid / Expiring soon / Expired / Missing**.
- `GET /api/vehicles/{id}/documents` — one row per type: status, days left, current document, history.
- `POST /api/vehicles/{id}/documents` — add or renew (the newest start date becomes the current one, the old one stays as history).
- `PUT /api/vehicle-documents/{id}` — correct. `DELETE /api/vehicle-documents/{id}` — owner only.
- `GET /api/vehicle-documents?status=expired,missing,expiring&type=insurance` — "Documents & Deadlines" for the whole fleet, most urgent first (for the dashboard alerts).
**Rules:**
- Valid up to and including the expiry date; "expiring soon" = within the agency's setting (30 days).
- "Today" = the agency's day (Casablanca), not the server's (UTC). At 00:30 in Casablanca it's already tomorrow there, but still yesterday in UTC.
- Insurance / inspection / vignette need an expiry date after the start date; the registration card has none.
- Cost in whole dirhams. No documents added to archived cars. Archived cars not in the fleet list.
- Every document change leaves a footprint (activity log) — the trait from step 5 worked with one line.
**Files:** `database/migrations/..._create_vehicle_documents_table.php`, `app/Models/VehicleDocument.php`, `app/Enums/DocumentType.php`, `DocumentStatus.php`, `app/Support/DocumentOverview.php`, `app/Policies/VehicleDocumentPolicy.php`, `app/Http/Requests/VehicleDocumentRequest.php`, `app/Http/Resources/VehicleDocumentResource.php`, `DocumentRowResource.php`, `app/Http/Controllers/Api/VehicleDocumentController.php`, `FleetDocumentController.php`, `database/factories/VehicleDocumentFactory.php`, `database/seeders/VehicleDocumentSeeder.php`, `tests/Feature/Vehicles/DocumentStatusTest.php`, `VehicleDocumentTest.php`
**Laravel concepts:**
- **hasMany / belongsTo** = a car has many documents; a document belongs to a car.
- **Eager loading** (`with('documents')`) = load all cars' documents in one query instead of one query per car (avoids the "N+1" problem).
- **Collections** (`groupBy`, `sortBy`, `filter`, `flatMap`) = like JavaScript array methods, on query results.
- **Conditional validation** = the rules change with the type (`prohibited` for the registration card).
- **A support class** (`DocumentOverview`) = business logic in one place, used by two controllers and tested alone.
**Lesson:** a **flaky test** — it changed a random price to 250, but sometimes the random price was already 250, so nothing changed and the test failed. Fixed by setting the starting value. Rule: never rely on random values you then change.
**Not built yet:** scanned files (PDF / photo of the document) → question for my friend.
**My notes:**

### Step 3: Damage record on the car (2026-10-21)
**What:** the live damage record of each car — **open** damage (on the car now, shown on the drawing at the next hand-over) and **repaired** damage (history with date and workshop cost).
- `GET /api/vehicles/{id}/damages?status=open` — oldest first, so the numbers on the drawing stay the same.
- `POST /api/vehicles/{id}/damages` — record damage found outside a rental (e.g. noticed at the agency).
- `PUT /api/vehicle-damages/{id}` — correct an open damage. `DELETE` — owner only (mistake).
- `POST /api/vehicle-damages/{id}/repair` — mark repaired (date, cost, note). `DELETE .../repair` — owner only, reopen if marked repaired by mistake.
- The car list now shows `open_damages_count` for each car.
**Data of a damage mark:** `x`, `y` (position on the drawing from 0 to 1 → same on phone and desktop), `area` (car part from a fixed list of 25), `type` (scratch, dent, crack, broken, other), `size`, `note`, `found_at`, `found_stage` (hand-over / return / outside a rental).
**Rules:** repair date between the day it was found and today (agency's day); repair cost in whole dirhams; a repaired damage can't be edited or repaired twice; no new damage on archived cars; every change leaves a footprint (`created`, `repaired`, `reopened`).
**Left for the Rentals module:** link damage to the rental where it was found, create damage automatically at hand-over / return, and the **customer charge** (charge / reduce / waive). Later idea: compare charged amount vs repair cost (profit / loss on damage).
**Files:** `database/migrations/..._create_vehicle_damages_table.php`, `app/Models/VehicleDamage.php`, `app/Enums/DamageType.php`, `DamageSize.php`, `DamageStatus.php`, `DamageStage.php`, `app/Support/CarParts.php`, `app/Policies/VehicleDamagePolicy.php`, `app/Http/Requests/VehicleDamageRequest.php`, `RepairDamageRequest.php`, `app/Http/Resources/VehicleDamageResource.php`, `app/Http/Controllers/Api/VehicleDamageController.php`, `VehicleDamageRepairController.php`, `database/factories/VehicleDamageFactory.php`, `database/seeders/VehicleDamageSeeder.php`, `tests/Feature/Vehicles/VehicleDamageTest.php`
**Laravel concepts:**
- **`withCount`** = count related rows in the same query (`open_damages_count` for every car) without loading them.
- **`whenHas`** in a Resource = only include a field when it was loaded.
- **`forceFill`** = set fields that are not fillable (status, repair info) on purpose, from trusted code — never with raw user input.
- **Dynamic validation rules** = the repair date limits come from the damage itself and the agency's timezone.
**Lesson:** tests passed first try, and a real-server check (login → cars → documents → damage) matched the prototype's sample data.
**My notes:**

**Vehicles module finished:** 127 tests passing.

---

## Module 3 — Customers

### Decisions (2026-10-21)
**Customer vs driver:** the *customer* signs and pays; the *driver* drives (always a person with ID + licence). For an individual they're the same person. Companies (corporate leasing) come **later**: company = payer, drivers = individuals linked to it. A `type` column (`individual`) is ready for it.
**Blocklist:** yes — the owner blocks a customer with a reason (unpaid damage, very late return...). Blocked customers can't start a new rental.

### Small refactor: one trait for "numbers per agency"
Cars (V-001) and customers (C-001) both need "next number of this agency" → moved into `app/Models/Concerns/NumberedPerAgency.php`. Rentals (R-...) will reuse it. Lesson: when the same logic is needed a second time, extract it; the 71 vehicle tests proved the refactor broke nothing.

### Step 1: Customers (2026-10-21)
**What:**
- `GET /api/customers?search=&blocked=1&archived=1` — sorted by name; search by name (any order), phone, ID number or code (C-012).
- `POST /api/customers`, `GET /api/customers/{id}`, `PUT /api/customers/{id}`.
- `POST|DELETE /api/customers/{id}/block` (with reason) and `POST|DELETE /api/customers/{id}/archive` — owner only.
**Rules:**
- Same ID number twice in an agency → refused, with a helpful message: "already belongs to customer C-001 (Amine Kettani)". Compared on `id_key` (letters + digits): `xx 100 001` = `XX100001`.
- Phone stored as `+212600000001`; searching `0600 000 001`, `+212 600...` or `00212 600...` finds it (leading zeros ignored).
- Licence number and licence date go together, and the licence date is after the birth date. Both optional (someone may pay but not drive).
- **Privacy:** ID and licence numbers appear as "(hidden)" in the activity log — tested, the real number never appears.
- No delete: archived (same rule as cars).
**Files:** `database/migrations/..._create_customers_table.php`, `app/Models/Customer.php`, `app/Enums/IdType.php`, `CustomerType.php`, `app/Models/Concerns/NumberedPerAgency.php`, `app/Policies/CustomerPolicy.php`, `app/Http/Requests/CustomerRequest.php`, `app/Http/Resources/CustomerResource.php`, `app/Http/Controllers/Api/CustomerController.php`, `CustomerBlockController.php`, `CustomerArchiveController.php`, `database/factories/CustomerFactory.php`, `database/seeders/CustomerSeeder.php` (the prototype's 12 invented customers; C-009 blocked), `tests/Feature/Customers/CustomerApiTest.php`, `CustomerBlockArchiveTest.php`
**Laravel concepts:**
- **Mutator with two columns** = setting `id_number` also fills `id_key` (same trick as plate / plate_key).
- **`required_with`** = "if one of these fields is filled, the other is required too".
- **Masked fields** (`$activityMasked`) = privacy by design: sensitive values never reach the log.
**Lessons:**
- A test found that staff typing a local number (`0611...`) couldn't find a customer stored as `+212 611...` → search now ignores leading zeros.
- MySQL reorders the keys inside JSON → compare JSON arrays without caring about key order (`assertEquals`, not `assertSame`).
**My notes:**

### Step 2: Driver check (2026-10-21)
**What:** `GET /api/customers/{id}/driver-check?on=2026-11-15` → "can this person drive on the pickup date?"
```
{ "eligible": false, "on": "2026-11-14",
  "checks": [ { "key": "age", "ok": false, "value": 20, "minimum": 21, "message": "20 years old (minimum 21)" },
              { "key": "licence", "ok": true, ... "message": "Licence held for 3 years (minimum 2)" } ] }
```
**Rules:**
- Age ≥ 21 and licence held ≥ 2 years **on the pickup date** (not today) — values from the agency settings.
- Full years like birthdays: you turn 21 *on* your birthday. Born on 29 February → in a normal year you have your birthday on 1 March (same as the Vue prototype).
- No licence recorded, blocked or archived → cannot drive, with the reason shown to staff.
- No date given → today in the agency's timezone (at 00:30 in Casablanca it's already the next day there).
**Files:** `app/Support/DriverCheck.php`, `app/Http/Controllers/Api/DriverCheckController.php`, `routes/api.php`, `tests/Feature/Customers/DriverCheckTest.php`
**Laravel concepts:**
- **A support class with no database** (`DriverCheck`) = pure business logic, easy to test with many dates (data provider).
- **`date_format:Y-m-d`** = only accept dates written exactly like `2026-11-15` (refuses `15/11/2026`, which is ambiguous).
- **Returning plain JSON** (`response()->json`) when the answer isn't a model.
**Lesson:** the backend uses exactly the same year-counting rule as the Vue prototype, so the form and the server can never disagree.
**My notes:**

**Customers module finished (individuals):** 164 tests passing.

