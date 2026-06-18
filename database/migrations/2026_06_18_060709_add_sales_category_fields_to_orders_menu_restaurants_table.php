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
        if (Schema::hasColumn('orders_menu_restaurants', 'sales_category_type') ||
            Schema::hasColumn('orders_menu_restaurants', 'sales_category_uuid')) {

            Schema::table('orders_menu_restaurants', function (Blueprint $table) {
                try {
                    $table->dropForeign(['sales_category_uuid']);
                    $table->dropIndex(['sales_category_type']);
                    $table->dropIndex(['sales_category_uuid']);
                } catch (\Exception $e) {
                }

                if (Schema::hasColumn('orders_menu_restaurants', 'sales_category_type')) {
                    $table->dropColumn('sales_category_type');
                }
                if (Schema::hasColumn('orders_menu_restaurants', 'sales_category_uuid')) {
                    $table->dropColumn('sales_category_uuid');
                }
            });
        }

        Schema::table('orders_menu_restaurants', function (Blueprint $table) {
            $table->string('sales_category_type')->nullable()->after('full_name');
            $table->uuid('sales_category_uuid')->nullable()->after('sales_category_type');

            $table->foreign('sales_category_uuid')
                ->references('uuid')
                ->on('sales_categories')
                ->nullOnDelete();

            $table->index('sales_category_type');
            $table->index('sales_category_uuid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders_menu_restaurants', function (Blueprint $table) {
            $table->dropForeign(['sales_category_uuid']);
            $table->dropIndex(['sales_category_type']);
            $table->dropIndex(['sales_category_uuid']);

            $table->dropColumn('sales_category_type');
            $table->dropColumn('sales_category_uuid');
        });
    }
};
