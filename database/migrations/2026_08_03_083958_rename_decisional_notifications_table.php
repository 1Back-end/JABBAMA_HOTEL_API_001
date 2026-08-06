<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 💡 On renomme seulement si la table source existe et que la cible n'existe pas encore
        if (Schema::hasTable('decisional_notifications') && !Schema::hasTable('purchase_orders_decisional_notifications')) {
            Schema::rename('decisional_notifications', 'purchase_orders_decisional_notifications');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('purchase_orders_decisional_notifications') && !Schema::hasTable('decisional_notifications')) {
            Schema::rename('purchase_orders_decisional_notifications', 'decisional_notifications');
        }
    }
};
