<?php

namespace App\Http\Controllers\Api;

use App\Enums\ChargeDecision;
use App\Enums\ChargeKind;
use App\Enums\DamageSize;
use App\Enums\DamageStage;
use App\Enums\DamageType;
use App\Enums\PaymentMethod;
use App\Enums\RentalStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\RentalResource;
use App\Models\Rental;
use App\Models\RentalDamage;
use App\Models\RentalPayment;
use App\Models\Vehicle;
use App\Models\VehicleDamage;
use App\Rules\WholeDirhams;
use App\Support\CarParts;
use App\Support\Money;
use App\Support\ReturnFacts;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The car comes back. The system shows the facts (km over, late, fuel, new damage); the agent decides
 * for each one: charge an amount, or 0 = no charge (with an optional note). Then the money is settled
 * (part of the deposit kept and/or a payment), the rental is closed and the car is freed.
 */
class RentalReturnController extends Controller
{
    /**
     * GET /api/rentals/{rental}/return?at=2026-11-13 13:15&mileage=62120&fuel=2
     * Preview for the return page: the facts, the hand-over damage (drawn on the diagram), deposit, balance.
     */
    public function show(Request $request, Rental $rental): JsonResponse
    {
        Gate::authorize('update', $rental);

        $validated = $request->validate([
            'at' => ['nullable', 'date_format:Y-m-d H:i'],
            'mileage' => ['nullable', 'integer', 'min:0'],
            'fuel' => ['nullable', 'integer', 'between:0,4'],
        ]);

        $agency = $request->user()->agency;
        $rental->loadDetails();

        return response()->json(['data' => [
            'can_return' => $rental->status === RentalStatus::Active,
            'facts' => (new ReturnFacts($agency))->for(
                $rental,
                $this->returnTime($validated['at'] ?? null, $agency->timezone),
                isset($validated['mileage']) ? (int) $validated['mileage'] : null,
                isset($validated['fuel']) ? (int) $validated['fuel'] : null,
            ),
            'handover_damages' => $rental->damages->where('stage', DamageStage::Handover)->values()->map(fn ($damage) => [
                'id' => $damage->id, 'x' => $damage->x, 'y' => $damage->y, 'area' => $damage->area,
                'type' => $damage->type, 'size' => $damage->size, 'note' => $damage->note,
            ]),
            'deposit' => $rental->deposit_amount,
            'total' => $rental->total(),
            'paid' => $rental->paid(),
            'balance' => $rental->balance(),
        ]]);
    }

