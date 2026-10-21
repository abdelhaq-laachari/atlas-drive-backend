<?php

namespace Database\Seeders;

use App\Enums\DocumentType;
use App\Models\Agency;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Sample documents (like the Vue prototype's sampleDocuments.js), relative to today so the
 * same cars always show as expired or expiring:
 *   V-005 insurance expired 3 days ago     V-009 inspection expired 10 days ago
 *   V-002 inspection expires in 9 days     V-008 insurance expires in 12 days
 *   V-010 registration card missing
 */
class VehicleDocumentSeeder extends Seeder
{
    /**
     * Days until expiry for the cars above; every other car gets a date that stays valid.
     *
     * @var array<int, array<string, int>>
     */
    private array $overrides = [
        5 => ['insurance' => -3],
        9 => ['inspection' => -10],
        2 => ['inspection' => 9],
        8 => ['insurance' => 12],
    ];

    public function run(Agency $agency): void
    {
        $today = Carbon::now($agency->timezone)->startOfDay();
        $insuranceCost = ['economy' => 4500, 'compact' => 5500, 'suv' => 7500, 'van' => 7000, 'luxury' => 14000];
        $vignetteCost = ['economy' => 700, 'compact' => 700, 'suv' => 1500, 'van' => 1500, 'luxury' => 6000];

        Vehicle::withoutGlobalScopes()->where('agency_id', $agency->id)->get()->each(
            function (Vehicle $vehicle) use ($agency, $today, $insuranceCost, $vignetteCost) {
                $n = $vehicle->fleet_number;
                $code = str_pad((string) $n, 4, '0', STR_PAD_LEFT);
                $category = $vehicle->category->value;

                // A yearly document ending in $days days
                $yearly = function (string $type, int $defaultDays) use ($n, $today) {
                    $expiry = $today->copy()->addDays($this->overrides[$n][$type] ?? $defaultDays);

                    return ['issue_date' => $expiry->copy()->subYear()->addDay(), 'expiry_date' => $expiry];
                };

                $insurance = $yearly('insurance', (($n * 47) % 300) + 40);
                $this->add($agency, $vehicle, DocumentType::Insurance, "SAMPLE-POL-{$code}-OLD", 'Wafa Assurance', [
                    'issue_date' => $insurance['issue_date']->copy()->subYear(),
                    'expiry_date' => $insurance['issue_date']->copy()->subDay(),
                ], $insuranceCost[$category] - 300);
                $this->add($agency, $vehicle, DocumentType::Insurance, "SAMPLE-POL-{$code}", 'Wafa Assurance', $insurance, $insuranceCost[$category]);

                $this->add($agency, $vehicle, DocumentType::Inspection, "SAMPLE-VT-{$code}", 'Sample inspection centre',
                    $yearly('inspection', (($n * 61) % 320) + 35), 300);

                // The vignette is paid once a year and runs to the end of December
                $this->add($agency, $vehicle, DocumentType::Vignette, "SAMPLE-VIG-{$today->year}-{$code}", null, [
                    'issue_date' => $today->copy()->setDate($today->year, 1, 15),
                    'expiry_date' => $today->copy()->setDate($today->year, 12, 31),
                ], $vignetteCost[$category]);

                if ($n !== 10) {
                    $this->add($agency, $vehicle, DocumentType::Registration, "SAMPLE-CG-{$code}", null, [
                        'issue_date' => $vehicle->first_registration ?? $today->copy()->subYears(2),
                        'expiry_date' => null,
                    ], null);
                }
            }
        );
    }

    /**
     * @param  array{issue_date: mixed, expiry_date: mixed}  $period
     */
    private function add(Agency $agency, Vehicle $vehicle, DocumentType $type, string $reference, ?string $provider, array $period, ?int $cost): void
    {
        $document = new VehicleDocument([
            'type' => $type,
            'reference' => $reference,
            'provider' => $provider,
            'issue_date' => $period['issue_date'],
            'expiry_date' => $period['expiry_date'],
            'cost' => $cost,
        ]);

        // Seeders run with nobody logged in, so the agency is set by hand.
        $document->agency_id = $agency->id;
        $document->vehicle()->associate($vehicle);
        $document->save();
    }
}
