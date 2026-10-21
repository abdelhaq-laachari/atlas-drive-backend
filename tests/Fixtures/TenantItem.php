<?php

namespace Tests\Fixtures;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Test-only model standing in for future tenant models (Vehicle, Customer...).
 */
#[Fillable(['name'])]
class TenantItem extends Model
{
    use BelongsToAgency;
}
