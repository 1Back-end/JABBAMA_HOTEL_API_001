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
        Schema::table('complement_virtual_temps_backup', function (Blueprint $table) {
            $table->uuid('warehouse_uuid')->nullable();

            // Clé étrangère vers la table des entrepôts
            $table->foreign('warehouse_uuid')
                ->references('uuid')
                ->on('warehouses')
                ->onDelete('set null')
                ->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('complement_virtual_temps_backup', function (Blueprint $table) {
            $table->dropForeign(['warehouse_uuid']);
            $table->dropColumn('warehouse_uuid');
        });
    }
};
