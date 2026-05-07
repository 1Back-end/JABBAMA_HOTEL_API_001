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
        Schema::table('drink_compositions', function (Blueprint $table) {

            // ❌ remove old columns
            $table->dropForeign(['product_uuid']);
            $table->dropColumn('product_uuid');

            $table->dropColumn('quantity_used');

            // ✅ add new column
            $table->uuid('warehouse_uuid')->after('drinks_restaurant_uuid');

            // FK warehouse
            $table->foreign('warehouse_uuid')
                ->references('uuid')
                ->on('warehouses')
                ->onDelete('cascade');

            // index
            $table->index('warehouse_uuid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('drink_compositions', function (Blueprint $table) {

            // rollback
            $table->dropForeign(['warehouse_uuid']);
            $table->dropColumn('warehouse_uuid');

            $table->uuid('product_uuid')->after('drinks_restaurant_uuid');
            $table->integer('quantity_used')->default(0);

            $table->foreign('product_uuid')
                ->references('uuid')
                ->on('produits')
                ->onDelete('cascade');

            $table->index('product_uuid');
        });
    }
};
