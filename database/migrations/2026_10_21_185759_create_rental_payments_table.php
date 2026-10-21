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
        // Money received for a rental. method "deposit" = kept from the deposit at return.
        Schema::create('rental_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('method', 20); // cash, card, cheque, transfer, deposit
            $table->string('reference', 50)->nullable(); // cheque no., card authorisation, transfer ref.
            $table->timestamp('paid_at');
            $table->timestamps();

            $table->index(['rental_id', 'paid_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rental_payments');
    }
};
