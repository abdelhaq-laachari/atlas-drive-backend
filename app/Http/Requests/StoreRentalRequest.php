<?php

namespace App\Http\Requests;

use App\Enums\ChargeDecision;
use App\Enums\PaymentMethod;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Rules\WholeDirhams;
use App\Support\RentalBooking;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Book a car: POST /api/rentals
 * Times are the agency's local time, written "2026-11-15 10:00".
 */
class StoreRentalRequest extends FormRequest
{
    /**
     * Result of the booking checks (days, price, warnings), for the controller.
     *
     * @var array{errors: array<string, string>, warnings: list<string>, days: int, daily_price: string, suggested_price: string, deposit: string}|null
     */
    public ?array $booking = null;

    public ?Vehicle $vehicle = null;

    public ?Customer $customer = null;

    public ?Customer $driver = null;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->bookingRules(),
            // Agreed price; empty = the suggested days x daily price. A lower price needs a reason.
            'price' => ['nullable', new WholeDirhams],
            'price_reason' => ['nullable', 'string', 'max:255'],
            // Deposit; empty = the car's deposit
            'deposit_amount' => ['nullable', new WholeDirhams],
            'deposit_method' => ['nullable', Rule::in(PaymentMethod::customerMethods())],
            'deposit_reference' => ['nullable', 'string', 'max:50'],
            // Optional payment received at booking
            'payment' => ['nullable', 'array'],
            'payment.amount' => ['required_with:payment', new WholeDirhams],
            'payment.method' => ['required_with:payment', Rule::in(PaymentMethod::customerMethods())],
            'payment.reference' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Car, people and period: shared with the quote.
     *
     * @return array<string, array<mixed>>
     */
    protected function bookingRules(): array
    {
        return [
            'vehicle_id' => ['required', 'integer'],
            'customer_id' => ['required', 'integer'],
            'driver_id' => ['nullable', 'integer'], // empty = the customer drives
            'pickup_at' => ['required', 'date_format:Y-m-d H:i'],
            'return_at' => ['required', 'date_format:Y-m-d H:i'],
        ];
    }

    /**
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (! $this->resolveBooking($validator)) {
                    return;
                }

                foreach ($this->booking['errors'] as $field => $message) {
                    $validator->errors()->add($field, $message);
                }

                $this->checkMoney($validator);
            },
        ];
    }

    /**
     * Find the car and the people (only in this agency) and run the booking checks.
     */
    protected function resolveBooking(Validator $validator): bool
    {
        if ($validator->errors()->hasAny(array_keys($this->bookingRules()))) {
            return false;
        }

        $this->vehicle = Vehicle::with('documents')->find($this->integer('vehicle_id'));
        $this->customer = Customer::find($this->integer('customer_id'));
        $this->driver = $this->filled('driver_id') ? Customer::find($this->integer('driver_id')) : $this->customer;

        foreach (['vehicle_id' => $this->vehicle, 'customer_id' => $this->customer, 'driver_id' => $this->driver] as $field => $model) {
            if (! $model) {
                $validator->errors()->add($field, 'Not found.');
            }
        }

        if ($validator->errors()->isNotEmpty()) {
            return false;
        }

        $this->booking = (new RentalBooking($this->user()->agency))->check(
            $this->vehicle, $this->customer, $this->driver, $this->pickupAt(), $this->returnAt(),
        );

        return true;
    }

    private function checkMoney(Validator $validator): void
    {
        if ($this->filled('price') && ! $validator->errors()->has('price')) {
            $decision = ChargeDecision::for($this->booking['suggested_price'], $this->input('price'));
            if ($decision->needsReason() && blank($this->input('price_reason'))) {
                $validator->errors()->add('price_reason', 'Give a reason for the lower price.');
            }
        }

        $depositAmount = $this->filled('deposit_amount') ? $this->input('deposit_amount') : $this->booking['deposit'];
        if ((float) $depositAmount > 0 && blank($this->input('deposit_method'))) {
            $validator->errors()->add('deposit_method', 'Choose how the deposit is left.');
        }

        foreach (['deposit' => 'deposit_', 'payment' => 'payment.'] as $prefix) {
            $method = PaymentMethod::tryFrom((string) $this->input($prefix.'method'));
            if ($method?->needsReference() && blank($this->input($prefix.'reference'))) {
                $validator->errors()->add($prefix.'reference', 'A reference is required for '.$method->value.' (cheque no., card authorisation, transfer ref.).');
            }
        }
    }

    public function pickupAt(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i', $this->input('pickup_at'), $this->user()->agency->timezone);
    }

    public function returnAt(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i', $this->input('return_at'), $this->user()->agency->timezone);
    }
}
