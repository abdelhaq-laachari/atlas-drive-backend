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
        // One visit to the garage: scheduled -> in progress (car "in maintenance") -> done, or cancelled.
        Schema::create('maintenance_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained();
            $table->string('type', 20); // oil_change, tyres, brakes, battery, service, repair, bodywork, other
            $table->string('status', 20)->default('scheduled');
            $table->date('scheduled_for')->nullable(); // planned day (agency day)
            $table->timestamp('started_at')->nullable(); // car went to the garage (UTC)
            $table->timestamp('completed_at')->nullable(); // car came back (UTC)
            $table->string('garage', 100)->nullable();
            $table->unsignedInteger('mileage')->nullable(); // km when the work was done
            $table->decimal('cost', 10, 2)->nullable(); // what the garage charged the agency (MAD)
            $table->text('description')->nullable(); // what was done / to do
            $table->string('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['vehicle_id', 'status']);
            $table->index(['agency_id', 'status', 'scheduled_for']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('maintenance_jobs');
    }
};
