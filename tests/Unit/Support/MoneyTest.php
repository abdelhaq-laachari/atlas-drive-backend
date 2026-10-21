<?php

namespace Tests\Unit\Support;

use App\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    /**
     * @return array<string, array{int|float|string, string}>
     */
    public static function amounts(): array
    {
        return [
            'whole dirhams' => [100, '100.00'],
            'string from the database' => ['150.00', '150.00'],
            'price without VAT (100 / 1.2)' => [100 / 1.2, '83.33'],
            'half rounds up' => [16.665, '16.67'],
            'classic float trap' => [1.005, '1.01'],
            'float noise (0.1 + 0.2)' => [0.1 + 0.2, '0.30'],
            'weekly price split per day (1000 / 7)' => [1000 / 7, '142.86'],
            'zero' => [0, '0.00'],
        ];
    }

    #[DataProvider('amounts')]
    public function test_amounts_are_rounded_to_two_decimals(int|float|string $amount, string $expected): void
    {
        $this->assertSame($expected, Money::round($amount));
    }
}
