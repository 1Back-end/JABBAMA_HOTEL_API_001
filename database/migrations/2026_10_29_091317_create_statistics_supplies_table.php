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
        if (!Schema::hasTable('statistics_supplies')) {
            Schema::create('statistics_supplies', function (Blueprint $table) {
                $table->uuid('uuid')->primary();
                $table->uuid('purchase_order_uuid')->nullable();
                $table->uuid('product_uuid')->nullable();
                $table->uuid('supplier_uuid')->nullable();

                $table->decimal('quantity', 15, 3)->default(0);
                $table->string('status')->default('draft');

                $table->timestamp('transfered_at')->nullable();
                $table->unsignedBigInteger('transfered_by')->nullable();

                $table->timestamp('validated_at')->nullable();
                $table->unsignedBigInteger('validated_by')->nullable();

                $table->timestamp('rejected_at')->nullable();
                $table->unsignedBigInteger('rejected_by')->nullable();

                $table->timestamp('received_at')->nullable();
                $table->unsignedBigInteger('received_by')->nullable();

                $table->timestamp('approved_at')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();

                $table->timestamp('cancelled_at')->nullable();
                $table->unsignedBigInteger('cancelled_by')->nullable();

                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();

                $table->timestamps();
                $table->softDeletes();

                $table->foreign('purchase_order_uuid')->references('uuid')->on('purchase_orders')->onDelete('cascade');
                $table->foreign('product_uuid')->references('uuid')->on('produits')->onDelete('cascade');
                $table->foreign('supplier_uuid')->references('uuid')->on('suppliers')->onDelete('set null');

                $table->foreign('transfered_by')->references('id')->on('users')->onDelete('set null');
                $table->foreign('validated_by')->references('id')->on('users')->onDelete('set null');
                $table->foreign('rejected_by')->references('id')->on('users')->onDelete('set null');
                $table->foreign('received_by')->references('id')->on('users')->onDelete('set null');
                $table->foreign('approved_by')->references('id')->on('users')->onDelete('set null');
                $table->foreign('cancelled_by')->references('id')->on('users')->onDelete('set null');
                $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
                $table->foreign('updated_by')->references('id')->on('users')->onDelete('set null');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('statistics_supplies');
    }
};
