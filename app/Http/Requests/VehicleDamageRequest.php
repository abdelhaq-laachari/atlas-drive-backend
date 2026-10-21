<?php

namespace App\Http\Requests;

use App\Enums\DamageSize;
use App\Enums\DamageType;
use App\Models\VehicleDamage;
use App\Support\CarParts;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Record damage found on a car (POST /api/vehicles/{vehicle}/damages)
 * or correct it (PUT /api/vehicle-damages/{damage}).
 */
class VehicleDamageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $damage = $this->route('damage');

        return $damage
            ? $this->user()->can('update', $damage)
            : $this->user()->can('create', VehicleDamage::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Position on the drawing, 0 to 1
            'x' => ['required', 'numeric', 'between:0,1'],
            'y' => ['required', 'numeric', 'between:0,1'],
            'area' => ['required', Rule::in(CarParts::ALL)],
            'type' => ['required', Rule::enum(DamageType::class)],
            'size' => ['required', Rule::enum(DamageSize::class)],
            'note' => ['nullable', 'string', 'max:500'],
            'found_at' => ['nullable', 'date', 'before_or_equal:now'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'area.in' => 'Choose a car part from the list.',
            'found_at.before_or_equal' => 'The damage cannot be found in the future.',
        ];
    }
}
