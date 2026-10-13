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
        Schema::create('stock_snapshots', function (Blueprint $table) {
            $table->uuid('uuid')->primary();

            $table->date('snapshot_date')->nullable();

            $table->uuid('product_uuid')->nullable();
            $table->foreign('product_uuid')
                ->references('uuid')
                ->on('produits')
                ->onDelete('set null')
                ->onUpdate('cascade');

            $table->decimal('initial_quantity', 10, 2)->default(0);

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->unique(['snapshot_date', 'product_uuid']);

            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_snapshots');
    }
};
