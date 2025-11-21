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
        Schema::table('supply_items', function (Blueprint $table) {
            $table->uuid('supplier_uuid')->nullable()->after('product_uuid');

            // Définir la clé étrangère
            $table->foreign('supplier_uuid')
                ->references('uuid')
                ->on('suppliers')
                ->onDelete('set null'); // met null si le fournisseur est supprimé
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supply_items', function (Blueprint $table) {
            $table->dropForeign(['supplier_uuid']);
            $table->dropColumn('supplier_uuid');
        });
    }
};
