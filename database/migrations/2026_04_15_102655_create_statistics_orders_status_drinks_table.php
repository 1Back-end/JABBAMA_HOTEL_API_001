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
        Schema::create('statistics_orders_status_drinks', function (Blueprint $table) {
            $table->uuid('uuid')->primary();

            $table->uuid('order_menu_restaurant_uuid');
            $table->foreign('order_menu_restaurant_uuid', 'sosd_order_fk')
                ->references('uuid')
                ->on('orders_menu_restaurants')
                ->cascadeOnDelete();

            $table->uuid('product_uuid');
            $table->foreign('product_uuid', 'sosd_prod_fk')
                ->references('uuid')
                ->on('produits')
                ->cascadeOnDelete();

            $table->string('status')->default('transferred');
            $table->integer('quantity')->default(0);

            // Auteurs principaux
            $table->foreignId('created_by')->nullable()
                ->constrained('users', indexName: 'sosd_created_by_fk')
                ->nullOnDelete();

            $table->foreignId('updated_by')->nullable()
                ->constrained('users', indexName: 'sosd_updated_by_fk')
                ->nullOnDelete();

            // Status timestamps et auteurs (Raccourcis pour MySQL 64 chars limit)
            $table->timestamp('pending_at')->nullable();
            $table->foreignId('make_pending_by')->nullable()->constrained('users', indexName: 'sosd_pending_by_fk')->nullOnDelete();

            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('make_rejected_by')->nullable()->constrained('users', indexName: 'sosd_rejected_by_fk')->nullOnDelete();

            $table->timestamp('delivered')->nullable();
            $table->foreignId('make_delivered_by')->nullable()->constrained('users', indexName: 'sosd_delivered_by_fk')->nullOnDelete();

            $table->timestamp('ready_at')->nullable();
            $table->foreignId('make_ready_by')->nullable()->constrained('users', indexName: 'sosd_ready_by_fk')->nullOnDelete();

            $table->timestamp('not_delivered_at')->nullable();
            $table->foreignId('make_not_delivered_by')->nullable()->constrained('users', indexName: 'sosd_ndel_by_fk')->nullOnDelete();

            $table->timestamp('partial_delivered_at')->nullable();
            $table->foreignId('make_partial_delivered_by')->nullable()->constrained('users', indexName: 'sosd_pdel_by_fk')->nullOnDelete();

            $table->timestamp('delivered_in_preparation_at')->nullable();
            $table->foreignId('make_delivered_in_preparation_by')->nullable()->constrained('users', indexName: 'sosd_delprep_by_fk')->nullOnDelete();

            $table->timestamp('transferred_at')->nullable();
            $table->foreignId('make_transferred_by')->nullable()->constrained('users', indexName: 'sosd_trans_by_fk')->nullOnDelete();

            $table->timestamp('cancel_for_new_update_at')->nullable();
            $table->foreignId('make_cancel_for_new_update_by')->nullable()->constrained('users', indexName: 'sosd_cancel_by_fk')->nullOnDelete();

            $table->timestamp('in_preparation_at')->nullable();
            $table->foreignId('make_in_preparation_by')->nullable()->constrained('users', indexName: 'sosd_prep_by_fk')->nullOnDelete();

            $table->timestamp('partial_completed_at')->nullable();
            $table->foreignId('make_partial_completed_by')->nullable()->constrained('users', indexName: 'sosd_pcomp_by_fk')->nullOnDelete();

            $table->timestamp('new_rejected_at')->nullable();
            $table->foreignId('make_new_rejected_by')->nullable()->constrained('users', indexName: 'sosd_nrej_by_fk')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('statistics_orders_status_drinks');
    }
};
