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

    /**
     * Whole centimes, to compare or add amounts exactly: "270.50" → 27050.
     */
    public static function cents(int|float|string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    /**
     * Add amounts exactly and return the database format: ["270.00", 135.5] → "405.50".
     *
     * @param  iterable<int|float|string>  $amounts
     */
    public static function sum(iterable $amounts): string
    {
        $cents = 0;

        foreach ($amounts as $amount) {
            $cents += self::cents($amount);
        }

        return number_format($cents / 100, 2, '.', '');
    }
}
