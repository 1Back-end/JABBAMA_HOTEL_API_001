<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Nettoyage de sécurité
        DB::unprepared("DROP TRIGGER IF EXISTS after_insert_partner_allocation");
        DB::unprepared("DROP TRIGGER IF EXISTS after_update_partner_allocation");
        DB::unprepared("DROP TRIGGER IF EXISTS after_insert_free_client_allocation");
        DB::unprepared("DROP TRIGGER IF EXISTS after_update_free_client_allocation");
        DB::unprepared("DROP TRIGGER IF EXISTS after_insert_order_allocation");
        DB::unprepared("DROP TRIGGER IF EXISTS after_update_order_allocation");

        // ==========================================
        // 1. TRIGGERS POUR LES PARTENAIRES (restaurant_partners)
        // ==========================================
        DB::unprepared("
            CREATE TRIGGER after_insert_partner_allocation AFTER INSERT ON restaurant_partners FOR EACH ROW
            BEGIN
                INSERT INTO client_allocations (uuid, source_type, source_uuid, client_name, amount_allocated, amount_allocated_total, created_at, updated_at)
                VALUES (UUID(), 'partner', NEW.uuid, NEW.full_name, NEW.amount_allocated, NEW.amount_allocated_total, NOW(), NOW());
            END
        ");

        DB::unprepared("
            CREATE TRIGGER after_update_partner_allocation AFTER UPDATE ON restaurant_partners FOR EACH ROW
            BEGIN
                UPDATE client_allocations
                SET client_name = NEW.full_name,
                    amount_allocated = NEW.amount_allocated,
                    amount_allocated_total = NEW.amount_allocated_total,
                    updated_at = NOW()
                WHERE source_uuid = NEW.uuid AND source_type = 'partner';
            END
        ");

        // ==========================================
        // 2. TRIGGERS POUR LES CLIENTS LIBRES (free_clients_restaurants)
        // ==========================================
        DB::unprepared("
            CREATE TRIGGER after_insert_free_client_allocation AFTER INSERT ON free_clients_restaurants FOR EACH ROW
            BEGIN
                INSERT INTO client_allocations (uuid, source_type, source_uuid, client_name, amount_allocated, amount_allocated_total, created_at, updated_at)
                VALUES (UUID(), 'free_client', NEW.uuid, NEW.full_name, NEW.amount_allocated, NEW.amount_allocated_total, NOW(), NOW());
            END
        ");

        DB::unprepared("
            CREATE TRIGGER after_update_free_client_allocation AFTER UPDATE ON free_clients_restaurants FOR EACH ROW
            BEGIN
                UPDATE client_allocations
                SET client_name = NEW.full_name,
                    amount_allocated = NEW.amount_allocated,
                    amount_allocated_total = NEW.amount_allocated_total,
                    updated_at = NOW()
                WHERE source_uuid = NEW.uuid AND source_type = 'free_client';
            END
        ");

        // ==========================================
        // 3. TRIGGERS POUR LES COMMANDES (orders_menu_restaurants)
        // ==========================================
        DB::unprepared("
            CREATE TRIGGER after_insert_order_allocation AFTER INSERT ON orders_menu_restaurants FOR EACH ROW
            BEGIN
                IF NEW.amount_allocated > 0 THEN
                    INSERT INTO client_allocations (uuid, source_type, source_uuid, client_name, amount_allocated, amount_allocated_total, created_at, updated_at)
                    VALUES (UUID(), 'order', NEW.uuid, NEW.full_name, NEW.amount_allocated, NEW.amount_allocated, NOW(), NOW());
                END IF;
            END
        ");

        DB::unprepared("
            CREATE TRIGGER after_update_order_allocation AFTER UPDATE ON orders_menu_restaurants FOR EACH ROW
            BEGIN
                IF NEW.amount_allocated > 0 THEN
                    -- On vérifie manuellement si la ligne existe déjà pour cette commande
                    IF EXISTS (SELECT 1 FROM client_allocations WHERE source_uuid = NEW.uuid AND source_type = 'order') THEN
                        UPDATE client_allocations
                        SET client_name = NEW.full_name,
                            amount_allocated = NEW.amount_allocated,
                            amount_allocated_total = NEW.amount_allocated,
                            updated_at = NOW()
                        WHERE source_uuid = NEW.uuid AND source_type = 'order';
                    ELSE
                        -- Si elle n'existe pas encore, on la crée proprement
                        INSERT INTO client_allocations (uuid, source_type, source_uuid, client_name, amount_allocated, amount_allocated_total, created_at, updated_at)
                        VALUES (UUID(), 'order', NEW.uuid, NEW.full_name, NEW.amount_allocated, NEW.amount_allocated, NOW(), NOW());
                    END IF;
                END IF;
            END
        ");
    }

    public function down(): void
    {
        DB::unprepared("DROP TRIGGER IF EXISTS after_insert_partner_allocation");
        DB::unprepared("DROP TRIGGER IF EXISTS after_update_partner_allocation");
        DB::unprepared("DROP TRIGGER IF EXISTS after_insert_free_client_allocation");
        DB::unprepared("DROP TRIGGER IF EXISTS after_update_free_client_allocation");
        DB::unprepared("DROP TRIGGER IF EXISTS after_insert_order_allocation");
        DB::unprepared("DROP TRIGGER IF EXISTS after_update_order_allocation");
    }
};
