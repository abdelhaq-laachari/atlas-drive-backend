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
- Build module by module: Foundation → Vehicles → Customers → Rentals → Invoices → Maintenance → Settings → Booking requests → Super admin (SaaS) → Subscriptions & payments.
- Super admin (SaaS, after the first client is live): separate `admins` table and its own login — never a role on `users`, so it stays outside the agency guard (`BelongsToAgency`). Manages agencies (create agency + owner, suspend/activate, plan & subscription status) and sees usage numbers (cars, rentals, staff, last login), not agencies' customer personal data (Moroccan law 09-08). Optional "view as agency" support access must be logged.

## Business rules agreed

- One location, ~30 cars. Pricing is per car (daily / weekly / monthly, deposit, extra-km price). Seasonal pricing later.
- Vehicle documents: insurance, technical inspection, annual tax, registration → status Valid / Expiring soon / Expired.
- Rental lifecycle: Reserved → Active → Returned/Closed, plus Cancelled and Overdue. Vehicle status follows the rental.
- Driver: minimum age 21, licence held at least 2 years.
- Day counting: calendar days + 1 extra day if the return time is later than the pickup time.
- Late: an active rental is overdue when more than 60 minutes (grace) past its return time, measured in minutes.
- Deposit: any method (cash, card, cheque, transfer) with a reference; a numbered deposit receipt (DEP-xxxx) is issued.
- Hand-over / return: time, mileage, fuel in quarters, damage marks `{x, y (normalized), type, size, area, stage: handover|return}`, remarks.
- Return charges (extra km, late days, fuel at 150 MAD/quarter sample, damages) are **suggested**: the manager can charge, reduce or waive each one with a reason. Never force a charge — the owner often makes exceptions for loyal customers.
- Damages are also recorded on the car (open / repaired); a rental keeps a frozen copy.
- Customers: individual or company. Design data so one contract can later hold several cars with monthly billing (corporate long-term leasing).
- Later: booking requests from the public website (staff confirm → Reserved, or reject), notifications, repair cost vs amount charged.

## Working rules

- Never commit unless the user asks; the user commits and pushes themselves.
- The user is a junior developer (MERN background): explain Laravel concepts briefly when introducing them.

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
