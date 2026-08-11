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
        Schema::create('other_cash_ins', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->string('name');
            $table->integer('amount')->nullable()->default(0);
            $table->string('status')->default('validated');
            $table->string('slug')->default('AUTRES ENCAISSEMENTS');
            $table->string('attachment')->nullable();

            $table->uuid('regulation_method_uuid')->nullable();

            $table->foreign('regulation_method_uuid')
                ->references('uuid')
                ->on('regulation_methods')
                ->onDelete('set null')
                ->onUpdate('cascade');

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('cancelled_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('validated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('reason_of_cancelled')->nullable();

            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('other_cash_ins');
    }
};
