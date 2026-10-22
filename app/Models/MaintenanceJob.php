<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceType;
use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\LogsActivity;
use Database\Factories\MaintenanceJobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One visit to the garage. Status and dates are set by the start / finish / cancel actions, not by forms.
 */
#[Fillable([
    'type',
    'scheduled_for',
    'garage',
    'mileage',
    'cost',
    'description',
])]
class MaintenanceJob extends Model
{
    /** @use HasFactory<MaintenanceJobFactory> */
    use BelongsToAgency, HasFactory, LogsActivity;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'scheduled',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => MaintenanceType::class,
            'status' => MaintenanceStatus::class,
            'scheduled_for' => 'date',
            'started_at' => UtcDateTime::class,
            'completed_at' => UtcDateTime::class,
            'mileage' => 'integer',
            'cost' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [MaintenanceStatus::Scheduled, MaintenanceStatus::InProgress], true);
    }

    public function activityLabel(): string
    {
        return $this->vehicle?->fleet_code.' '.$this->type->label();
    }

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     */
    public function activityAction(array $changes): string
    {
        return match ($changes['status']['new'] ?? null) {
            'in_progress' => 'started',
            'done' => 'completed',
            'cancelled' => 'cancelled',
            default => 'updated',
        };
    }
}
