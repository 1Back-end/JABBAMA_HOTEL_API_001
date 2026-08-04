<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('decisional_notifications', 'purchase_orders_decisional_notifications');
    }

    public function down(): void
    {
        Schema::rename('purchase_orders_decisional_notifications', 'decisional_notifications');
    }
};
