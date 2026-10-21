<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The rental during which the damage was found (null = found outside a rental).
        Schema::table('vehicle_damages', function (Blueprint $table) {
            $table->foreignId('rental_id')->nullable()->after('found_stage')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehicle_damages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rental_id');
        });
    }
};
