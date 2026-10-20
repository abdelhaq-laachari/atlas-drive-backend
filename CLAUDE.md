# Atlas Drive — backend

Back office for a car-rental agency (first customer: a friend's agency in Morocco; later sold as a SaaS to other agencies).

## What this project is

- Laravel 13 + MySQL (`atlas_drive`, tests on `atlas_drive_test`). The backend logic (migrations, models, business rules, routes, tests) is built here first.
- The UI prototype lives in a separate Vuexy v8 project (Vue 2): `~/dashboard/Dashboard_maska/vuexy-admin-v8.2.2/vue-laravel-version/vuexy-bootstrapvue/full-version`. Its sample data and stores (`vehicleStore.js`, `rentalStore.js`, `rentalOptions.js`…) show the agreed data shapes and rules.
- When the user buys the new Vuexy (Laravel + Vue 3), this backend is **copied into that project** → one single project folder. Keep code standard Laravel so it moves easily (no exotic packages).

## Conventions

- Vue pages talk to Laravel through JSON routes under `/api`, auth with Sanctum (cookie session, same domain).
- Every tenant table has `agency_id` from day one (SaaS later). Agency facts (currency, rules, grace minutes, fuel price…) come from agency settings, not hardcoded values.
- Money in MAD; pick one storage format (integer centimes or decimal(10,2)) and use it everywhere.
- Every business rule has a feature/unit test (`php artisan test`).
- Build module by module: Foundation → Vehicles → Customers → Rentals → Invoices → Maintenance → Settings → Booking requests.

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
# Laravel Application

This repository contains a Laravel application. Complete the following setup before working on the user's request.

## Prerequisites

Verify that PHP and Composer are available:

```sh
php -v
composer -V
```

If either command is unavailable, detect the user's operating system and install the prerequisites with the appropriate command:

macOS:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/mac/8.5)"
```

Windows PowerShell:

```powershell
Set-ExecutionPolicy Bypass -Scope Process -Force; [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor 3072; iex ((New-Object System.Net.WebClient).DownloadString('https://php.new/install/windows/8.5'))
```

Linux:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/linux/8.5)"
```

After installation, ask the user to restart their terminal. If the agent needs the restarted shell to continue, ask the user to reopen their terminal and rerun their original prompt.

## Agent Setup

Install Laravel Boost from the application root before making application changes:

```sh
composer require laravel/boost --dev
php artisan boost:install
```

Boost replaces these bootstrap instructions with guidelines tailored to the application. After installation, read `AGENTS.md` again and continue with the user's original request using the generated guidelines.
</laravel-boost-guidelines>