    /**
     * POST /api/rentals/{rental}/return
     * {
     *   at?, mileage, fuel (0-4), remarks?,
     *   charges?: { extra_km?: {amount, note?}, late?: {amount, note?}, fuel?: {amount, note?} },
     *   new_damages?: [{ x, y, area, type, size, note?, charge: {amount, note?} }],
     *   extra_charges?: [{ label, amount }],      // e.g. cleaning, traffic fine
     *   deposit_kept?, payment?: {amount, method, reference?}
     * }
     */
    public function store(Request $request, Rental $rental): JsonResponse
    {
        Gate::authorize('update', $rental);

        abort_unless($rental->status === RentalStatus::Active, 409, "{$rental->rental_code} is {$rental->status->value}: only a rental in progress can be returned.");

        $agency = $request->user()->agency;
        $rental->loadDetails();

        $validated = $request->validate([
            'at' => ['nullable', 'date_format:Y-m-d H:i'],
            'mileage' => ['required', 'integer', 'min:'.$rental->handover_mileage, 'max:2000000'],
            'fuel' => ['required', 'integer', 'between:0,4'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'charges' => ['nullable', 'array'],
            'charges.*.amount' => ['required', new WholeDirhams],
            'charges.*.note' => ['nullable', 'string', 'max:255'],
            'new_damages' => ['nullable', 'array', 'max:50'],
            'new_damages.*.x' => ['required', 'numeric', 'between:0,1'],
            'new_damages.*.y' => ['required', 'numeric', 'between:0,1'],
            'new_damages.*.area' => ['required', Rule::in(CarParts::ALL)],
            'new_damages.*.type' => ['required', Rule::enum(DamageType::class)],
            'new_damages.*.size' => ['required', Rule::enum(DamageSize::class)],
            'new_damages.*.note' => ['nullable', 'string', 'max:500'],
            'new_damages.*.charge' => ['required', 'array'],
            'new_damages.*.charge.amount' => ['required', new WholeDirhams],
            'new_damages.*.charge.note' => ['nullable', 'string', 'max:255'],
            'extra_charges' => ['nullable', 'array', 'max:10'],
            'extra_charges.*.label' => ['required', 'string', 'max:100'],
            'extra_charges.*.amount' => ['required', new WholeDirhams, 'not_in:0'],
            'deposit_kept' => ['nullable', new WholeDirhams],
            'payment' => ['nullable', 'array'],
            'payment.amount' => ['required_with:payment', new WholeDirhams, 'not_in:0'],
            'payment.method' => ['required_with:payment', Rule::in(PaymentMethod::customerMethods())],
            'payment.reference' => ['nullable', 'string', 'max:50'],
        ], [
            'mileage.min' => "The km can't be lower than at hand-over ({$rental->handover_mileage}).",
            'new_damages.*.charge.required' => 'Decide for this damage: charge an amount, or 0 for no charge.',
        ]);

        $at = $this->returnTime($validated['at'] ?? null, $agency->timezone);
        if ($at->gt(now()->addMinutes(5))) {
            throw ValidationException::withMessages(['at' => 'The return time cannot be in the future.']);
        }
        if ($at->lt($rental->handed_over_at)) {
            throw ValidationException::withMessages(['at' => 'The return cannot be before the hand-over.']);
        }

        $facts = (new ReturnFacts($agency))->for($rental, $at, (int) $validated['mileage'], (int) $validated['fuel']);
        $lines = $this->chargeLines($facts, $validated);
        [$depositKept, $payment] = $this->settlement($rental, $lines, $validated);

        DB::transaction(function () use ($rental, $validated, $at, $lines, $depositKept, $payment) {
            $vehicle = Vehicle::whereKey($rental->vehicle_id)->lockForUpdate()->first();

            $rental->status = RentalStatus::Completed;
            $rental->returned_at = $at;
            $rental->return_mileage = $validated['mileage'];
            $rental->return_fuel = $validated['fuel'];
            $rental->return_remarks = $validated['remarks'] ?? null;
            $rental->deposit_kept = $depositKept;
            if ((float) $rental->deposit_amount > 0) {
                $rental->deposit_returned_at = $at; // the rest of the deposit goes back
            }
            $rental->save();

            // New damage: on the car's record (linked to this rental) and on the rental's frozen copy
            $damages = [];
            foreach ($validated['new_damages'] ?? [] as $index => $mark) {
                $damage = new VehicleDamage([...collect($mark)->except('charge')->all(), 'found_at' => $at]);
                $damage->found_stage = DamageStage::Return;
                $damage->rental()->associate($rental);
                $damage->vehicle()->associate($vehicle);
                $damage->save();
                $damages[$index] = $damage;

                RentalDamage::freeze($rental, $damage, DamageStage::Return, known: false);
            }

            foreach ($lines as $line) {
                $damage = isset($line['damage_index']) ? $damages[$line['damage_index']] : null;
                $rental->addCharge($line['kind'], $line['label'], $line['suggested'], $line['amount'], $line['note'], $damage);
            }

            if ($depositKept > 0) {
                $this->addPayment($rental, $depositKept, PaymentMethod::Deposit, null, $at);
            }
            if ($payment) {
                $this->addPayment($rental, $payment['amount'], PaymentMethod::from($payment['method']), $payment['reference'] ?? null, $at);
            }

            $vehicle->mileage = max($vehicle->mileage, (int) $validated['mileage']);
            $vehicle->save();
            $vehicle->syncStatusWithRentals(); // Available, or Reserved if another booking is waiting
        });

        return (new RentalResource($rental->loadDetails()))
            ->additional(['meta' => ['facts' => $facts]])
            ->response();
    }

    /**
     * One line per fact that happened (a decision is required) and per extra charge.
     *
     * @param  array<string, mixed>  $facts
     * @param  array<string, mixed>  $validated
     * @return list<array{kind: ChargeKind, label: string, suggested: ?string, amount: int|string, note: ?string}>
     */
    private function chargeLines(array $facts, array $validated): array
    {
        $decisions = $validated['charges'] ?? [];
        $suggested = $facts['suggested'];
        $lines = [];
        $missing = [];

        $happened = [
            'extra_km' => [ChargeKind::ExtraKm, $facts['km']['over'] > 0,
                "Extra km: {$facts['km']['over']} km over {$facts['km']['included']} included"],
            'late' => [ChargeKind::Late, $facts['late']['extra_days'] > 0,
                "Late return: {$facts['late']['text']} ({$facts['late']['extra_days']} extra ".($facts['late']['extra_days'] === 1 ? 'day' : 'days').')'],
            'fuel' => [ChargeKind::Fuel, $facts['fuel']['missing_quarters'] > 0,
                "Fuel: {$facts['fuel']['missing_quarters']} ".($facts['fuel']['missing_quarters'] === 1 ? 'quarter' : 'quarters').' missing'],
        ];

        foreach ($happened as $key => [$kind, $didHappen, $label]) {
            if (! $didHappen) {
                continue;
            }
            if (! isset($decisions[$key])) {
                $missing["charges.{$key}.amount"] = 'Decide: charge an amount, or 0 for no charge.';

                continue;
            }
            $line = [
                'kind' => $kind,
                'label' => $label,
                'suggested' => $suggested[$key] ?? null,
                'amount' => $decisions[$key]['amount'],
                'note' => $decisions[$key]['note'] ?? null,
            ];

            // Only when the agency turned price suggestions on: less than suggested needs a note.
            if (ChargeDecision::for($line['suggested'], $line['amount'])->needsReason() && $line['suggested'] !== null && blank($line['note'])) {
                $missing["charges.{$key}.note"] = 'Give a reason for charging less than suggested.';
            }

            $lines[] = $line;
        }

        if ($missing) {
            throw ValidationException::withMessages($missing);
        }

        foreach ($validated['new_damages'] ?? [] as $index => $mark) {
            $lines[] = [
                'kind' => ChargeKind::Damage,
                'label' => 'Damage: '.ucfirst($mark['type']).' - '.$mark['area'],
                'suggested' => null,
                'amount' => $mark['charge']['amount'],
                'note' => $mark['charge']['note'] ?? null,
                'damage_index' => $index, // linked to the damage once it is created
            ];
        }

        foreach ($validated['extra_charges'] ?? [] as $extra) {
            $lines[] = ['kind' => ChargeKind::Extra, 'label' => $extra['label'], 'suggested' => null, 'amount' => $extra['amount'], 'note' => null];
        }

        return $lines;
    }

    /**
     * Part of the deposit kept and/or a payment, never more than what is due.
     *
     * @param  list<array{amount: int|string}>  $lines
     * @param  array<string, mixed>  $validated
     * @return array{int, ?array{amount: int|string, method: string, reference?: ?string}}
     */
    private function settlement(Rental $rental, array $lines, array $validated): array
    {
        $dueCents = Money::cents($rental->balance()) + array_sum(array_map(fn ($line) => Money::cents($line['amount']), $lines));
        $keptCents = Money::cents($validated['deposit_kept'] ?? 0);

        if ($keptCents > Money::cents($rental->deposit_amount)) {
            throw ValidationException::withMessages(['deposit_kept' => "You can't keep more than the deposit ({$rental->deposit_amount} MAD)."]);
        }
        if ($keptCents > max(0, $dueCents)) {
            throw ValidationException::withMessages(['deposit_kept' => 'Keep only what is due ('.Money::round(max(0, $dueCents) / 100).' MAD).']);
        }

        $payment = $validated['payment'] ?? null;
        if ($payment) {
            $method = PaymentMethod::from($payment['method']);
            if ($method->needsReference() && blank($payment['reference'] ?? null)) {
                throw ValidationException::withMessages(['payment.reference' => 'A reference is required for '.$method->value.' (cheque no., card authorisation, transfer ref.).']);
            }
            if (Money::cents($payment['amount']) > $dueCents - $keptCents) {
                throw ValidationException::withMessages(['payment.amount' => "That's more than what is due (".Money::round(max(0, $dueCents - $keptCents) / 100).' MAD).']);
            }
        }

        return [intdiv($keptCents, 100), $payment];
    }

    private function addPayment(Rental $rental, int|string $amount, PaymentMethod $method, ?string $reference, CarbonImmutable $at): void
    {
        $line = new RentalPayment;
        $line->rental()->associate($rental);
        $line->amount = $amount;
        $line->method = $method;
        $line->reference = $reference;
        $line->paid_at = $at;
        $line->save();
    }

    private function returnTime(?string $local, string $timezone): CarbonImmutable
    {
        return $local
            ? CarbonImmutable::createFromFormat('Y-m-d H:i', $local, $timezone)
            : CarbonImmutable::now();
    }
}
