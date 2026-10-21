<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Validator;

/**
 * Preview a booking without saving: POST /api/rentals/quote
 * Same checks as booking, but problems are returned in the answer (200), not as a 422.
 */
class QuoteRentalRequest extends StoreRentalRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->bookingRules();
    }

    /**
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->resolveBooking($validator),
        ];
    }
}
