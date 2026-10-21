<?php

namespace Tests\Unit\Rules;

use App\Rules\WholeDirhams;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WholeDirhamsTest extends TestCase
{
    private function passes(mixed $value): bool
    {
        return Validator::make(['price' => $value], ['price' => [new WholeDirhams]])->passes();
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function accepted(): array
    {
        return [
            'number' => [307],
            'text from a form' => ['190'],
            'zero' => [0],
            'largest amount' => [WholeDirhams::MAX],
        ];
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function refused(): array
    {
        return [
            'cents as number' => [150.5],
            'cents as text' => ['150.50'],
            'negative' => [-5],
            'too big for the column' => [WholeDirhams::MAX + 1],
            'not a number' => ['abc'],
            'true/false' => [true],
        ];
    }

    #[DataProvider('accepted')]
    public function test_whole_dirhams_are_accepted(mixed $value): void
    {
        $this->assertTrue($this->passes($value));
    }

    #[DataProvider('refused')]
    public function test_other_values_are_refused(mixed $value): void
    {
        $this->assertFalse($this->passes($value));
    }

    public function test_the_error_message_explains_the_rule(): void
    {
        $errors = Validator::make(['daily_price' => '150.50'], ['daily_price' => [new WholeDirhams]])->errors();

        $this->assertSame('The daily price must be a whole amount in dirhams (no cents).', $errors->first('daily_price'));
    }
}
