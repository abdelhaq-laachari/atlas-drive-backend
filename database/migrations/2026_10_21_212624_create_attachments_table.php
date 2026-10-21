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
        // Files attached to a customer, rental, car, car document or damage.
        // The file itself is encrypted on the private disk; only AttachmentController sends it.
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->string('attachable_type', 50); // morph alias: customer, rental, vehicle...
            $table->unsignedBigInteger('attachable_id');
            $table->string('category', 30); // id_front, signed_contract, scan, photo...
            $table->string('original_name'); // name of the uploaded file, shown to staff
            $table->string('mime', 100); // after processing: image/jpeg or application/pdf
            $table->unsignedInteger('size'); // bytes, after processing (before encryption)
            $table->string('path'); // random name on the private disk
            $table->foreignId('uploaded_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['attachable_type', 'attachable_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
