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
        Schema::create('payment_regulations', function (Blueprint $table) {
            $table->uuid('uuid')->primary();

            $table->uuid('payment_uuid');

            $table->uuid('regulation_method_uuid');

            $table->decimal('amount', 15, 2);

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

            $table->index('payment_uuid');
            $table->index('regulation_method_uuid');

            $table->foreign('payment_uuid')
                ->references('uuid')
                ->on('payments')
                ->onDelete('cascade');

            $table->foreign('regulation_method_uuid')
                ->references('uuid')
                ->on('regulation_methods')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_regulations');
    }
};
