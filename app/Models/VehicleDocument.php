<?php

namespace App\Models;

use App\Enums\DocumentType;
use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\LogsActivity;
use Database\Factories\VehicleDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'type',
    'reference',
    'provider',
    'issue_date',
    'expiry_date',
    'cost',
    'notes',
])]
class VehicleDocument extends Model
{
    /** @use HasFactory<VehicleDocumentFactory> */
    use BelongsToAgency, HasFactory, LogsActivity;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'issue_date' => 'date',
            'expiry_date' => 'date',
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

    public function activityLabel(): string
    {
        $label = ucfirst($this->type->value).($this->reference ? ' '.$this->reference : '');

        return $this->vehicle?->fleet_code.' '.$label;
    }
}
