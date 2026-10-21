<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * An amount typed by a user (price, deposit, charge): whole dirhams, no cents.
 *
 * Usage in a Form Request: 'daily_price' => ['required', new WholeDirhams],
 */
class WholeDirhams implements ValidationRule
{
    // Largest value a decimal(10,2) column can hold, in whole dirhams.
    public const MAX = 99_999_999;

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $amount = is_bool($value) ? false : filter_var($value, FILTER_VALIDATE_INT);

        if ($amount === false) {
            $fail('The :attribute must be a whole amount in dirhams (no cents).');

            return;
        }

        if ($amount < 0 || $amount > self::MAX) {
            $fail('The :attribute must be between 0 and '.number_format(self::MAX, 0, '.', ' ').' MAD.');
        }
    }
}
