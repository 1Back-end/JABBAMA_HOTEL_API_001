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
        Schema::create('orders_menu_restaurants', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->string('code')->unique();

            $table->string('status')->nullable()->default('pending');

            $table->integer('unit_price')->nullable();
            $table->integer('total_price')->default(0);
            $table->boolean('is_for_sale_free')->default(false);

            $table->enum('consumption_type', ['dine_in', 'take_away'])->default('dine_in');

            $table->text('description')->nullable();
            $table->string('reason_cancel')->nullable();

            $table->timestamp('validated_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->uuid('restaurant_table_uuid')->nullable();
            $table->foreign('restaurant_table_uuid')->references('uuid')->on('restaurant_tables')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders_menu_restaurants');
    }
};
