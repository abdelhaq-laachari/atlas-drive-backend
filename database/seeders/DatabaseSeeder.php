<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

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
    }
}
