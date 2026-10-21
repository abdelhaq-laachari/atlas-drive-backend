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

---

## Module 4 — Rentals

**Plan:** 1 reservation → 2 before pickup (cancel, extend, payments) → 3 hand-over → 4 return (suggested charges, deposit) → 5 lists & dashboard (overdue, today, upcoming).

### Decisions (2026-10-21)
**Charge lines instead of one total:** each amount is a line with *suggested* vs *charged* and a decision (charge / reduce / waive + reason). Total = sum of lines. The owner always sees what was suggested, what was charged and why → fits "never force a charge, loyal customers get exceptions".
**Payments are separate lines** (cash, card, cheque, transfer + reference, or taken from the deposit).
**Deposit:** amount, method, reference, and a numbered receipt DEP-0001.
**Expired documents:** warning at booking (there's time to renew), **blocked at hand-over** for insurance / inspection (driving without them is illegal). Vignette / registration: warning only.
**Reduce / waive:** everyone can, but a reason is required and it's in the footprints.
**My notes:**

### Step 1: Reservation (2026-10-21)
**What:**
- `POST /api/rentals/quote` — preview without saving: days, suggested price, deposit, warnings, and problems (`can_book: false` + reasons). For the new-rental form.
- `POST /api/rentals` — book: rental R-0001, status Reserved, rental charge line, deposit + receipt DEP-0001, optional payment. The car becomes Reserved (a car out on another rental keeps "Rented").
- `GET /api/rentals`, `GET /api/rentals/{id}` — with car, customer, driver, charges, payments, total / paid / balance.
- Times are sent as local agency time (`2026-11-10 10:00`) and returned with their offset; stored in UTC.
**Checks (errors block, warnings don't):**
- Errors: pickup in the past, return before pickup, car archived, car already booked for overlapping dates (back-to-back is fine), **overdue car blocked until it comes back**, customer blocked/archived, driver too young / licence too recent on the pickup date, another agency's car or customer → "Not found".
- Warnings: car in maintenance / out of service; documents missing, expired, or expiring before the return.
- Lower price → reason required (decision "reduce"); 0 → "waive". Deposit needs a method; cheque / card / transfer need a reference.
**Two people booking the same car at the same second:** the booking runs in a **database transaction that locks the car row** and checks again → the second one gets "409: just booked by someone else".
**Files:** `database/migrations/..._create_rentals_table.php`, `..._create_rental_charges_table.php`, `..._create_rental_payments_table.php`, `app/Models/Rental.php`, `RentalCharge.php`, `RentalPayment.php`, `app/Enums/RentalStatus.php`, `ChargeKind.php`, `ChargeDecision.php`, `PaymentMethod.php`, `app/Support/RentalDays.php`, `RentalBooking.php`, `Money.php` (cents, sum), `app/Casts/UtcDateTime.php`, `app/Policies/RentalPolicy.php`, `app/Http/Requests/StoreRentalRequest.php`, `QuoteRentalRequest.php`, `app/Http/Resources/RentalResource.php`, `app/Http/Controllers/Api/RentalController.php`, `database/factories/RentalFactory.php`, `tests/Feature/Rentals/RentalDaysTest.php`, `RentalBookingTest.php`
**Laravel concepts:**
- **DB transaction + `lockForUpdate()`** = "nobody else may touch this car row until I'm done" → no double booking.
- **Request inheritance** = `QuoteRentalRequest extends StoreRentalRequest`: same checks, different answer.
- **Custom cast** (`UtcDateTime`) = your own rule for reading/saving a column.
- **`additional(['meta' => ...])`** on a Resource = send extra info (warnings) next to `data`.
- **Money in centimes** (`Money::cents`, `Money::sum`) = add and compare amounts exactly, without the bcmath extension (a server might not have it).
**Lessons:**
- **Morocco's time:** PHP's timezone data (2026.3) says Morocco is **UTC+0 since 20 Sep 2026** with no more clock changes. My tests had "+01:00" written in them and failed. The app was fine because it never hardcodes an offset — it asks the timezone database. Lesson: store the timezone *name* (`Africa/Casablanca`), never "+1".
- **A hidden one-hour bug:** Laravel saves a date's *clock reading* without converting its timezone (10:00 in Lagos saved as 10:00 UTC = 11:00 Lagos). It was invisible because Casablanca = UTC now. Found by testing with an agency in Lagos (UTC+1 all year) → fixed once for all with the `UtcDateTime` cast; the same bug existed in damage `found_at`.
- A factory run while someone is logged in gets *their* agency (the agency guard) → create "other agency" data before logging in, in tests.
**My notes:**

### Note — Moroccan time (2026-10-21)
Morocco is back to normal time (UTC+0); summer time (+1) might return, not sure.
**What to do if it changes:** nothing in the code (we store the name `Africa/Casablanca`, PHP's timezone database knows the rules) → just **keep PHP updated on the server**.
**Edge case:** a reservation made *before* an announcement, for a date *after* the change, would shift by 1 hour → fix with a one-time command for future reservations. Finished rentals are never affected.
**My notes:**

### Step 2: Cancel, extend, payments (2026-10-21)
**What:**
- `POST /api/rentals/{id}/cancel` `{ reason, fee? }` — reservations only. Price lines become **waived** (we still see what was suggested), optional **cancellation fee**, deposit marked as given back, the car becomes Available — or stays Reserved if another booking is waiting, and a car in maintenance stays in maintenance.
- `POST /api/rentals/{id}/extend` `{ return_at, amount?, reason? }` — reservations or rentals in progress. Suggested = extra days × the rental's daily price (e.g. "Extension +2 days (until 15/11/2026 10:00)" = 540 MAD); less or free → reason required. Can't overlap the next booking (back-to-back OK); an overdue rental can be extended into the future.
- `POST /api/rentals/{id}/payments` `{ amount, method, reference?, refund? }` — money in, up to what is due; **refunds** (negative amounts) up to what was paid. A cancelled rental only accepts refunds.
**Shared helpers:** `Rental::addCharge()` (decision from suggested vs amount), `Vehicle::syncStatusWithRentals()` (Rented / Reserved / Available from the car's rentals), `Rental::loadDetails()`.
**Files:** `app/Http/Controllers/Api/RentalCancelController.php`, `RentalExtensionController.php`, `RentalPaymentController.php`, `app/Models/Rental.php`, `Vehicle.php`, `app/Enums/ChargeKind.php` (cancellation), `app/Models/Concerns/LogsActivity.php`, rentals migration (deposit_kept, deposit_returned_at), `tests/Feature/Rentals/RentalChangesTest.php`
**Laravel concepts:**
- **`ValidationException::withMessages()`** = return a 422 with your own message from inside a controller (when the check needs calculations first).
- **Negative amounts for refunds** = one payments table; `paid` = sum of all lines.
**Lessons:**
- The activity log didn't record the new return date: for **custom casts**, Eloquent returns the *current* value even when you ask for the old one → old = new → change skipped. Fixed by asking the cast directly. My first fix broke everything (enums are also "class casts" and can't be created with `new`) → 69/215 tests failing showed it at once → check enums first. Lesson: run the *whole* test suite after a change in shared code.
**My notes:**

### Decision — Files (attachments) (2026-10-21)
**Real workflow:** online booking → the agency calls the client → the client sends CIN / licence on **WhatsApp** → the agent downloads them on the PC (WhatsApp Web) and uploads them. Walk-in clients: documents **scanned** on the PC. Then the contract is generated, signed, scanned and uploaded.
**So now:** a normal upload from the PC. Car damage photos skipped for now (the car diagram replaces them).
**Later — phone photos without Bluetooth:** a QR code on the PC hand-over page → the agent scans it with the phone camera (iPhone or Android) → a small temporary page "Photos for R-0012" → photos go **straight to the server** and appear on the PC. No AirDrop / Bluetooth / WhatsApp needed.
**Security layers:** HTTPS + login, agency guard, file type checked by content, photos re-saved by the server (removes hidden code and the GPS location), private storage with random names, **files encrypted on disk**, footprints, automatic deletion of old ID copies (later), backups.
**Space:** shrinking photos (3–5 MB → ~0.3 MB) → ~6–7 GB per year for the agency instead of ~60 GB. A Laravel-capable Hostinger shared plan is enough for one agency (check: SSH + Composer, PHP 8.4+ with GD, cron, backups); a VPS later for the SaaS.
**My notes:**

### Shared step: Attachments — files (2026-10-21)
**What:**
- `POST /api/attachments` (form with a file): `attachable_type` (customer, rental, vehicle, vehicle_document, vehicle_damage), `attachable_id`, `category`, `file`.
- `GET /api/attachments?attachable_type=customer&attachable_id=5` — files of a record, newest first.
- `GET /api/attachments/{id}/file` — opens the file (`?download=1` to download). `DELETE /api/attachments/{id}` — owner only.
**Categories:** customer: CIN front/back, passport, licence front/back, international permit · rental: signed contract, deposit proof, payment proof, photo · car document: scan · damage: photo, repair invoice.
**What happens to a file:**
1. Logged in? Your agency's record? Category fits the record?
2. Type checked **from the content** (JPG, PNG, WEBP, PDF), max 10 MB.
3. Photos are **re-drawn as JPEG, max 1600 px** (4000×3000, 3 MB → 1600×1200, ~0.3 MB), phone rotation applied, EXIF/GPS removed. PDFs kept as they are (must start with `%PDF-`).
4. **Encrypted** with the app key and saved under a random name: `agency-1/customer/9f3a…c2.jpg.enc`.
5. Opening it decrypts it again, only for logged-in users of the same agency, with "don't cache" headers. Opening an ID document leaves a **"viewed"** footprint.
**Also:** the activity log now records **deletions** too (before, deleting a document or damage left no trace).
**⚠️ APP_KEY:** files are encrypted with `APP_KEY` from `.env` → lose or change it = all files unreadable forever. Written in `docs/deployment-checklist.md`.
**Files:** `database/migrations/..._create_attachments_table.php`, `app/Models/Attachment.php`, `app/Enums/AttachmentCategory.php`, `app/Support/ImageShrinker.php`, `app/Policies/AttachmentPolicy.php`, `app/Http/Controllers/Api/AttachmentController.php`, `app/Http/Resources/AttachmentResource.php`, `app/Models/Concerns/LogsActivity.php` (deleted), `config/filesystems.php` (`serve` off), `docs/deployment-checklist.md`, `tests/Feature/AttachmentTest.php`
**Laravel concepts:**
- **Storage facade + disks** = one API for "local disk now, cloud storage later" (change the config, not the code).
- **`Crypt::encryptString`** = encryption with the app key (AES-256).
- **`multipart/form-data`** = how a form sends files (`-F file=@photo.jpg` with curl).
- **`Storage::fake()`** in tests = a temporary disk, nothing real is written.
- **`Rule::enum(...)->only([...])`** = only some values of an enum are allowed.
**Lessons:**
- **Defence in depth:** a PHP script renamed `photo.jpg` is stopped by the content check; and even if something passed it, the server can't re-draw it as an image → refused again. Two locks are better than one.
- Laravel's *fake* uploaded files report their type from the **name**, real uploads from the **content** → to test the content check, the test uses a real file on disk.
**My notes:**

### Decision — JPEG, not WebP (2026-10-21)
WebP is ~25–35% smaller than JPEG, but for us it saves only ~2 GB/year (the big saving was shrinking 3–5 MB photos to ~0.3 MB). Our files often **leave the app** (emailed to insurers / police, printed, opened on old PCs) where JPEG always works and WebP not always.
**Decision:** keep **JPEG for documents** (ID, licence, contract, cheque). Use **WebP for car photos** later (QR phone upload: many photos, only viewed in the app). Switching is one line in `ImageShrinker` (+ JPEG fallback).
**My notes:**

### Step 3: Hand-over (2026-10-21)
**What:**
- `GET /api/rentals/{id}/handover` — **pre-check** for the hand-over page: can the car leave? problems and warnings, the car's current km, and its **open damage** (already drawn on the diagram).
- `POST /api/rentals/{id}/handover` `{ at?, mileage, fuel (0–4 quarters), remarks?, new_damages?, payment? }` → rental **Active**, car **Rented**, car km updated.
**Damage at hand-over:** the car's open damage is **copied onto the rental** (frozen — the contract keeps it even if the car is repaired later); new damage noticed now goes on the **car's record** (linked to this rental) and onto the rental's copy.
**What blocks it:** not a reservation; car still out with another customer (e.g. overdue); car archived / in maintenance; **insurance or inspection expired or missing**; customer blocked since the booking; driver not allowed on the hand-over day; km lower than the car's km; hand-over time in the future. Vignette / registration problems and papers expiring before the return → warnings only.
**Files:** `database/migrations/..._create_rental_damages_table.php`, `..._add_rental_id_to_vehicle_damages_table.php`, rentals migration (hand-over / return columns), `app/Models/RentalDamage.php`, `Rental.php`, `VehicleDamage.php`, `app/Support/RentalHandover.php`, `app/Http/Controllers/Api/RentalHandoverController.php`, `app/Http/Resources/RentalResource.php`, `app/Casts/UtcDateTime.php`, `tests/Feature/Rentals/RentalHandoverTest.php`
**Laravel concepts:**
- **Frozen copy vs live record** = `rental_damages` never changes; `vehicle_damages` follows the car (repaired...). Same idea as the frozen daily price on the rental.
- **Validating arrays** (`new_damages.*.x`) = rules for every item of a list.
- **Named arguments** (`known: true`) = readable calls in PHP 8.
- **Immutable dates** (`CarbonImmutable`) = changing the timezone gives a *new* date, the original stays the same.
**Lessons:**
- A date object returned by our cast could be changed by mistake (`->setTimezone()` changed it for everyone reading the model afterwards) → the cast now returns immutable dates.
- Again the testing trap: "another agency" data must be created while nobody is logged in.
**My notes:**

