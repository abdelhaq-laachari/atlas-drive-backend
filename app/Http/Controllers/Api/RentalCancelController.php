<?php

namespace App\Http\Controllers\Api;

use App\Enums\ChargeDecision;
use App\Enums\ChargeKind;
use App\Enums\RentalStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\RentalResource;
use App\Models\Rental;
use App\Rules\WholeDirhams;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * POST /api/rentals/{rental}/cancel  { "reason": "...", "fee": 200 }
 * Only a reservation can be cancelled. Its price lines are waived, an optional cancellation fee
 * is charged, the deposit is given back and the car is freed (unless another booking is waiting).
 * A negative balance afterwards = money to refund (POST /payments with "refund": true).
 */
class RentalCancelController extends Controller
{
    public function __invoke(Request $request, Rental $rental): RentalResource
    {
        Gate::authorize('update', $rental);

        abort_unless($rental->isReserved(), 409, 'Only a reservation can be cancelled. A car already handed over must be returned.');

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'fee' => ['nullable', new WholeDirhams],
        ]);

        DB::transaction(function () use ($rental, $validated) {
            foreach ($rental->charges as $charge) {
                if ((float) $charge->amount > 0) {
                    $charge->amount = 0;
                    $charge->decision = ChargeDecision::Waive;
                    $charge->reason = 'Reservation cancelled';
                    $charge->save();
                }
            }

            $fee = (int) ($validated['fee'] ?? 0);
            if ($fee > 0) {
                $rental->addCharge(ChargeKind::Cancellation, 'Cancellation fee', $fee, $fee);
            }

            $rental->status = RentalStatus::Cancelled;
            $rental->cancelled_at = now();
            $rental->cancel_reason = $validated['reason'];
            if ((float) $rental->deposit_amount > 0) {
                $rental->deposit_returned_at = now();
            }
            $rental->save();

            $rental->vehicle->syncStatusWithRentals();
        });

        return new RentalResource($rental->loadDetails());
    }
}
