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
        Schema::table('warehouses', function (Blueprint $table) {
            $table->foreignId('manager_id')->nullable()->constrained('users')->after('updated_by');

            // Modifier nature -> nature_uuid
            $table->dropColumn('nature'); // Supprime l'ancienne colonne
            $table->uuid('nature_uuid')->nullable()->after('name'); // Nouvelle colonne
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropForeign(['manager_id']);
            $table->dropColumn('manager_id');

            $table->dropColumn('nature_uuid');
            $table->string('nature', 100)->nullable()->after('name');
        });
    }
};
