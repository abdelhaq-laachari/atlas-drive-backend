<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class CustomerController extends Controller
{
    /**
     * GET /api/customers?search=&blocked=1&archived=1&per_page= — sorted by name.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Customer::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:50'],
            'blocked' => ['nullable', 'boolean'],
            'archived' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $customers = Customer::query()
            ->when(
                $request->boolean('archived'),
                fn ($query) => $query->archived(),
                fn ($query) => $query->active(),
            )
            ->when($request->has('blocked'), fn ($query) => $request->boolean('blocked')
                ? $query->whereNotNull('blocked_at')
                : $query->whereNull('blocked_at'))
            ->when($filters['search'] ?? null, fn ($query, $search) => $this->search($query, $search))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return CustomerResource::collection($customers);
    }

    /**
     * POST /api/customers
     */
    public function store(CustomerRequest $request): CustomerResource
    {
        $customer = Customer::create($request->validated());

        return new CustomerResource($customer->refresh());
    }

    /**
     * GET /api/customers/{customer}
     */
    public function show(Customer $customer): CustomerResource
    {
        Gate::authorize('view', $customer);

        return new CustomerResource($customer);
    }

    /**
     * PUT /api/customers/{customer}
     */
    public function update(CustomerRequest $request, Customer $customer): CustomerResource
    {
        abort_if($customer->isArchived(), 409, 'This customer is archived. Restore them before editing.');

        $customer->update($request->validated());

        return new CustomerResource($customer);
    }

    /**
     * Search by name (either order), phone, ID number or customer code (C-012 / 12).
     */
    private function search($query, string $search): void
    {
        $query->where(function ($query) use ($search) {
            $term = '%'.$search.'%';

            $query->where('first_name', 'like', $term)
                ->orWhere('last_name', 'like', $term)
                ->orWhereRaw("CONCAT(first_name, ' ', last_name) like ?", [$term])
                ->orWhereRaw("CONCAT(last_name, ' ', first_name) like ?", [$term]);

            // Local "0611..." and international "+212 611..." / "00212 611..." find the same phone.
            if (strlen($digits = ltrim(preg_replace('/\D/', '', $search), '0')) >= 4) {
                $query->orWhere('phone', 'like', '%'.$digits.'%');
            }

            if (strlen($idKey = Customer::idKey($search)) >= 4) {
                $query->orWhere('id_key', 'like', '%'.$idKey.'%');
            }

            if ($number = Customer::numberFromCode($search, 'C-')) {
                $query->orWhere('customer_number', $number);
            }
        });
    }
}
