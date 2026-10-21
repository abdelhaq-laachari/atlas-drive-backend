<?php

namespace App\Http\Controllers\Api;

use App\Enums\DamageSize;
use App\Enums\DamageStage;
use App\Enums\DamageStatus;
use App\Enums\DamageType;
use App\Enums\PaymentMethod;
use App\Enums\RentalStatus;
use App\Enums\VehicleStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\RentalResource;
use App\Http\Resources\VehicleDamageResource;
use App\Models\Rental;
use App\Models\RentalDamage;
use App\Models\RentalPayment;
use App\Models\Vehicle;
use App\Models\VehicleDamage;
use App\Rules\WholeDirhams;
use App\Support\CarParts;
use App\Support\Money;
use App\Support\RentalHandover;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The customer takes the car: record its condition, start the rental, mark the car Rented.
 */
class RentalHandoverController extends Controller
{
    /**
     * GET /api/rentals/{rental}/handover — pre-check for the hand-over page:
     * can the car leave now, its current km, and the damage already on it (shown on the drawing).
     */
    public function show(Request $request, Rental $rental): JsonResponse
    {
        Gate::authorize('update', $rental);

        $check = (new RentalHandover($request->user()->agency))->check($rental->loadDetails(), CarbonImmutable::now());
        $openDamages = $rental->vehicle->damages()->where('status', DamageStatus::Open)->orderBy('found_at')->orderBy('id')->get();

        return response()->json(['data' => [
            'can_hand_over' => $check['errors'] === [],
            'errors' => $check['errors'],
            'warnings' => $check['warnings'],
            'current_mileage' => $rental->vehicle->mileage,
            'open_damages' => VehicleDamageResource::collection($openDamages),
        ]]);
    }

    /**
     * POST /api/rentals/{rental}/handover
     * { at?, mileage, fuel (0-4 quarters), remarks?, new_damages?: [{x, y, area, type, size, note?}], payment? }
     */
    public function store(Request $request, Rental $rental): JsonResponse
    {
        Gate::authorize('update', $rental);

        abort_unless($rental->status === RentalStatus::Reserved, 409, "{$rental->rental_code} is {$rental->status->value}: only a reservation can be handed over.");

        $agency = $request->user()->agency;
        $vehicle = $rental->vehicle;

        $validated = $request->validate([
            'at' => ['nullable', 'date_format:Y-m-d H:i'], // agency local time; empty = now
            'mileage' => ['required', 'integer', 'min:'.$vehicle->mileage, 'max:2000000'],
            'fuel' => ['required', 'integer', 'between:0,4'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'new_damages' => ['nullable', 'array', 'max:50'],
            'new_damages.*.x' => ['required', 'numeric', 'between:0,1'],
            'new_damages.*.y' => ['required', 'numeric', 'between:0,1'],
            'new_damages.*.area' => ['required', Rule::in(CarParts::ALL)],
            'new_damages.*.type' => ['required', Rule::enum(DamageType::class)],
            'new_damages.*.size' => ['required', Rule::enum(DamageSize::class)],
            'new_damages.*.note' => ['nullable', 'string', 'max:500'],
            'payment' => ['nullable', 'array'],
            'payment.amount' => ['required_with:payment', new WholeDirhams, 'not_in:0'],
            'payment.method' => ['required_with:payment', Rule::in(PaymentMethod::customerMethods())],
            'payment.reference' => ['nullable', 'string', 'max:50'],
        ], [
            'mileage.min' => "The km can't be lower than the car's current km ({$vehicle->mileage}).",
        ]);

        $at = isset($validated['at'])
            ? CarbonImmutable::createFromFormat('Y-m-d H:i', $validated['at'], $agency->timezone)
            : CarbonImmutable::now();

        if ($at->gt(now()->addMinutes(5))) {
            throw ValidationException::withMessages(['at' => 'The hand-over time cannot be in the future.']);
        }

        $this->checkPayment($rental, $validated['payment'] ?? null);

        $check = (new RentalHandover($agency))->check($rental, $at);
        if ($check['errors'] !== []) {
            throw ValidationException::withMessages($check['errors']);
        }

        DB::transaction(function () use ($rental, $validated, $at) {
            // Lock the car while it changes hands.
            $vehicle = Vehicle::whereKey($rental->vehicle_id)->lockForUpdate()->first();

            $rental->status = RentalStatus::Active;
            $rental->handed_over_at = $at;
            $rental->handover_mileage = $validated['mileage'];
            $rental->handover_fuel = $validated['fuel'];
            $rental->handover_remarks = $validated['remarks'] ?? null;
            $rental->save();

            // Damage already on the car: copied onto the rental (frozen for the contract)
            $vehicle->damages()->where('status', DamageStatus::Open)->orderBy('found_at')->orderBy('id')->get()
                ->each(fn (VehicleDamage $damage) => RentalDamage::freeze($rental, $damage, DamageStage::Handover, known: true));

            // Damage first noticed now: on the car's record too, linked to this rental
            foreach ($validated['new_damages'] ?? [] as $mark) {
                $damage = new VehicleDamage([...$mark, 'found_at' => $at]);
                $damage->found_stage = DamageStage::Handover;
                $damage->rental()->associate($rental);
                $damage->vehicle()->associate($vehicle);
                $damage->save();

                RentalDamage::freeze($rental, $damage, DamageStage::Handover, known: false);
            }

            if ($payment = $validated['payment'] ?? null) {
                $line = new RentalPayment;
                $line->rental()->associate($rental);
                $line->amount = $payment['amount'];
                $line->method = PaymentMethod::from($payment['method']);
                $line->reference = $payment['reference'] ?? null;
                $line->paid_at = $at;
                $line->save();
            }

            $vehicle->status = VehicleStatus::Rented;
            $vehicle->mileage = max($vehicle->mileage, (int) $validated['mileage']);
            $vehicle->save();
        });

        return (new RentalResource($rental->loadDetails()))
            ->additional(['meta' => ['warnings' => $check['warnings']]])
            ->response();
    }

    /**
     * @param  array{amount?: mixed, method?: string, reference?: ?string}|null  $payment
     */
    private function checkPayment(Rental $rental, ?array $payment): void
    {
        if (! $payment) {
            return;
        }

        $method = PaymentMethod::from($payment['method']);
        if ($method->needsReference() && blank($payment['reference'] ?? null)) {
            throw ValidationException::withMessages([
                'payment.reference' => 'A reference is required for '.$method->value.' (cheque no., card authorisation, transfer ref.).',
            ]);
        }

        $rental->load(['charges', 'payments']);
        if (Money::cents($payment['amount']) > Money::cents($rental->balance())) {
            throw ValidationException::withMessages(['payment.amount' => "That's more than what is due ({$rental->balance()} MAD)."]);
        }
    }
}
