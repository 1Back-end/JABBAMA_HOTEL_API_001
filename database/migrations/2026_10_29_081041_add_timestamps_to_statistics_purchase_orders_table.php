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
            // Vérifie si la colonne created_at n'existe pas avant de l'ajouter
            if (!Schema::hasColumn('statistics_purchase_orders', 'created_at')) {
                $table->timestamps();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('statistics_purchase_orders', function (Blueprint $table) {
            if (Schema::hasColumn('statistics_purchase_orders', 'created_at')) {
                $table->dropTimestamps();
            }
        });
    }
};
