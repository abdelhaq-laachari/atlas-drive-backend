<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database (local development only).
     */
    public function run(): void
    {
        // Placeholder for the first customer's agency; real details come later from Settings.
        $agency = Agency::factory()->create([
            'name' => 'Atlas Drive',
            'city' => 'Casablanca',
        ]);

        User::factory()->owner()->for($agency)->create([
            'name' => 'Agency Owner',
            'email' => 'owner@atlasdrive.test',
        ]);

        User::factory()->for($agency)->create([
            'name' => 'Front Desk',
            'email' => 'staff@atlasdrive.test',
        ]);

        $this->call(VehicleSeeder::class, parameters: ['agency' => $agency]);
        $this->call(VehicleDocumentSeeder::class, parameters: ['agency' => $agency]);
        $this->call(VehicleDamageSeeder::class, parameters: ['agency' => $agency]);
        $this->call(CustomerSeeder::class, parameters: ['agency' => $agency]);
        $this->call(RentalSeeder::class, parameters: ['agency' => $agency]);
    }
}
