<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\VehicleDocumentRequest;
use App\Http\Resources\DocumentRowResource;
use App\Http\Resources\VehicleDocumentResource;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Support\DocumentOverview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class VehicleDocumentController extends Controller
{
    /**
     * GET /api/vehicles/{vehicle}/documents — one row per type: status, current document, history.
     */
    public function index(Request $request, Vehicle $vehicle): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', VehicleDocument::class);

        $vehicle->load('documents');

        return DocumentRowResource::collection(
            (new DocumentOverview($request->user()->agency))->rows($vehicle)
        );
    }

    /**
     * POST /api/vehicles/{vehicle}/documents — add a document, or renew one (the newest becomes current).
     */
    public function store(VehicleDocumentRequest $request, Vehicle $vehicle): VehicleDocumentResource
    {
        abort_if($vehicle->isArchived(), 409, 'This car is archived.');

        $document = new VehicleDocument($request->validated());
        $document->vehicle()->associate($vehicle);
        $document->save();

        return new VehicleDocumentResource($document);
    }

    /**
     * PUT /api/vehicle-documents/{document} — correct a document.
     */
    public function update(VehicleDocumentRequest $request, VehicleDocument $document): VehicleDocumentResource
    {
        abort_if($document->vehicle->isArchived(), 409, 'This car is archived.');

        $document->update($request->validated());

        return new VehicleDocumentResource($document);
    }

    /**
     * DELETE /api/vehicle-documents/{document} — owner only (e.g. entered by mistake).
     */
    public function destroy(VehicleDocument $document): Response
    {
        Gate::authorize('delete', $document);

        $document->delete();

        return response()->noContent();
    }
}
