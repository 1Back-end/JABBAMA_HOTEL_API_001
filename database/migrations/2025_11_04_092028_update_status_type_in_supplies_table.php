<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplies', function (Blueprint $table) {

            // ✅ Ajouter la colonne "type" seulement si elle n’existe pas
            if (!Schema::hasColumn('supplies', 'type')) {
                $table->string('type')->default('internal')->after('reference');
            }

            // ✅ Modifier la colonne "status" en string (plus d'enum)
            $table->string('status')->default('draft')->change();
        });
    }

    public function down(): void
    {
        Schema::table('supplies', function (Blueprint $table) {

            // 🔙 Supprimer la colonne "type" si elle existe
            if (Schema::hasColumn('supplies', 'type')) {
                $table->dropColumn('type');
            }

            // 🔙 Remettre status en enum
            // ⚠️ Il faut s’assurer que les valeurs existantes correspondent
            $table->enum('status', [
                'draft',
                'open',
                'pending',
                'partially_delivered',
                'delivered',
                'not_delivered',
                'rejected'
            ])->default('draft')->change();
        });
    }
};
