<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_regulations', function (Blueprint $table) {

            // type encaissement / depense
            $table->string('type')
                ->default('encaissement')
                ->after('amount');

            // rendre nullable si existe déjà
            if (Schema::hasColumn('payment_regulations', 'payment_uuid')) {
                $table->uuid('payment_uuid')->nullable()->change();
            } else {
                $table->uuid('payment_uuid')->nullable()->after('restaurant_expense_type_uuid');
            }

        });
    }

    public function down(): void
    {
        Schema::table('payment_regulations', function (Blueprint $table) {
            $table->dropColumn('type');

            // optional rollback safe
            if (Schema::hasColumn('payment_regulations', 'payment_uuid')) {
                $table->uuid('payment_uuid')->nullable(false)->change();
            }
        });
    }
};
