<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Enums\DamageSize;
use App\Enums\DamageStage;
use App\Enums\DamageStatus;
use App\Enums\DamageType;
use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\LogsActivity;
use Database\Factories\VehicleDamageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'x',
    'y',
    'area',
    'type',
    'size',
    'note',
    'found_at',
])]
class VehicleDamage extends Model
{
    /** @use HasFactory<VehicleDamageFactory> */
    use BelongsToAgency, HasFactory, LogsActivity;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'open',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'x' => 'float',
            'y' => 'float',
            'type' => DamageType::class,
            'size' => DamageSize::class,
            'found_at' => UtcDateTime::class,
            'found_stage' => DamageStage::class,
            'status' => DamageStatus::class,
            'repaired_at' => 'date',
            'repair_cost' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * The rental during which it was found (null = outside a rental).
     *
     * @return BelongsTo<Rental, $this>
     */
    public function rental(): BelongsTo
    {
        return $this->belongsTo(Rental::class);
    }

    public function isOpen(): bool
    {
        return $this->status === DamageStatus::Open;
    }

    public function activityLabel(): string
    {
        return $this->vehicle?->fleet_code.' '.ucfirst($this->type->value).' - '.$this->area;
    }

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     */
    public function activityAction(array $changes): string
    {
        if (array_key_exists('status', $changes)) {
            return $changes['status']['new'] === DamageStatus::Repaired->value ? 'repaired' : 'reopened';
        }

        return 'updated';
    }
}
