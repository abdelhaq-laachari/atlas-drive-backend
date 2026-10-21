<?php

namespace App\Support;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Agency;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Works out the status of each car document (Valid / Expiring soon / Expired / Missing)
 * using the agency's "expiring soon" days and the agency's own "today" (its timezone).
 */
class DocumentOverview
{
    private CarbonImmutable $today;

    private int $soonDays;

    public function __construct(Agency $agency)
    {
        $this->today = CarbonImmutable::now($agency->timezone)->startOfDay();
        $this->soonDays = $agency->document_expiring_soon_days;
    }

    /**
     * Days from today to the expiry date (negative = expired). Null when there is no expiry.
     */
    public function daysLeft(?VehicleDocument $document): ?int
    {
        if (! $document?->expiry_date) {
            return null;
        }

        $expiry = CarbonImmutable::parse($document->expiry_date->toDateString(), $this->today->timezone);

        return (int) $this->today->diffInDays($expiry, false);
    }

    /**
     * A document is valid up to and including its expiry date.
     */
    public function status(?VehicleDocument $document): DocumentStatus
    {
        if (! $document) {
            return DocumentStatus::Missing;
        }

        $days = $this->daysLeft($document);

        return match (true) {
            $days === null => DocumentStatus::Valid,
            $days < 0 => DocumentStatus::Expired,
            $days <= $this->soonDays => DocumentStatus::Expiring,
            default => DocumentStatus::Valid,
        };
    }

    /**
     * One row per document type for a car: its current document, status and history.
     * The car's `documents` relation should be loaded.
     *
     * @return list<array{type: DocumentType, status: DocumentStatus, days_left: ?int, current: ?VehicleDocument, history: Collection<int, VehicleDocument>}>
     */
    public function rows(Vehicle $vehicle): array
    {
        $byType = $vehicle->documents
            ->sortByDesc(fn (VehicleDocument $document) => [$document->issue_date->toDateString(), $document->id])
            ->groupBy(fn (VehicleDocument $document) => $document->type->value);

        return array_map(function (DocumentType $type) use ($byType) {
            $documents = $byType->get($type->value, collect())->values();
            $current = $documents->first();

            return [
                'type' => $type,
                'status' => $this->status($current),
                'days_left' => $this->daysLeft($current),
                'current' => $current,
                'history' => $documents->slice(1)->values(),
            ];
        }, DocumentType::cases());
    }
}
