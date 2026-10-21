<?php

namespace App\Providers;

use App\Models\Customer;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDamage;
use App\Models\VehicleDocument;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Short names stored in the database instead of PHP class names (activity_logs.subject_type).
        Relation::enforceMorphMap([
            'customer' => Customer::class,
            'user' => User::class,
            'vehicle' => Vehicle::class,
            'vehicle_document' => VehicleDocument::class,
            'vehicle_damage' => VehicleDamage::class,
        ]);

        // 5 login attempts per minute for each email + IP (slows down password guessing).
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip());
        });
    }
}
