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
        // 1. PHASE DE NETTOYAGE (Si les colonnes existent déjà d'un test précédent)
        Schema::table('orders_menu_restaurant_items', function (Blueprint $table) {
            if (Schema::hasColumn('orders_menu_restaurant_items', 'rejected_after_validation_by')) {
                $table->dropForeign('omri_rav_by_fk');
                $table->dropColumn('rejected_after_validation_by');
            }

            $table->dropColumn(array_filter([
                Schema::hasColumn('orders_menu_restaurant_items', 'rejected_after_validation_at') ? 'rejected_after_validation_at' : null,
                Schema::hasColumn('orders_menu_restaurant_items', 'reason_of_rejected_after_validation') ? 'reason_of_rejected_after_validation' : null,
                Schema::hasColumn('orders_menu_restaurant_items', 'is_reason_of_cancel_for_new_update') ? 'is_reason_of_cancel_for_new_update' : null,
            ]));
        });

        // 2. PHASE DE CRÉATION
        Schema::table('orders_menu_restaurant_items', function (Blueprint $table) {
            $table->foreignId('rejected_after_validation_by')
                ->nullable()
                ->after('rejected_by')
                ->constrained('users', indexName: 'omri_rav_by_fk')
                ->nullOnDelete();

            $table->timestamp('rejected_after_validation_at')
                ->nullable()
                ->after('rejected_after_validation_by');

            $table->string('reason_of_rejected_after_validation')
                ->nullable()
                ->after('rejected_after_validation_at');

            $table->boolean('is_reason_of_cancel_for_new_update')
                ->default(false)
                ->after('reason_of_rejected_after_validation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders_menu_restaurant_items', function (Blueprint $table) {
            if (Schema::hasColumn('orders_menu_restaurant_items', 'rejected_after_validation_by')) {
                $table->dropForeign('omri_rav_by_fk');
            }

            $table->dropColumn([
                'rejected_after_validation_by',
                'rejected_after_validation_at',
                'reason_of_rejected_after_validation',
                'is_reason_of_cancel_for_new_update'
            ]);
        });
    }
};
