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
        // Lifecycle: reserved -> active (handed over) -> completed (returned), or cancelled.
        // "Overdue" is never stored: an active rental past return_at + the agency's grace minutes.
        Schema::create('rentals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('rental_number'); // shown as R-0001, per agency

            $table->foreignId('vehicle_id')->constrained();
            $table->foreignId('customer_id')->constrained('customers'); // who signs and pays
            $table->foreignId('driver_id')->constrained('customers'); // who drives (often the same person)

            $table->string('status', 20)->default('reserved');
            $table->timestamp('pickup_at'); // planned, UTC
            $table->timestamp('return_at'); // planned, UTC (moves when extended)
            $table->unsignedSmallInteger('days'); // billed days for pickup_at -> return_at
            $table->decimal('daily_price', 10, 2); // frozen copy of the car's price at booking

            // Deposit (caution): any method with a reference; numbered receipt DEP-0001 per agency
            $table->decimal('deposit_amount', 10, 2)->default(0);
            $table->string('deposit_method', 20)->nullable();
            $table->string('deposit_reference', 50)->nullable();
            $table->unsignedInteger('deposit_number')->nullable();
            $table->decimal('deposit_kept', 10, 2)->default(0); // kept at return to pay what is due
            $table->timestamp('deposit_returned_at')->nullable(); // rest given back (return or cancel)

            // Hand-over: the car leaves (fuel in quarters: 0 = empty ... 4 = full)
            $table->timestamp('handed_over_at')->nullable();
            $table->unsignedInteger('handover_mileage')->nullable();
            $table->unsignedTinyInteger('handover_fuel')->nullable();
            $table->text('handover_remarks')->nullable();

            // Return: the car comes back
            $table->timestamp('returned_at')->nullable();
            $table->unsignedInteger('return_mileage')->nullable();
            $table->unsignedTinyInteger('return_fuel')->nullable();
            $table->text('return_remarks')->nullable();

            $table->text('notes')->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();

            $table->timestamps();

            $table->unique(['agency_id', 'rental_number']);
            $table->unique(['agency_id', 'deposit_number']);
            $table->index(['vehicle_id', 'status', 'pickup_at']);
            $table->index(['agency_id', 'status', 'pickup_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rentals');
    }
};
