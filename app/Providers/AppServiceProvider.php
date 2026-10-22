<?php

namespace App\Providers;

use App\Models\Agency;
use App\Models\Attachment;
use App\Models\Customer;
use App\Models\MaintenanceJob;
use App\Models\MaintenancePlan;
use App\Models\Rental;
use App\Models\RentalCharge;
use App\Models\RentalPayment;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDamage;
use App\Models\VehicleDocument;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Fortify;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // We use Fortify only for two-step verification: none of its routes (login, sign-up...).
        Fortify::ignoreRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Short names stored in the database instead of PHP class names (activity_logs.subject_type).
        Relation::enforceMorphMap([
            'agency' => Agency::class,
            'attachment' => Attachment::class,
            'customer' => Customer::class,
            'maintenance_job' => MaintenanceJob::class,
            'maintenance_plan' => MaintenancePlan::class,
            'rental' => Rental::class,
            'rental_charge' => RentalCharge::class,
            'rental_payment' => RentalPayment::class,
            'user' => User::class,
            'vehicle' => Vehicle::class,
            'vehicle_document' => VehicleDocument::class,
            'vehicle_damage' => VehicleDamage::class,
        ]);

        // Money reports (costs, damage profit / loss): owner only for now (client question #1).
        Gate::define('viewReports', fn (User $user) => $user->isOwner());

        // Password rules for every new password. On the live server, also refuse passwords found in
        // public data leaks (Have I Been Pwned: only the first 5 characters of the password's SHA-1
        // fingerprint are sent, never the password).
        Password::defaults(function () {
            $rule = Password::min(10)->letters()->mixedCase()->numbers();

            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });

        // 5 tries per minute for the 6-digit code (per pending login, else per IP)
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by('2fa|'.($request->session()->get('two_factor_login.id') ?? $request->ip()));
        });

        // 5 login attempts per minute for each email + IP (slows down password guessing).
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip());
        });
    }
}
