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
        // Insurance, technical inspection, vignette, registration card. Renewing adds a new row;
        // the row with the newest issue date of each type is the current one, older rows are history.
        Schema::create('vehicle_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('reference', 50)->nullable(); // policy / certificate / receipt / card number
            $table->string('provider', 100)->nullable(); // insurer, inspection centre
            $table->date('issue_date');
            $table->date('expiry_date')->nullable(); // null for the registration card
            $table->decimal('cost', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['vehicle_id', 'type', 'issue_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_documents');
    }
};
