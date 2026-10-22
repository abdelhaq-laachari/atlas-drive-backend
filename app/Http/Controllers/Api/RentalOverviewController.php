<?php

namespace App\Http\Controllers\Api;

use App\Enums\RentalStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\RentalResource;
use App\Models\Rental;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * GET /api/rentals/overview — the day at the agency (dashboard and rentals page), in the agency's day:
 * - pickups_today: reservations to hand over today, and rentals already handed over today
 * - returns_today: rentals due back today, and rentals already returned today
 * - overdue: cars that should be back (past return time + grace)
 * - upcoming: reservations starting in the next 7 days (today included)
 */
class RentalOverviewController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Rental::class);

        $agency = $request->user()->agency;
        $todayStart = CarbonImmutable::now($agency->timezone)->startOfDay();
        $tomorrowStart = $todayStart->addDay();
        $weekEnd = $todayStart->addDays(8);
        $relations = ['vehicle', 'customer', 'driver', 'charges', 'payments'];

        $pickupsToday = Rental::query()->with($relations)
            ->whereIn('status', RentalStatus::blocking())
            ->where('pickup_at', '>=', $todayStart)->where('pickup_at', '<', $tomorrowStart)
            ->orderBy('pickup_at')->get();

        $returnsToday = Rental::query()->with($relations)
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->where('status', RentalStatus::Active)
                    ->where('return_at', '>=', $todayStart)->where('return_at', '<', $tomorrowStart))
                ->orWhere(fn ($query) => $query->where('status', RentalStatus::Completed)
                    ->where('returned_at', '>=', $todayStart)->where('returned_at', '<', $tomorrowStart)))
            ->orderBy('return_at')->get();

        $overdue = Rental::query()->with($relations)
            ->overdue($agency->late_grace_minutes)
            ->orderBy('return_at')->get();

        $upcoming = Rental::query()->with($relations)
            ->where('status', RentalStatus::Reserved)
            ->where('pickup_at', '>=', $todayStart)->where('pickup_at', '<', $weekEnd)
            ->orderBy('pickup_at')->get();

        return response()->json(['data' => [
            'date' => $todayStart->toDateString(),
            'counts' => [
                'pickups_today' => $pickupsToday->count(),
                'returns_today' => $returnsToday->count(),
                'overdue' => $overdue->count(),
                'upcoming' => $upcoming->count(),
                'active' => Rental::where('status', RentalStatus::Active)->count(),
                'reserved' => Rental::where('status', RentalStatus::Reserved)->count(),
            ],
            'pickups_today' => RentalResource::collection($pickupsToday),
            'returns_today' => RentalResource::collection($returnsToday),
            'overdue' => RentalResource::collection($overdue),
            'upcoming' => RentalResource::collection($upcoming),
        ]]);
    }
}
