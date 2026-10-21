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
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();

            // Shown as fleet code V-001; numbered per agency, never reused.
            $table->unsignedInteger('fleet_number');

            $table->string('make', 50);
            $table->string('model', 50);
            $table->unsignedSmallInteger('year');
            $table->string('plate', 20); // display form, e.g. 30577-B-6
            $table->string('plate_key', 20); // letters + digits only, for duplicate checks: 30577B6
            $table->char('vin', 17)->nullable();
            $table->string('category', 20);
            $table->string('fuel', 20);
            $table->string('transmission', 20);
            $table->unsignedTinyInteger('seats');
            $table->unsignedTinyInteger('doors');
            $table->string('color', 30)->nullable();
            $table->unsignedInteger('mileage');
            $table->date('first_registration')->nullable();
            $table->string('status', 20)->default('available');
            $table->text('notes')->nullable();

            // Pricing (MAD). Extra-km fee is a per-km rate and may have cents.
            $table->decimal('daily_price', 10, 2);
            $table->decimal('weekly_price', 10, 2);
            $table->decimal('monthly_price', 10, 2);
            $table->decimal('deposit', 10, 2);
            $table->unsignedSmallInteger('included_km_per_day')->nullable(); // null = unlimited
            $table->decimal('extra_km_fee', 10, 2);

            // Archived = sold or retired: hidden from lists, never deleted (history stays).
            $table->timestamp('archived_at')->nullable();
            $table->string('archive_reason')->nullable();

            $table->timestamps();

            $table->unique(['agency_id', 'fleet_number']);
            $table->unique(['agency_id', 'plate_key']);
            $table->unique(['agency_id', 'vin']);
            $table->index(['agency_id', 'archived_at', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
