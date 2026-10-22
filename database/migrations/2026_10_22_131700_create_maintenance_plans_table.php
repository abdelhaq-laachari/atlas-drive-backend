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
        // A car's routine: "oil change every 10,000 km or 12 months" (whichever comes first).
        // The next due date / km is computed from the last finished job of that type,
        // or from the starting point below when the car has no job yet.
        Schema::create('maintenance_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->unsignedInteger('every_km')->nullable();
            $table->unsignedSmallInteger('every_months')->nullable();
            $table->date('start_date'); // last time it was done before the car was in the system
            $table->unsignedInteger('start_mileage');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['vehicle_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('maintenance_plans');
    }
};
