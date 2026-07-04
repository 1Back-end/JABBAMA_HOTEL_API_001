<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Rattrapage des Partenaires existants
        DB::statement("
            INSERT INTO client_allocations (uuid, source_type, source_uuid, client_name, amount_allocated, amount_allocated_total, created_at, updated_at)
            SELECT UUID(), 'partner', uuid, full_name, amount_allocated, amount_allocated_total, NOW(), NOW()
            FROM restaurant_partners
            WHERE uuid NOT IN (SELECT source_uuid FROM client_allocations WHERE source_type = 'partner')
        ");

        // 2. Rattrapage des Clients Libres existants
        DB::statement("
            INSERT INTO client_allocations (uuid, source_type, source_uuid, client_name, amount_allocated, amount_allocated_total, created_at, updated_at)
            SELECT UUID(), 'free_client', uuid, full_name, amount_allocated, amount_allocated_total, NOW(), NOW()
            FROM free_clients_restaurants
            WHERE uuid NOT IN (SELECT source_uuid FROM client_allocations WHERE source_type = 'free_client')
        ");

        // 3. Rattrapage des Commandes existantes (uniquement celles avec montant alloué > 0)
        DB::statement("
            INSERT INTO client_allocations (uuid, source_type, source_uuid, client_name, amount_allocated, amount_allocated_total, created_at, updated_at)
            SELECT UUID(), 'order', uuid, full_name, amount_allocated, amount_allocated, NOW(), NOW()
            FROM orders_menu_restaurants
            WHERE amount_allocated > 0
              AND uuid NOT IN (SELECT source_uuid FROM client_allocations WHERE source_type = 'order')
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // En cas de rollback, on supprime uniquement les lignes créées par ce rattrapage
        DB::table('client_allocations')
            ->whereIn('source_type', ['partner', 'free_client', 'order'])
            ->delete();
    }
};
