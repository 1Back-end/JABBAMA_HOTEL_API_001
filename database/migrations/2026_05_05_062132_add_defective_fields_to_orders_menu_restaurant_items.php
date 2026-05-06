<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 🔥 1. DETECT FK REELLE
        $fks = DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_NAME = 'orders_menu_restaurant_items'
            AND COLUMN_NAME = 'defective_by'
            AND CONSTRAINT_SCHEMA = DATABASE()
            AND REFERENCED_TABLE_NAME IS NOT NULL
        ");

        Schema::table('orders_menu_restaurant_items', function (Blueprint $table) use ($fks) {

            // 🔥 DROP SAFE FK
            foreach ($fks as $fk) {
                try {
                    $table->dropForeign($fk->CONSTRAINT_NAME);
                } catch (\Exception $e) {}
            }

            // 🔹 colonnes safe
            if (!Schema::hasColumn('orders_menu_restaurant_items', 'defective_by')) {
                $table->unsignedBigInteger('defective_by')->nullable();
            }

            if (!Schema::hasColumn('orders_menu_restaurant_items', 'is_defective')) {
                $table->boolean('is_defective')->default(false);
            }

            if (!Schema::hasColumn('orders_menu_restaurant_items', 'reason_of_defective')) {
                $table->string('reason_of_defective')->nullable();
            }

            if (!Schema::hasColumn('orders_menu_restaurant_items', 'defective_at')) {
                $table->timestamp('defective_at')->nullable();
            }
        });

        // 🔥 2. RECREATE FK PROPRE
        Schema::table('orders_menu_restaurant_items', function (Blueprint $table) {

            $table->foreign('defective_by', 'fk_defective_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders_menu_restaurant_items', function (Blueprint $table) {

            try {
                $table->dropForeign('fk_defective_by');
            } catch (\Exception $e) {}

            $columns = [
                'defective_by',
                'is_defective',
                'reason_of_defective',
                'defective_at'
            ];

            foreach ($columns as $col) {
                if (Schema::hasColumn('orders_menu_restaurant_items', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
