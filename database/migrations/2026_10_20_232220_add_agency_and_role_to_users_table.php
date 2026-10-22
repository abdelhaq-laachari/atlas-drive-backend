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
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('agency_id')->after('id')->constrained()->cascadeOnDelete();
            $table->string('role')->after('email')->default('staff');
            // Temporary password given by the super admin / owner: must be changed at first login
            $table->boolean('must_change_password')->after('password')->default(false);
            // Staff who leave are deactivated, never deleted (history keeps who did what)
            $table->timestamp('deactivated_at')->nullable()->after('must_change_password');
            $table->timestamp('last_login_at')->nullable()->after('deactivated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agency_id');
            $table->dropColumn(['role', 'must_change_password', 'deactivated_at', 'last_login_at']);
        });
    }
};
