<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Limits every query to the logged-in user's agency.
 *
 * With nobody logged in (seeders, console commands, queued jobs) no filter is
 * applied, so that code must filter by agency itself.
 */
class AgencyScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        if ($user = Auth::user()) {
            $builder->where($model->qualifyColumn('agency_id'), $user->agency_id);
        }
    }
}
