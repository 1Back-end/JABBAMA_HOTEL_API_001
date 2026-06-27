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
        Schema::create('payment_lines', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('payment_uuid')->index();
            $table->string('payable_type');
            $table->decimal('amount', 15, 2)->default(0);
            $table->uuid('payable_uuid')->index();
            $table->uuid('regulation_method_uuid')->nullable();

            $table->string('phone_number')->nullable();
            $table->string('reference')->nullable();
            $table->text('detail')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->softDeletes();
            $table->timestamps();

            $table->foreign('payment_uuid')
                ->references('uuid')
                ->on('payments')
                ->onDelete('cascade');

            $table->index(['payable_type', 'payable_uuid']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_lines');
    }
};
