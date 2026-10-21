<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Archive (hide) a customer, e.g. a duplicate or someone who no longer rents. Never deleted:
 * their rentals and invoices keep pointing to them. Owner only.
 */
class CustomerArchiveController extends Controller
{
    /**
     * POST /api/customers/{customer}/archive  { "reason": "Duplicate of C-004" }
     */
    public function store(Request $request, Customer $customer): CustomerResource
    {
        Gate::authorize('archive', $customer);

        abort_if($customer->isArchived(), 409, 'This customer is already archived.');

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $customer->forceFill([
            'archived_at' => now(),
            'archive_reason' => $validated['reason'],
        ])->save();

        return new CustomerResource($customer);
    }

    /**
     * DELETE /api/customers/{customer}/archive
     */
    public function destroy(Customer $customer): CustomerResource
    {
        Gate::authorize('archive', $customer);

        abort_unless($customer->isArchived(), 409, 'This customer is not archived.');

        $customer->forceFill([
            'archived_at' => null,
            'archive_reason' => null,
        ])->save();

        return new CustomerResource($customer);
    }
}
