# Atlas Drive — backend

Back office for a car-rental agency (first customer: a friend's agency in Morocco; later sold as a SaaS to other agencies).

## What this project is

- Laravel 13 + MySQL (`atlas_drive`, tests on `atlas_drive_test`). The backend logic (migrations, models, business rules, routes, tests) is built here first.
- The UI prototype lives in a separate Vuexy v8 project (Vue 2): `~/dashboard/Dashboard_maska/vuexy-admin-v8.2.2/vue-laravel-version/vuexy-bootstrapvue/full-version`. Its sample data and stores (`vehicleStore.js`, `rentalStore.js`, `rentalOptions.js`…) show the agreed data shapes and rules.
- When the user buys the new Vuexy (Laravel + Vue 3), this backend is **copied into that project** → one single project folder. Keep code standard Laravel so it moves easily (no exotic packages).

## Conventions

- Vue pages talk to Laravel through JSON routes under `/api`, auth with Sanctum (cookie session, same domain).
- Every tenant table has `agency_id` from day one (SaaS later). Agency facts (currency, rules, grace minutes, fuel price…) come from agency settings, not hardcoded values.
- Money in MAD, stored as `decimal(10,2)` everywhere (no centimes, no dividing by 100). Amounts the user types (prices, deposits, charges) are whole dirhams only — validated in the Vue form AND in Laravel (Form Request). Calculated amounts (VAT, splits, discounts) may keep cents, rounded to 2 decimals by one shared helper.
- Every business rule has a feature/unit test (`php artisan test`).

## API conventions (Foundation)

- Auth: Sanctum SPA cookie session. Vue calls `GET /sanctum/csrf-cookie`, then `POST /api/login`; `GET /api/me` returns the user + agency + agency rules; `POST /api/logout`. All back-office routes go in the `auth:sanctum` group in `routes/api.php`. Production domain must be in `SANCTUM_STATEFUL_DOMAINS`.
- Tenancy: every tenant model uses `App\Models\Concerns\BelongsToAgency` (auto `agency_id` + agency filter for the logged-in user). Code running with nobody logged in (seeders, commands, scheduled tasks, jobs) is NOT filtered and must filter by agency itself. Migration: `$table->foreignId('agency_id')->constrained()->cascadeOnDelete();`
- Roles: `App\Enums\Role` (owner, staff). `role` and `agency_id` are never mass-assignable.
- Input: one Form Request per write action. Typed money uses `new App\Rules\WholeDirhams`; calculated money goes through `App\Support\Money::round()`.
- Output: always an API Resource → `{ "data": ... }`; lists are paginated → `{ data, links, meta }`. Never return models directly.
- JSON keys are snake_case (`fleet_code`, `daily_price`); the Vue stores map them. Money values come as strings like `"270.00"`.
- Permissions live in Policies (`app/Policies`), checked in the Form Request `authorize()` or with `Gate::authorize()` in the controller.
- Tests must not depend on random factory values they then change (set the starting value explicitly) and freeze time (`travelTo`) when dates matter.
- Model events (`creating`) fill automatic values (agency_id, fleet number): never use `WithoutModelEvents` / `saveQuietly()` on those models.
- Activity log (footprints): every tenant model also uses `App\Models\Concerns\LogsActivity` and is added to the morph map in `AppServiceProvider` (`'vehicle' => Vehicle::class`). Define `activityLabel()` (e.g. "V-011 Dacia Logan") and, when useful, `activityAction()` (archived, status_changed...). Sensitive personal values (CIN, passport, licence numbers...) go in `$activityMasked`. Logs are read-only (no edit/delete, ever); created / updated / deleted are logged automatically; `GET /api/activity` is owner-only. Column is `diff`, never `changes` (Eloquent internal name).
- Errors (Laravel defaults + one override): 401 `Unauthenticated.`, 403 forbidden (role), 404 always `{ "message": "Not found." }` (no model/route names), 409 not allowed in the current state (e.g. editing an archived car), 419 CSRF expired, 422 `{ message, errors: { field: [..] } }`, 429 too many attempts.
- Dates: stored in UTC, sent as ISO 8601; business rules (day counting, late) are computed in the agency's timezone (`agencies.timezone`, default Africa/Casablanca).
- Build module by module: Foundation → Vehicles → Customers → Rentals → Invoices → Maintenance → Settings → Booking requests → Super admin (SaaS) → Subscriptions & payments.
- Super admin (SaaS, after the first client is live): separate `admins` table and its own login — never a role on `users`, so it stays outside the agency guard (`BelongsToAgency`). Manages agencies (create agency + owner, suspend/activate, plan & subscription status) and sees usage numbers (cars, rentals, staff, last login), not agencies' customer personal data (Moroccan law 09-08). Support access: see the owner-granted rule below.
- Accounts (agreed 2026-10-21, build in Settings → Users & roles): no public sign-up, login page only. Super admin creates agency + owner; owner creates staff; staff cannot create accounts or change any role (incl. their own). First password = temporary password + forced change at first login (`must_change_password`). Everyone can change their own password (current password required). Staff who leave are **deactivated, never deleted** (history keeps who did what). Owner can reset a staff password; super admin can reset an owner's. An owner cannot deactivate themselves or the last owner. Later: invitation link by email.
- Two-step verification: authenticator app (TOTP — Google Authenticator etc., free, no API) via Laravel Fortify, with recovery codes. Build before going live with real data. No SMS.
- Super admin and agency data (law 09-08: the agency is responsible for its data, we are its service provider): the super admin dashboard shows **only usage numbers** (cars, rentals, staff, last login) — no page lists an agency's customers, rentals or activity log, so nothing can be seen by accident. Support access only when the **owner grants it** in their Settings ("Allow support access", time-limited, e.g. 24 h, auto-expires); every support access is logged and **visible to the owner** ("Atlas Drive support accessed your account on ..."). Staff must be told their actions are recorded (note on login page / owner informs them). Contract / terms: provider accesses data only for support, security or legal reasons. Dev uses fake seeded data only, never real customers on a laptop.
- Attachments (files): one shared `attachments` table (polymorphic: customer, rental, vehicle, vehicle_document, vehicle_damage) with a category. Files on the private `local` disk (never public URLs), random names, **all encrypted at rest**, photos shrunk + re-encoded to JPEG (max 1600 px, strips EXIF/GPS; JPEG on purpose for documents that get emailed/printed — WebP only for future car photos), PDFs kept as is; JPG/PNG/WEBP/PDF only, max 10 MB (server `upload_max_filesize` / `post_max_size` must allow it). Download only via `GET /api/attachments/{id}/file` (auth + agency guard). Files are encrypted with APP_KEY: **losing or changing APP_KEY makes every file unreadable** (see `docs/deployment-checklist.md`). Upload / delete / viewing ID documents leave footprints; delete = owner only. Today: upload from the PC (scanner, WhatsApp Web downloads). **Later:** phone photo upload via a temporary QR link for one rental (no Bluetooth/AirDrop needed), automatic deletion of ID copies X years after the last rental (client question), contract PDF generated by the backend.
- Open client questions live in `docs/client-questions.md` (add new ones there; the user asks them before meetings).

## Business rules agreed

- One location, ~30 cars. Pricing is per car (daily / weekly / monthly, deposit, extra-km price). Seasonal pricing later.
- Vehicle documents: insurance, technical inspection, vignette (annual tax), registration card → status Valid / Expiring soon / Expired / Missing, computed by `App\Support\DocumentOverview` in the agency's timezone with `agencies.document_expiring_soon_days` (valid up to and including the expiry date). Renewing adds a row; the newest issue date per type is current, older rows are history. Staff add/correct documents, only the owner deletes. Scanned files (PDF/photo) not built yet.
- Extra-km fee is a per-km rate and MAY have cents (e.g. 1.50 MAD/km) — the only typed money exception to whole dirhams; the resulting charge goes through `Money::round()`.
- Cars are never deleted once they have history: they are **archived** (date + reason), hidden from lists and not rentable; rentals, invoices and documents keep pointing to them. Only a car with no history can be truly deleted.
- Rental lifecycle: Reserved → Active → Returned/Closed, plus Cancelled and Overdue. Vehicle status follows the rental.
- Driver: minimum age 21, licence held at least 2 years (agency settings `driver_min_age`, `licence_min_years`), checked on the **pickup date** by `App\Support\DriverCheck` (full years like birthdays — same rule as the Vue prototype's `fullYearsBetween`). No licence, blocked or archived → cannot drive. `GET /api/customers/{id}/driver-check?on=YYYY-MM-DD`. The Rentals module must run the same check when a rental is created.
- Day counting: calendar days + 1 extra day if the return time is later than the pickup time.
- Late: an active rental is overdue when more than 60 minutes (grace) past its return time, measured in minutes.
- Rental money (agreed 2026-10-21): every amount is a **charge line** (`rental_charges`: kind, label, suggested, amount, decision charge|reduce|waive, reason) — the rental total is the sum of lines; payments are separate lines (`rental_payments`). Reducing or waiving needs a reason; everyone may do it (logged; owner sees it in the activity log — client question #3 may restrict it later).
- Cancel (reservations only, reason required): price lines become waived, optional cancellation fee line, deposit marked given back, car status re-synced (`Vehicle::syncStatusWithRentals()` — never overrides maintenance / out of service). Extend (reserved or active): suggested = extra days x the rental's frozen daily price, checked against the next booking. Payments: positive amounts up to the balance; refunds stored as negative amounts, up to what was paid; a cancelled rental only accepts refunds.
- Hand-over (`App\Support\RentalHandover` = pre-check + checks; `GET|POST /api/rentals/{id}/handover`): reservations only; blocked if the car is still out with another rental, archived, in maintenance / out of service, insurance or inspection expired/missing, customer blocked, driver not eligible on the hand-over day, km lower than the car's km. Stores time / km / fuel (quarters 0-4) / remarks on the rental; the car's open damage is frozen on `rental_damages` (known = true), new marks become `vehicle_damages` (found_stage handover, rental_id) + frozen copy (known = false). Car → Rented, car km updated. `UtcDateTime` returns **immutable** dates (setTimezone never changes the cached value).
- Return (`App\Support\ReturnFacts`; `GET|POST /api/rentals/{id}/return`): active rentals only. Facts: km over (included km/day x billed days; null = unlimited), late minutes + extra days (beyond the agency grace; same day rule), fuel quarters missing, unused days if early. Required decision per fact that happened and per new damage (`charges.{extra_km|late|fuel}.amount`, `new_damages.*.charge.amount`; 0 = no charge = waive); optional `extra_charges` (cleaning, fine). Settlement: `deposit_kept` (≤ deposit, ≤ due) recorded as a payment with method deposit, optional payment (≤ due); the rest of the deposit is marked returned. New damage → car record (found_stage return, rental_id) + frozen copy. Car km updated, status re-synced.
- Expired documents: insurance / technical inspection expired (or missing) → **warning** at booking, **blocked** at hand-over (illegal to drive). Vignette / registration → warning only.
- Rental times: the API receives and returns local agency time for pickup/return input (`Y-m-d H:i`, agency timezone), stores UTC; day counting and lateness use the agency's local date/time. **Date-times entered by people use the `App\Casts\UtcDateTime` cast** (not `datetime`): Eloquent's own cast stores the clock reading without converting, so 10:00+01:00 would be saved as 10:00 UTC. Never hardcode an offset: PHP's tzdata 2026.3 says Morocco is UTC+0 since 2026-09-20 (no more clock changes; confirmed by the user 2026-10-21, but summer time may come back). **If Morocco changes its clock rules:** update PHP / tzdata on the server; then run a one-time command that shifts **future** reservations made before the announcement (stored UTC with the old offset) so their local time stays the same. Past rentals are never touched. Timezone tests use `Africa/Lagos` (UTC+1 all year) so the conversion is really tested.
- Deposit: any method (cash, card, cheque, transfer) with a reference; a numbered deposit receipt (DEP-xxxx) is issued.
- Hand-over / return: time, mileage, fuel in quarters, damage marks `{x, y (normalized), type, size, area, stage: handover|return}`, remarks.
- Return (decided 2026-10-22): the system shows **facts** (km over the allowance, late time + extra days, fuel quarters missing, new damage) and **suggests no price** — negotiation and forgiving loyal customers are the agent's call. For each fact the agent must record a decision: **charge X MAD** or **no charge** (0), with an optional note, so the owner sees forgiven vs forgotten. Price suggestions (fuel price per quarter, car's extra-km fee, daily price for late days) exist in `App\Support\ReturnFacts` but are **off** (`agencies.suggest_return_prices`, default false). Never force a charge.
- Damages are also recorded on the car (open / repaired); a rental keeps a frozen copy. Built: `vehicle_damages` (x/y 0–1, area from `App\Support\CarParts`, type, size, found_at, found_stage handover|return|null, status open|repaired, repaired_at, repair_cost, repair_note). Staff record and mark repaired; only the owner reopens or deletes. The Rentals module must add `rental_id` (found in) and create damage at hand-over / return; the customer charge belongs to the rental, the repair cost to the damage.
- Customers: built for individuals only (`customers.type` = individual; codes C-001 per agency via `NumberedPerAgency`). ID number unique per agency on `id_key` (letters+digits); phone stored as `+` and digits (search ignores leading zeros: 0611… = +212 611…). ID and licence numbers are masked in the activity log. Licence fields optional (a payer may not drive). Staff add/edit; only the owner blocks (blocklist with reason — blocked customers can't start a new rental) and archives (never deleted).
- Companies (later, agreed design): company = who signs and pays (name, ICE, RC, contact); drivers are always individual customers (ID + licence + age checks), optionally linked to a company. A rental = one paying customer + one or more drivers (also covers "extra driver"). Design data so one contract can later hold several cars with monthly billing (corporate long-term leasing).
- Later: booking requests from the public website (staff confirm → Reserved, or reject), notifications, repair cost vs amount charged.

## Working rules

- Never commit unless the user asks; the user commits and pushes themselves.
- The user is a junior developer (MERN background): explain Laravel concepts briefly when introducing them.
- After every finished step (or decision), add an entry to `docs/learning-journal.md` in the same format (What / Why / Files / Laravel concepts / Lesson / empty "My notes:" line for the user). Never edit the user's own "My notes" text.

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.5. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This project uses PHPUnit. Create tests with `php artisan make:test --phpunit {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/phpunit` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

</laravel-boost-guidelines>
