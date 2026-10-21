<?php

namespace App\Support;

/**
 * Money is stored as decimal(10,2) in MAD and handled as a "123.45" string.
 * Every calculated amount (VAT, splits, discounts) goes through Money::round().
 */
class Money
{
    /**
     * Round to 2 decimals (half up) and return the database format, e.g. 83.333 → "83.33".
     */
    public static function round(int|float|string $amount): string
    {
        return number_format(round((float) $amount, 2), 2, '.', '');
    }
}
