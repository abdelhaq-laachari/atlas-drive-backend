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
        // Every amount of a rental is a line: what the system suggested and what was really charged.
        // Rental total = sum of amount. Never force a charge: reduce / waive needs a reason.
        Schema::create('rental_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20); // rental, extension, late, extra_km, fuel, damage, extra
            $table->string('label'); // "Rental 4 days x 270 MAD"
            $table->decimal('suggested', 10, 2)->nullable(); // null = no price suggested (return facts)
            $table->decimal('amount', 10, 2);
            $table->string('decision', 10); // charge, reduce, waive
            $table->string('reason')->nullable();
            // For damage lines: which damage on the car (to compare charged vs repair cost)
            $table->foreignId('vehicle_damage_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['rental_id', 'kind']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rental_charges');
    }
};
