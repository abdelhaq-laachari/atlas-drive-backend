<?php

namespace App\Http\Controllers\Api;

use App\Enums\ChargeKind;
use App\Enums\PaymentMethod;
use App\Enums\VehicleStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\QuoteRentalRequest;
use App\Http\Requests\StoreRentalRequest;
use App\Http\Resources\RentalResource;
use App\Models\Rental;
use App\Models\RentalPayment;
use App\Models\Vehicle;
use App\Support\RentalBooking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RentalController extends Controller
{
    /**
     * GET /api/rentals — newest pickup first (filters and dashboard lists come in step 5).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Rental::class);

        $rentals = Rental::query()
            ->with(['vehicle', 'customer', 'driver', 'charges', 'payments'])
            ->latest('pickup_at')
            ->paginate($request->integer('per_page', 25) ?: 25);

        return RentalResource::collection($rentals);
    }

    /**
     * POST /api/rentals/quote — days, price, warnings and problems, without saving.
     */
    public function quote(QuoteRentalRequest $request): JsonResponse
    {
        Gate::authorize('create', Rental::class);

        $booking = $request->booking;

        return response()->json(['data' => [
            'can_book' => $booking['errors'] === [],
            'errors' => $booking['errors'],
            'warnings' => $booking['warnings'],
            'days' => $booking['days'],
            'daily_price' => $booking['daily_price'],
            'suggested_price' => $booking['suggested_price'],
            'deposit' => $booking['deposit'],
        ]]);
    }

    /**
     * POST /api/rentals — book the car (status Reserved).
     */
    public function store(StoreRentalRequest $request): JsonResponse
    {
        Gate::authorize('create', Rental::class);

        $booking = $request->booking;

        $rental = DB::transaction(function () use ($request, $booking) {
            // Lock the car row: two people booking the same car at the same moment can't both succeed.
            $vehicle = Vehicle::whereKey($request->vehicle->id)->lockForUpdate()->first();
            $stillFree = (new RentalBooking($request->user()->agency))
                ->conflicts($vehicle, $request->pickupAt(), $request->returnAt())
                ->isEmpty();
            abort_unless($stillFree, 409, "{$vehicle->fleet_code} was just booked by someone else for these dates.");

            $depositAmount = $request->filled('deposit_amount') ? $request->input('deposit_amount') : $booking['deposit'];

            $rental = new Rental;
            $rental->vehicle()->associate($vehicle);
            $rental->customer()->associate($request->customer);
            $rental->driver()->associate($request->driver);
            // Local agency time; the UtcDateTime cast stores it in UTC.
            $rental->pickup_at = $request->pickupAt();
            $rental->return_at = $request->returnAt();
            $rental->days = $booking['days'];
            $rental->daily_price = $booking['daily_price'];
            $rental->deposit_amount = $depositAmount;
            $rental->deposit_method = (float) $depositAmount > 0 ? $request->input('deposit_method') : null;
            $rental->deposit_reference = (float) $depositAmount > 0 ? $request->input('deposit_reference') : null;
            $rental->notes = $request->input('notes');
            $rental->save();

            $rental->addCharge(
                ChargeKind::Rental,
                "Rental {$booking['days']} ".($booking['days'] === 1 ? 'day' : 'days')." x {$booking['daily_price']} MAD",
                $booking['suggested_price'],
                $request->filled('price') ? $request->input('price') : $booking['suggested_price'],
                $request->input('price_reason'),
            );

            if ($request->filled('payment.amount') && (float) $request->input('payment.amount') > 0) {
                $payment = new RentalPayment;
                $payment->rental()->associate($rental);
                $payment->amount = $request->input('payment.amount');
                $payment->method = PaymentMethod::from($request->input('payment.method'));
                $payment->reference = $request->input('payment.reference');
                $payment->paid_at = now();
                $payment->save();
            }

            // An available car is now Reserved (a car out on another rental keeps its status).
            if ($vehicle->status === VehicleStatus::Available) {
                $vehicle->status = VehicleStatus::Reserved;
                $vehicle->save();
            }

            return $rental;
        });

        return (new RentalResource($rental->loadDetails()))
            ->additional(['meta' => ['warnings' => $booking['warnings']]])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /api/rentals/{rental}
     */
    public function show(Rental $rental): RentalResource
    {
        Gate::authorize('view', $rental);

        return new RentalResource($rental->loadDetails());
    }
}
