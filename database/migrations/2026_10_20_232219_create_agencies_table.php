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
        Schema::create('agencies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            // Legal numbers printed on contracts and invoices (filled in by the owner)
            $table->string('ice', 15)->nullable(); // Identifiant Commun de l'Entreprise (15 digits)
            $table->string('if_number', 20)->nullable(); // Identifiant Fiscal
            $table->string('rc_number', 30)->nullable(); // Registre de Commerce
            $table->string('patente_number', 30)->nullable(); // Taxe professionnelle

            // Set once at creation (super admin / agency:create); never changed from Settings
            $table->char('currency', 3)->default('MAD');
            $table->string('timezone')->default('Africa/Casablanca');

            // Business rules each agency can change in Settings.
            $table->unsignedSmallInteger('late_grace_minutes')->default(60);
            $table->decimal('fuel_price_per_quarter', 10, 2)->default(150);
            $table->unsignedTinyInteger('driver_min_age')->default(21);
            $table->unsignedTinyInteger('licence_min_years')->default(2);
            $table->unsignedSmallInteger('document_expiring_soon_days')->default(30);
            // Off: at return the system shows facts only and the agent decides the price.
            $table->boolean('suggest_return_prices')->default(false);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agencies');
    }
};
