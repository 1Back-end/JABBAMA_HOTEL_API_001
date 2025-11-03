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
        Schema::create('supplies', function (Blueprint $table) {
            $table->uuid('uuid')->primary();

            // 🔗 Commande associée
            $table->uuid('purchase_order_uuid')->nullable()
                ->constrained('purchase_orders')->nullOnDelete();

            // 🔗 Entrepôt et fournisseur
            $table->uuid('warehouse_uuid')->nullable()
                ->constrained('warehouses')->nullOnDelete();

            $table->uuid('supplier_uuid')->nullable()
                ->constrained('suppliers')->nullOnDelete();

            // 📦 Informations de l’approvisionnement
            $table->string('reference')->unique();
            $table->date('supply_date')->nullable();
            $table->enum('status', ['pending', 'validated', 'rejected'])->default('pending');
            $table->text('notes')->nullable();

            // 📂 Documents scannés
            $table->json('scanned_documents')->nullable()
                ->comment('Liste des documents scannés liés à l’approvisionnement');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();


            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplies');
    }
};
