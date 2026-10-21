<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Blocklist (owner only): a blocked customer can't be chosen for a new rental.
 */
class CustomerBlockController extends Controller
{
    /**
     * POST /api/customers/{customer}/block  { "reason": "Unpaid damage" }
     */
    public function store(Request $request, Customer $customer): CustomerResource
    {
        Gate::authorize('block', $customer);

        abort_if($customer->isBlocked(), 409, 'This customer is already blocked.');

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $customer->forceFill([
            'blocked_at' => now(),
            'block_reason' => $validated['reason'],
        ])->save();

        return new CustomerResource($customer);
    }

    /**
     * DELETE /api/customers/{customer}/block
     */
    public function destroy(Customer $customer): CustomerResource
    {
        Gate::authorize('block', $customer);

        abort_unless($customer->isBlocked(), 409, 'This customer is not blocked.');

        $customer->forceFill([
            'blocked_at' => null,
            'block_reason' => null,
        ])->save();

        return new CustomerResource($customer);
    }
}
