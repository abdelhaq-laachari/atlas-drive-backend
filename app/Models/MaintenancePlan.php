<?php

namespace App\Models;

use App\Enums\MaintenanceType;
use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\LogsActivity;
use Database\Factories\MaintenancePlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'type',
    'every_km',
    'every_months',
    'start_date',
    'start_mileage',
    'notes',
])]
class MaintenancePlan extends Model
{
    /** @use HasFactory<MaintenancePlanFactory> */
    use BelongsToAgency, HasFactory, LogsActivity;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => MaintenanceType::class,
            'every_km' => 'integer',
            'every_months' => 'integer',
            'start_date' => 'date',
            'start_mileage' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function activityLabel(): string
    {
        return $this->vehicle?->fleet_code.' plan: '.$this->type->label();
    }
}
