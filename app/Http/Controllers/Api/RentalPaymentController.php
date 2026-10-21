<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentMethod;
use App\Enums\RentalStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\RentalResource;
use App\Models\Rental;
use App\Models\RentalPayment;
use App\Rules\WholeDirhams;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * POST /api/rentals/{rental}/payments  { "amount": 300, "method": "card", "reference": "AUTH-1", "refund": false }
 * Money received, or given back ("refund": true, stored as a negative amount).
 */
class RentalPaymentController extends Controller
{
    public function store(Request $request, Rental $rental): JsonResponse
    {
        Gate::authorize('update', $rental);

        $validated = $request->validate([
            'amount' => ['required', new WholeDirhams, 'not_in:0'],
            'method' => ['required', Rule::in(PaymentMethod::customerMethods())],
            'reference' => ['nullable', 'string', 'max:50'],
            'refund' => ['nullable', 'boolean'],
        ], [
            'amount.not_in' => 'The amount must be more than 0.',
        ]);

        $method = PaymentMethod::from($validated['method']);
        if ($method->needsReference() && blank($validated['reference'] ?? null)) {
            throw ValidationException::withMessages([
                'reference' => 'A reference is required for '.$method->value.' (cheque no., card authorisation, transfer ref.).',
            ]);
        }

        $rental->load(['charges', 'payments']);
        $refund = $request->boolean('refund');
        $amount = Money::cents($validated['amount']);

        if ($refund && $amount > Money::cents($rental->paid())) {
            throw ValidationException::withMessages(['amount' => "You can't refund more than what was paid ({$rental->paid()} MAD)."]);
        }
        if (! $refund && $rental->status === RentalStatus::Cancelled) {
            throw ValidationException::withMessages(['amount' => 'This rental is cancelled: only refunds are possible.']);
        }
        if (! $refund && $amount > Money::cents($rental->balance())) {
            throw ValidationException::withMessages(['amount' => "That's more than what is due ({$rental->balance()} MAD)."]);
        }

        $payment = new RentalPayment;
        $payment->rental()->associate($rental);
        $payment->amount = Money::round(($refund ? -1 : 1) * $amount / 100);
        $payment->method = $method;
        $payment->reference = $validated['reference'] ?? null;
        $payment->paid_at = now();
        $payment->save();

        return (new RentalResource($rental->loadDetails()))->response()->setStatusCode(201);
    }
}
