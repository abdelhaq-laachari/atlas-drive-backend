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
        // Personal data (law 09-08): ID and licence numbers are masked in the activity log.
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('customer_number'); // shown as C-001, per agency
            $table->string('type', 20)->default('individual'); // company: later

            $table->string('first_name', 50);
            $table->string('last_name', 50);
            $table->string('phone', 20); // stored as + and digits only: +212600000001
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('city', 50)->nullable();

            $table->string('id_type', 20); // cin, passport
            $table->string('id_number', 20); // as typed, uppercase
            $table->string('id_key', 20); // letters + digits only, for duplicate checks
            $table->string('nationality', 50);
            $table->date('birth_date');

            // Needed to drive (age / licence checks); may be empty for a customer who pays but doesn't drive.
            $table->string('licence_number', 30)->nullable();
            $table->date('licence_issue_date')->nullable();

            $table->text('notes')->nullable();

            // Blocklist: can't be chosen for a new rental.
            $table->timestamp('blocked_at')->nullable();
            $table->string('block_reason')->nullable();

            $table->timestamp('archived_at')->nullable();
            $table->string('archive_reason')->nullable();

            $table->timestamps();

            $table->unique(['agency_id', 'customer_number']);
            $table->unique(['agency_id', 'id_key']);
            $table->index(['agency_id', 'last_name', 'first_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
