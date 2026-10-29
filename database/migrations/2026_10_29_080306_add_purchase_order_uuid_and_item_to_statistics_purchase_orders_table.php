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
        Schema::table('statistics_purchase_orders', function (Blueprint $table) {
            // Supprime les colonnes si elles existent déjà pour éviter l'erreur de duplication
            if (Schema::hasColumn('statistics_purchase_orders', 'purchase_order_uuid')) {
                $table->dropColumn('purchase_order_uuid');
            }
            if (Schema::hasColumn('statistics_purchase_orders', 'product_uuid')) {
                $table->dropColumn('product_uuid');
            }
            if (Schema::hasColumn('statistics_purchase_orders', 'quantity')) {
                $table->dropColumn('quantity');
            }
        });

        // Recréation propre des colonnes
        Schema::table('statistics_purchase_orders', function (Blueprint $table) {
            $table->uuid('purchase_order_uuid')->nullable()->after('uuid');
            $table->uuid('product_uuid')->nullable()->after('purchase_order_uuid');
            $table->decimal('quantity', 15, 3)->default(0)->after('product_uuid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('statistics_purchase_orders', function (Blueprint $table) {
            $table->dropColumn(['purchase_order_uuid', 'product_uuid', 'quantity']);
        });
    }
};
