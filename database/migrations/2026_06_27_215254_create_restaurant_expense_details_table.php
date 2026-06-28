<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_expense_details', function (Blueprint $table) {
            $table->uuid('uuid')->primary();

            $table->uuid('payment_regulation_uuid');
            $table->uuid('restaurant_expense_family_uuid');

            $table->string('name');
            $table->text('description')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users');

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users');

            $table->timestamps();
            $table->softDeletes();

            // ✅ FK PAYMENT (nom court)
            $table->foreign('payment_regulation_uuid', 'r_ed_payment_fk')
                ->references('uuid')
                ->on('payment_regulations')
                ->cascadeOnDelete();

            // ✅ FK FAMILY (nom court obligatoire)
            $table->foreign('restaurant_expense_family_uuid', 'r_ed_family_fk')
                ->references('uuid')
                ->on('restaurant_expense_types_families')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_expense_details');
    }
};
