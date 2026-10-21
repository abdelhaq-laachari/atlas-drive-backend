<?php

namespace App\Http\Controllers\Api;

use App\Enums\DocumentType;
use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentRowResource;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Support\DocumentOverview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * GET /api/vehicle-documents?status=expired,missing,expiring&type=insurance
 * "Documents & Deadlines": current documents of the whole fleet (archived cars excluded),
 * most urgent first (expired, missing, expiring, valid; soonest expiry first).
 */
class FleetDocumentController extends Controller
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', VehicleDocument::class);

        $request->validate([
            // Comma-separated list, e.g. expired,missing
            'status' => ['nullable', 'string', 'regex:/^(valid|expiring|expired|missing)(,(valid|expiring|expired|missing))*$/'],
            'type' => ['nullable', Rule::enum(DocumentType::class)],
        ]);

        $statuses = $request->filled('status') ? explode(',', $request->string('status')) : null;

        $overview = new DocumentOverview($request->user()->agency);

        $rows = Vehicle::query()
            ->inFleet()
            ->with('documents')
            ->orderBy('fleet_number')
            ->get()
            ->flatMap(fn (Vehicle $vehicle) => array_map(
                fn (array $row) => ['vehicle' => $vehicle] + $row,
                $overview->rows($vehicle),
            ))
            ->when($statuses, fn ($rows) => $rows->filter(fn ($row) => in_array($row['status']->value, $statuses, true)))
            ->when($request->input('type'), fn ($rows, $type) => $rows->filter(fn ($row) => $row['type']->value === $type))
            ->sortBy([
                fn ($a, $b) => $a['status']->urgency() <=> $b['status']->urgency(),
                fn ($a, $b) => ($a['days_left'] ?? PHP_INT_MAX) <=> ($b['days_left'] ?? PHP_INT_MAX),
            ])
            ->map(function (array $row) {
                unset($row['history']);

                return $row;
            })
            ->values();

        return DocumentRowResource::collection($rows);
    }
}
