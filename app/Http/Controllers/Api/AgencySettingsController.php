<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AgencySettingsRequest;
use App\Http\Resources\AgencyResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The logged-in user's own agency (no id in the URL: nobody can even ask for another agency).
 */
class AgencySettingsController extends Controller
{
    /**
     * GET /api/settings/agency
     */
    public function show(Request $request): AgencyResource
    {
        $agency = $request->user()->agency;
        Gate::authorize('view', $agency);

        return new AgencyResource($agency);
    }

    /**
     * PUT /api/settings/agency — owner only.
     */
    public function update(AgencySettingsRequest $request): AgencyResource
    {
        $agency = $request->user()->agency;
        $agency->update($request->validated());

        return new AgencyResource($agency);
    }
}
