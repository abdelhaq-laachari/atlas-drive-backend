<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\Customer;
use Illuminate\Database\Seeder;

/**
 * The Vue prototype's 12 invented customers (sampleCustomers.js): ID numbers start with "XX",
 * phones are +212 600-000xxx and emails use example.com. C-009 is blocked as a sample.
 */
class CustomerSeeder extends Seeder
{
    public function run(Agency $agency): void
    {
        $people = [
            ['Amine', 'Kettani', 'cin', 'XX100001', 'Moroccan', '1990-04-12', '2010-06-01', 'Casablanca'],
            ['Sara', 'Lahlou', 'cin', 'XX100002', 'Moroccan', '1995-09-23', '2015-03-10', 'Rabat'],
            ['Karim', 'Bennani', 'cin', 'XX100003', 'Moroccan', '1987-01-05', '2006-11-20', 'Casablanca'],
            ['Nadia', 'El Idrissi', 'cin', 'XX100004', 'Moroccan', '1992-07-30', '2013-02-14', 'Marrakech'],
            ['Omar', 'Tazi', 'cin', 'XX100005', 'Moroccan', '1985-12-18', '2004-08-09', 'Fes'],
            ['Lina', 'Mansouri', 'cin', 'XX100006', 'Moroccan', '1998-05-02', '2018-07-25', 'Tangier'],
            ['Youssef', 'Alaoui', 'cin', 'XX100007', 'Moroccan', '1993-03-14', '2012-09-30', 'Casablanca'],
            ['Salma', 'Chraibi', 'cin', 'XX100008', 'Moroccan', '1996-10-08', '2017-05-16', 'Agadir'],
            ['Hamza', 'Berrada', 'cin', 'XX100009', 'Moroccan', '2000-02-27', '2020-04-03', 'Casablanca'],
            ['Julie', 'Martin', 'passport', 'XXP200010', 'French', '1988-06-19', '2007-10-11', 'Lyon'],
            ['Daniel', 'Weber', 'passport', 'XXP200011', 'German', '1979-11-03', '1998-05-22', 'Munich'],
            ['Mehdi', 'Fassi', 'cin', 'XX100012', 'Moroccan', '1991-08-21', '2011-01-17', 'Rabat'],
        ];

        foreach ($people as $i => [$first, $last, $idType, $idNumber, $nationality, $birth, $licence, $city]) {
            $n = str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT);

            $customer = new Customer([
                'first_name' => $first,
                'last_name' => $last,
                'phone' => "+212 600-000{$n}",
                'email' => strtolower($first.'.'.str_replace(' ', '', $last)).'@example.com',
                'city' => $city,
                'id_type' => $idType,
                'id_number' => $idNumber,
                'nationality' => $nationality,
                'birth_date' => $birth,
                'licence_number' => "XX-LIC-{$n}",
                'licence_issue_date' => $licence,
            ]);

            // Seeders run with nobody logged in, so the agency is set by hand.
            $customer->agency_id = $agency->id;

            if ($i + 1 === 9) {
                $customer->blocked_at = now()->subDays(20);
                $customer->block_reason = 'Sample: returned the car 3 days late without calling';
            }

            $customer->save();
        }
    }
}
