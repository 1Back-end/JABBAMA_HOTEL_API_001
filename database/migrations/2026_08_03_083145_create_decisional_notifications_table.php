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
        // 💡 Supprime la table si elle existe déjà pour repartir sur une base propre
        Schema::dropIfExists('decisional_notifications');

        Schema::create('decisional_notifications', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('purchase_order_uuid');

            $table->foreign('purchase_order_uuid', 'fk_decisional_notif_po_uuid')
                ->references('uuid')
                ->on('purchase_orders')
                ->onDelete('cascade');

            $table->string('status')->nullable();
            $table->text('message');
            $table->string('target')->nullable();

            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreign('user_id', 'fk_dec_notif_user_id')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by', 'fk_dec_notif_created_by')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->unsignedBigInteger('updated_by')->nullable();
            $table->foreign('updated_by', 'fk_dec_notif_updated_by')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('decisional_notifications');
    }
};
