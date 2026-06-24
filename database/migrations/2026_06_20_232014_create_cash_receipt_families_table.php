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
        Schema::create('restaurant_expense_types_families', function (Blueprint $table) {
            $table->uuid('uuid')->primary();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->uuid('parent_uuid')->nullable();
            $table->uuid('restaurant_expense_uuid')->nullable();

            $table->string('name');
            $table->string('code')->nullable();
            $table->string('indexation')->nullable();
            $table->text('description')->nullable();

            $table->boolean('is_used')->default(true);
            $table->integer('level')->default(1);

            $table->foreign('parent_uuid', 'ref_parent_fk')
                ->references('uuid')
                ->on('restaurant_expense_types_families')
                ->nullOnDelete();


            $table->foreign('restaurant_expense_uuid', 'ref_type_fk')
                ->references('uuid')
                ->on('restaurant_expense_types')
                ->nullOnDelete();


            $table->index(
                ['restaurant_expense_uuid', 'parent_uuid'],
                'ref_type_parent_idx'
            );

            $table->index('level', 'ref_level_idx');
            $table->index('is_active', 'ref_active_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restaurant_expense_types_families');
    }
};
