<?php

namespace App\Models;

use App\Enums\DamageSize;
use App\Enums\DamageStage;
use App\Enums\DamageType;
use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Frozen copy of a damage mark on a rental (for the contract). Never changes after it is written,
 * even when the car's live record changes (e.g. the damage is repaired later).
 */
class RentalDamage extends Model
{
    use BelongsToAgency;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => DamageStage::class,
            'known' => 'boolean',
            'x' => 'float',
            'y' => 'float',
            'type' => DamageType::class,
            'size' => DamageSize::class,
        ];
    }

    /**
     * Copy a mark onto a rental.
     */
    public static function freeze(Rental $rental, VehicleDamage $damage, DamageStage $stage, bool $known): self
    {
        $copy = new self;
        $copy->agency_id = $rental->agency_id;
        $copy->rental()->associate($rental);
        $copy->vehicleDamage()->associate($damage);
        $copy->stage = $stage;
        $copy->known = $known;
        $copy->x = $damage->x;
        $copy->y = $damage->y;
        $copy->area = $damage->area;
        $copy->type = $damage->type;
        $copy->size = $damage->size;
        $copy->note = $damage->note;
        $copy->save();

        return $copy;
    }

    /**
     * @return BelongsTo<Rental, $this>
     */
    public function rental(): BelongsTo
    {
        return $this->belongsTo(Rental::class);
    }

    /**
     * @return BelongsTo<VehicleDamage, $this>
     */
    public function vehicleDamage(): BelongsTo
    {
        return $this->belongsTo(VehicleDamage::class);
    }
}
