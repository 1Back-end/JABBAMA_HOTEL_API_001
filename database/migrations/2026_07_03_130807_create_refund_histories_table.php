<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_histories', function (Blueprint $table) {
            $table->uuid('uuid')->primary();

            $table->uuid('client_allocation_uuid');

            $table->string('source_type');
            $table->uuid('source_uuid');

            $table->decimal('amount', 15, 2);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreign('client_allocation_uuid')
                ->references('uuid')
                ->on('client_allocations')
                ->cascadeOnDelete();

            $table->text('note')->nullable();

            $table->timestamps();
            $table->index('client_allocation_uuid');
            $table->index('source_uuid');
            $table->index('source_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_histories');
    }
};
