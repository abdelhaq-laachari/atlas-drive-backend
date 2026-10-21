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
        // Footprints: who did what, on which item, when. Rows are never edited or deleted.
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained(); // null = System (seeders, scheduled tasks)
            $table->string('action', 50); // created, updated, status_changed, archived, restored...
            $table->string('subject_type', 50); // morph alias: vehicle, customer, rental...
            $table->unsignedBigInteger('subject_id');
            $table->string('subject_label'); // frozen name, e.g. "V-011 Dacia Logan"
            $table->json('diff')->nullable(); // { field: { old, new } } (not "changes": Eloquent models already use that name)
            $table->timestamp('created_at')->useCurrent();

            $table->index(['agency_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index(['agency_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
