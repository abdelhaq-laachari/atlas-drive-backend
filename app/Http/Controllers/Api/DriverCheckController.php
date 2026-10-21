<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Support\DriverCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * GET /api/customers/{customer}/driver-check?on=2026-11-15
 * Can this customer drive on that day (default: today in the agency's timezone)?
 */
class DriverCheckController extends Controller
{
    public function __invoke(Request $request, Customer $customer): JsonResponse
    {
        Gate::authorize('view', $customer);

        $validated = $request->validate([
            'on' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $agency = $request->user()->agency;
        $on = isset($validated['on'])
            ? Carbon::parse($validated['on'], $agency->timezone)
            : Carbon::now($agency->timezone);

        return response()->json([
            'data' => (new DriverCheck($agency))->check($customer, $on),
        ]);
    }
}
