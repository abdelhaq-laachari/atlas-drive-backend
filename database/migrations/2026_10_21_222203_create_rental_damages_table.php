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
        // A rental's frozen copy of the car's damage (for the contract): what was on the car at
        // hand-over, and what was new at return. The car's live record is vehicle_damages.
        Schema::create('rental_damages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_damage_id')->nullable()->constrained()->nullOnDelete();
            $table->string('stage', 20); // handover, return
            $table->boolean('known'); // true = already on the car's record before this moment
            $table->decimal('x', 5, 4);
            $table->decimal('y', 5, 4);
            $table->string('area', 50);
            $table->string('type', 20);
            $table->string('size', 10);
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index(['rental_id', 'stage']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rental_damages');
    }
};
