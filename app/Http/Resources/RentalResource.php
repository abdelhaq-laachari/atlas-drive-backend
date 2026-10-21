<?php

namespace App\Http\Resources;

use App\Models\Rental;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Rental
 *
 * Dates are sent in the agency's timezone with their offset, e.g. "2026-11-15T10:00:00+01:00".
 */
class RentalResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $agency = $request->user()->agency;
        $local = fn ($date) => $date?->copy()->setTimezone($agency->timezone)->toIso8601String();

        return [
            'id' => $this->id,
            'rental_code' => $this->rental_code,
            'status' => $this->status,
            'display_status' => $this->isOverdue(null, $agency->late_grace_minutes) ? 'overdue' : $this->status->value,
            'minutes_late' => $this->minutesLate(null, $agency->late_grace_minutes),
            'vehicle' => $this->whenLoaded('vehicle', fn () => [
                'id' => $this->vehicle->id,
                'fleet_code' => $this->vehicle->fleet_code,
                'make' => $this->vehicle->make,
                'model' => $this->vehicle->model,
                'plate' => $this->vehicle->plate,
            ]),
            'customer' => $this->whenLoaded('customer', fn () => $this->person($this->customer)),
            'driver' => $this->whenLoaded('driver', fn () => $this->person($this->driver)),
            'pickup_at' => $local($this->pickup_at),
            'return_at' => $local($this->return_at),
            'days' => $this->days,
            'daily_price' => $this->daily_price,
            'total' => $this->whenLoaded('charges', fn () => $this->total()),
            'paid' => $this->whenLoaded('payments', fn () => $this->paid()),
            'balance' => $this->when(
                $this->relationLoaded('charges') && $this->relationLoaded('payments'),
                fn () => $this->balance(),
            ),
            'deposit' => [
                'amount' => $this->deposit_amount,
                'method' => $this->deposit_method,
                'reference' => $this->deposit_reference,
                'receipt_code' => $this->deposit_code,
                'kept' => $this->deposit_kept,
                'returned_at' => $local($this->deposit_returned_at),
            ],
            'charges' => $this->whenLoaded('charges', fn () => $this->charges->map(fn ($charge) => [
                'id' => $charge->id,
                'kind' => $charge->kind,
                'label' => $charge->label,
                'suggested' => $charge->suggested,
                'amount' => $charge->amount,
                'decision' => $charge->decision,
                'reason' => $charge->reason,
            ])),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($payment) => [
                'id' => $payment->id,
                'amount' => $payment->amount,
                'method' => $payment->method,
                'reference' => $payment->reference,
                'paid_at' => $local($payment->paid_at),
            ])),
            'handover' => $this->handed_over_at ? [
                'at' => $local($this->handed_over_at),
                'mileage' => $this->handover_mileage,
                'fuel' => $this->handover_fuel,
                'remarks' => $this->handover_remarks,
            ] : null,
            'return' => $this->returned_at ? [
                'at' => $local($this->returned_at),
                'mileage' => $this->return_mileage,
                'fuel' => $this->return_fuel,
                'remarks' => $this->return_remarks,
            ] : null,
            'damages' => $this->whenLoaded('damages', fn () => $this->damages->map(fn ($damage) => [
                'id' => $damage->id,
                'vehicle_damage_id' => $damage->vehicle_damage_id,
                'stage' => $damage->stage,
                'known' => $damage->known,
                'x' => $damage->x,
                'y' => $damage->y,
                'area' => $damage->area,
                'type' => $damage->type,
                'size' => $damage->size,
                'note' => $damage->note,
            ])),
            'notes' => $this->notes,
            'cancelled_at' => $local($this->cancelled_at),
            'cancel_reason' => $this->cancel_reason,
            'created_at' => $local($this->created_at),
            'updated_at' => $local($this->updated_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function person($customer): array
    {
        return [
            'id' => $customer->id,
            'customer_code' => $customer->customer_code,
            'full_name' => $customer->full_name,
            'phone' => $customer->phone,
        ];
    }
}
