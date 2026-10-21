<?php

namespace App\Casts;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Like the "datetime" cast, but a time from another timezone is converted to UTC before saving.
 * (Laravel's own cast saves the clock reading as-is: 10:00+01:00 would be stored as 10:00, one hour wrong.)
 * Use it for every date-time that comes from people (pickup, return, found at, paid at...).
 * Returns an immutable date: $rental->return_at->setTimezone(...) gives a new object and never
 * changes the value cached on the model.
 *
 * @implements CastsAttributes<CarbonImmutable, mixed>
 */
class UtcDateTime implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value, 'UTC');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value)->utc()->format('Y-m-d H:i:s');
    }
}
