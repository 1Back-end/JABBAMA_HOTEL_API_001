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
        Schema::table('statistics_orders_status_menus_restaurants', function (Blueprint $table) {
            if (!Schema::hasColumn('statistics_orders_status_menus_restaurants', 'rejected_after_validation_at')) {
                $table->timestamp('rejected_after_validation_at')->nullable();
            }

            if (!Schema::hasColumn('statistics_orders_status_menus_restaurants', 'rejected_after_validation_by')) {
                $table->unsignedBigInteger('rejected_after_validation_by')->nullable();

                $table->foreign('rejected_after_validation_by', 'stat_rej_after_val_user_fk')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('statistics_orders_status_menus_restaurants', function (Blueprint $table) {
            if (Schema::hasColumn('statistics_orders_status_menus_restaurants', 'rejected_after_validation_by')) {
                $table->dropForeign('stat_rej_after_val_user_fk');
                $table->dropColumn('rejected_after_validation_by');
            }

            if (Schema::hasColumn('statistics_orders_status_menus_restaurants', 'rejected_after_validation_at')) {
                $table->dropColumn('rejected_after_validation_at');
            }
        });
    }
};
