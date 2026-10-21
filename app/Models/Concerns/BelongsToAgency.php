<?php

namespace App\Models\Concerns;

use App\Models\Agency;
use App\Models\Scopes\AgencyScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Add to every tenant model (vehicles, customers, rentals...).
 *
 * - Reading: only rows of the logged-in user's agency are returned.
 * - Saving: agency_id is set to the logged-in user's agency.
 *
 * The table needs: $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
 */
trait BelongsToAgency
{
    public static function bootBelongsToAgency(): void
    {
        static::addGlobalScope(new AgencyScope);

        static::creating(function (self $model) {
            if ($user = Auth::user()) {
                $model->agency_id = $user->agency_id;
            }
        });
    }

    /**
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }
}
