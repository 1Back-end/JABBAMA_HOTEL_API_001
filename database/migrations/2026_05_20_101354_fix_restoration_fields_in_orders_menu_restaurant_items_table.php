<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders_menu_restaurant_items', function (Blueprint $table) {

            // 🔥 supprimer FK si existe
            try {
                $table->dropForeign(['restorated_by']);
            } catch (\Exception $e) {
            }

            // 🔥 supprimer colonnes si existent
            $columns = [
                'is_restored',
                'restorated_by',
                'reason_of_restoration',
                'restorated_at'
            ];

            foreach ($columns as $column) {

                if (Schema::hasColumn('orders_menu_restaurant_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('orders_menu_restaurant_items', function (Blueprint $table) {

            // 🔥 recréation propre
            $table->boolean('is_restored')
                ->default(false)
                ->after('status');

            $table->foreignId('restorated_by')
                ->nullable()
                ->after('is_restored')
                ->constrained('users')
                ->nullOnDelete();

            $table->text('reason_of_restoration')
                ->nullable()
                ->after('restorated_by');

            $table->timestamp('restorated_at')
                ->nullable()
                ->after('reason_of_restoration');
        });
    }

    public function down(): void
    {
        Schema::table('orders_menu_restaurant_items', function (Blueprint $table) {

            try {
                $table->dropForeign(['restorated_by']);
            } catch (\Exception $e) {
            }

            $columns = [
                'is_restored',
                'restorated_by',
                'reason_of_restoration',
                'restorated_at'
            ];

            foreach ($columns as $column) {

                if (Schema::hasColumn('orders_menu_restaurant_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
