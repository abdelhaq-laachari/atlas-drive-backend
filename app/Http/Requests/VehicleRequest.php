<?php

namespace App\Http\Requests;

use App\Enums\FuelType;
use App\Enums\Transmission;
use App\Enums\VehicleCategory;
use App\Models\Vehicle;
use App\Rules\WholeDirhams;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Add a car (POST /api/vehicles) or edit it (PUT /api/vehicles/{vehicle}).
 */
class VehicleRequest extends FormRequest
{
    /**
     * Checked before validation: only the owner adds or edits cars (VehiclePolicy).
     */
    public function authorize(): bool
    {
        $vehicle = $this->route('vehicle');

        return $vehicle
            ? $this->user()->can('update', $vehicle)
            : $this->user()->can('create', Vehicle::class);
    }

    /**
     * Clean the input before the rules run.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'plate' => $this->has('plate') ? Vehicle::normalizePlate($this->input('plate')) : null,
            'vin' => $this->filled('vin') ? strtoupper(trim($this->input('vin'))) : null,
        ], fn ($value) => $value !== null));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $agencyId = $this->user()->agency_id;
        $vehicle = $this->route('vehicle');

        return [
            'make' => ['required', 'string', 'max:50'],
            'model' => ['required', 'string', 'max:50'],
            'year' => ['required', 'integer', 'between:1990,'.(now()->year + 1)],
            'plate' => ['required', 'string', 'max:20'],
            'vin' => [
                'nullable', 'string',
                // Standard VIN: 17 letters/digits, never I, O or Q
                'regex:/^[A-HJ-NPR-Z0-9]{17}$/',
                Rule::unique('vehicles')->where('agency_id', $agencyId)->ignore($vehicle),
            ],
            'category' => ['required', Rule::enum(VehicleCategory::class)],
            'fuel' => ['required', Rule::enum(FuelType::class)],
            'transmission' => ['required', Rule::enum(Transmission::class)],
            'seats' => ['required', 'integer', 'between:1,60'],
            'doors' => ['required', 'integer', 'between:0,6'],
            'color' => ['nullable', 'string', 'max:30'],
            'mileage' => ['required', 'integer', 'between:0,2000000'],
            'first_registration' => ['nullable', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'daily_price' => ['required', new WholeDirhams],
            'weekly_price' => ['required', new WholeDirhams],
            'monthly_price' => ['required', new WholeDirhams],
            'deposit' => ['required', new WholeDirhams],
            'included_km_per_day' => ['nullable', 'integer', 'between:0,5000'],
            'extra_km_fee' => ['required', 'numeric', 'decimal:0,2', 'between:0,1000'],
        ];
    }

    /**
     * Same plate check as the database unique index, but with a friendly message.
     * Vehicle queries are already limited to the user's agency (BelongsToAgency).
     *
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('plate')) {
                    return;
                }

                $taken = Vehicle::query()
                    ->where('plate_key', Vehicle::plateKey($this->input('plate')))
                    ->when($this->route('vehicle'), fn ($query, $vehicle) => $query->whereKeyNot($vehicle->getKey()))
                    ->exists();

                if ($taken) {
                    $validator->errors()->add('plate', 'Another vehicle already uses this plate number.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'vin.regex' => 'A chassis number (VIN) has exactly 17 letters and digits, without I, O or Q.',
            'vin.unique' => 'Another vehicle already uses this chassis number.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'vin' => 'chassis number (VIN)',
            'included_km_per_day' => 'included km per day',
        ];
    }
}
