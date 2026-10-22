<?php

namespace App\Models;

use App\Enums\ChargeDecision;
use App\Enums\ChargeKind;
use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One amount of a rental: suggested by the system, decided by the manager (charge / reduce / waive).
 */
class RentalCharge extends Model
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
            'kind' => ChargeKind::class,
            'suggested' => 'decimal:2',
            'amount' => 'decimal:2',
            'decision' => ChargeDecision::class,
        ];
    }

    /**
     * @return BelongsTo<Rental, $this>
     */
    public function rental(): BelongsTo
    {
        return $this->belongsTo(Rental::class);
    }

    /**
     * For damage lines: the damage on the car this amount was charged for.
     *
     * @return BelongsTo<VehicleDamage, $this>
     */
    public function vehicleDamage(): BelongsTo
    {
        return $this->belongsTo(VehicleDamage::class);
    }

    public function activityLabel(): string
    {
        return $this->rental?->rental_code.' '.$this->label;
    }
}
