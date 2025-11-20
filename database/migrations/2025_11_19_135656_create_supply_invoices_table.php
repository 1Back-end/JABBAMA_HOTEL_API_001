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
        Schema::create('supply_invoices', function (Blueprint $table) {
            $table->uuid('uuid')->primary();

            // 🔗 Lien avec l'approvisionnement
            $table->uuid('supply_uuid');
            $table->foreign('supply_uuid')->references('uuid')->on('supplies')->onDelete('cascade');

            // Numéro de facture ou référence
            $table->string('invoice_number')->nullable()->comment('Numéro ou référence de la facture');

            // Prix total (optionnel)
            $table->decimal('total_amount', 15, 2)->nullable()->comment('Prix total de la facture');

            // Prix unitaire calculé automatiquement
            $table->decimal('unit_price', 15, 2)->nullable()->comment('Prix unitaire calculé depuis le total et la quantité');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supply_invoices');
    }
};
