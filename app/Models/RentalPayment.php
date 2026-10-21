<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Enums\PaymentMethod;
use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money received for a rental (method "deposit" = kept from the deposit at return).
 */
class RentalPayment extends Model
{
    use BelongsToAgency, LogsActivity;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'method' => PaymentMethod::class,
            'paid_at' => UtcDateTime::class,
        ];
    }

    /**
     * @return BelongsTo<Rental, $this>
     */
    public function rental(): BelongsTo
    {
        return $this->belongsTo(Rental::class);
    }

    public function activityLabel(): string
    {
        return $this->rental?->rental_code.' payment '.$this->method->value;
    }
}
