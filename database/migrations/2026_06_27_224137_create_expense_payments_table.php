<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_payments', function (Blueprint $table) {

            $table->uuid('uuid')->primary();

            $table->uuid('restaurant_expense_type_uuid');
            $table->uuid('restaurant_expense_family_uuid');

            $table->uuid('regulation_method_uuid');

            $table->decimal('amount', 15, 2);
            $table->string('name');
            $table->text('description')->nullable();

            $table->timestamp('paid_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('restaurant_expense_type_uuid')
                ->references('uuid')
                ->on('restaurant_expense_types')
                ->cascadeOnDelete();

            $table->foreign('restaurant_expense_family_uuid')
                ->references('uuid')
                ->on('restaurant_expense_types_families')
                ->cascadeOnDelete();

            $table->foreign('regulation_method_uuid')
                ->references('uuid')
                ->on('regulation_methods')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_payments');
    }
};
