<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            // 🔹 Supprimer l'ancien champ enum
            $table->dropColumn('status');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            // 🔹 Recréer le champ en string (simple texte)
            $table->string('status')->default('draft')->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            // 🔹 Supprimer le champ string
            $table->dropColumn('status');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            // 🔹 Recréer le champ enum d’origine
            $table->enum('status', ['draft', 'open', 'closed', 'rejected', 'modified'])->default('draft')->after('type');
        });
    }
};
