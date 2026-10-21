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
        // The live damage record of each car: open damage (shown on the drawing at the next hand-over)
        // and repaired damage (history). Rentals will keep their own frozen copy.
        Schema::create('vehicle_damages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();

            // Position on the car drawing, 0 to 1 (0,0 = front-left corner): same on any screen size.
            $table->decimal('x', 5, 4);
            $table->decimal('y', 5, 4);
            $table->string('area', 50); // car part, e.g. "Rear bumper"
            $table->string('type', 20); // scratch, dent, crack, broken, other
            $table->string('size', 10); // small, medium, large
            $table->string('note', 500)->nullable();

            $table->timestamp('found_at');
            $table->string('found_stage', 20)->nullable(); // handover, return, or null = outside a rental
            // found_in rental: column added by the Rentals module

            $table->string('status', 20)->default('open'); // open, repaired
            $table->date('repaired_at')->nullable();
            $table->decimal('repair_cost', 10, 2)->nullable(); // what the workshop cost the agency
            $table->string('repair_note', 500)->nullable();

            $table->timestamps();

            $table->index(['vehicle_id', 'status', 'found_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_damages');
    }
};
