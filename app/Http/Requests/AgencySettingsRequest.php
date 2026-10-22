<?php

namespace App\Http\Requests;

use App\Rules\WholeDirhams;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/settings/agency — the owner edits their agency. Currency and timezone are not here on purpose:
 * they are set once at creation (changing them would change the meaning of existing amounts and times).
 */
class AgencySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->user()->agency);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Identity
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:25', 'regex:/^\+?[\d\s().-]{8,}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:50'],

            // Legal numbers
            'ice' => ['nullable', 'digits:15'],
            'if_number' => ['nullable', 'string', 'max:20', 'regex:/^\d+$/'],
            'rc_number' => ['nullable', 'string', 'max:30'],
            'patente_number' => ['nullable', 'string', 'max:30', 'regex:/^\d+$/'],

            // Rules
            'late_grace_minutes' => ['required', 'integer', 'between:0,1440'],
            'fuel_price_per_quarter' => ['required', new WholeDirhams],
            'driver_min_age' => ['required', 'integer', 'between:18,99'],
            'licence_min_years' => ['required', 'integer', 'between:0,20'],
            'document_expiring_soon_days' => ['required', 'integer', 'between:1,365'],
            'suggest_return_prices' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ice.digits' => 'The ICE has exactly 15 digits.',
            'if_number.regex' => 'The IF (identifiant fiscal) contains digits only.',
            'patente_number.regex' => 'The patente number contains digits only.',
            'phone.regex' => 'Enter a valid phone number.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'ice' => 'ICE',
            'if_number' => 'IF',
            'rc_number' => 'RC',
            'late_grace_minutes' => 'grace minutes',
        ];
    }
}
