<?php

namespace App\Http\Requests;

use App\Rules\WholeDirhams;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Mark damage as repaired: POST /api/vehicle-damages/{damage}/repair
 */
class RepairDamageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('repair', $this->route('damage'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $timezone = $this->user()->agency->timezone;
        $today = Carbon::now($timezone)->toDateString();
        $foundOn = $this->route('damage')->found_at->setTimezone($timezone)->toDateString();

        return [
            'repaired_at' => ['required', 'date', 'after_or_equal:'.$foundOn, 'before_or_equal:'.$today],
            'repair_cost' => ['nullable', new WholeDirhams],
            'repair_note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'repaired_at.after_or_equal' => 'The repair date cannot be before the damage was found.',
            'repaired_at.before_or_equal' => 'The repair date cannot be in the future.',
        ];
    }
}
