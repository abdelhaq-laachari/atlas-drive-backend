<?php

namespace App\Http\Controllers\Api;

use App\Enums\ChargeDecision;
use App\Enums\ChargeKind;
use App\Http\Controllers\Controller;
use App\Http\Resources\RentalResource;
use App\Models\Rental;
use App\Models\Vehicle;
use App\Rules\WholeDirhams;
use App\Support\Money;
use App\Support\RentalBooking;
use App\Support\RentalDays;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * POST /api/rentals/{rental}/extend  { "return_at": "2026-11-15 10:00", "amount": 270, "reason": "..." }
 * New return time (agency local time). Suggested charge = extra days x the rental's daily price;
 * the manager may charge less or nothing, with a reason.
 */
class RentalExtensionController extends Controller
{
    public function __invoke(Request $request, Rental $rental): JsonResponse
    {
        Gate::authorize('update', $rental);

        abort_unless($rental->isOpen(), 409, 'Only a reservation or a rental in progress can be extended.');

        $validated = $request->validate([
            'return_at' => ['required', 'date_format:Y-m-d H:i'],
            'amount' => ['nullable', new WholeDirhams],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $agency = $request->user()->agency;
        $timezone = $agency->timezone;
        $newReturn = CarbonImmutable::createFromFormat('Y-m-d H:i', $validated['return_at'], $timezone);
        $currentReturn = $rental->return_at->toImmutable()->setTimezone($timezone);
        $pickup = $rental->pickup_at->toImmutable()->setTimezone($timezone);

        if ($newReturn->lte($currentReturn)) {
            throw ValidationException::withMessages([
                'return_at' => 'The new return must be later than the current return ('.$currentReturn->format('d/m/Y H:i').').',
            ]);
        }
        if ($newReturn->lte(now())) {
            throw ValidationException::withMessages(['return_at' => 'The new return must be in the future.']);
        }

        $newDays = RentalDays::between($pickup, $newReturn);
        $extraDays = max(0, $newDays - $rental->days);
        $suggested = Money::round($extraDays * (float) $rental->daily_price);
        $amount = $validated['amount'] ?? $suggested;

        if (ChargeDecision::for($suggested, $amount)->needsReason() && blank($validated['reason'] ?? null)) {
            throw ValidationException::withMessages(['reason' => 'Give a reason for charging less than suggested.']);
        }

        $booking = new RentalBooking($agency);

        DB::transaction(function () use ($rental, $booking, $pickup, $newReturn, $newDays, $extraDays, $suggested, $amount, $validated) {
            // Lock the car: nobody may book it for these new days while we extend.
            $vehicle = Vehicle::whereKey($rental->vehicle_id)->lockForUpdate()->first();

            if ($conflict = $booking->conflicts($vehicle, $pickup, $newReturn, $rental->id)->first()) {
                throw ValidationException::withMessages(['return_at' => $booking->conflictMessage($vehicle, $conflict)]);
            }

            $rental->addCharge(
                ChargeKind::Extension,
                "Extension +{$extraDays} ".($extraDays === 1 ? 'day' : 'days').' (until '.$newReturn->format('d/m/Y H:i').')',
                $suggested,
                $amount,
                $validated['reason'] ?? null,
            );

            $rental->return_at = $newReturn;
            $rental->days = $newDays;
            $rental->save();
        });

        return (new RentalResource($rental->loadDetails()))
            ->additional(['meta' => ['warnings' => $booking->documentWarnings($rental->vehicle, $newReturn)]])
            ->response();
    }
}
