<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ==========================================
        // DROP anciens triggers
        // ==========================================
        DB::unprepared("DROP TRIGGER IF EXISTS after_insert_partner_allocation");
        DB::unprepared("DROP TRIGGER IF EXISTS after_update_partner_allocation");
        DB::unprepared("DROP TRIGGER IF EXISTS after_insert_free_client_allocation");
        DB::unprepared("DROP TRIGGER IF EXISTS after_update_free_client_allocation");
        DB::unprepared("DROP TRIGGER IF EXISTS after_insert_order_allocation");
        DB::unprepared("DROP TRIGGER IF EXISTS after_update_order_allocation");

        // ==========================================
        // PARTNERS
        // ==========================================
        DB::unprepared("
            CREATE TRIGGER after_insert_partner_allocation
            AFTER INSERT ON restaurant_partners
            FOR EACH ROW
            BEGIN
                INSERT INTO client_allocations (
                    uuid,
                    source_type,
                    source_uuid,
                    client_name,
                    amount_allocated,
                    amount_allocated_total,
                    created_at,
                    updated_at
                )
                VALUES (
                    UUID(),
                    'partner',
                    NEW.uuid,
                    NEW.full_name,
                    NEW.amount_allocated,
                    NEW.amount_allocated_total,
                    NOW(),
                    NOW()
                );
            END
        ");

        DB::unprepared("
            CREATE TRIGGER after_update_partner_allocation
            AFTER UPDATE ON restaurant_partners
            FOR EACH ROW
            BEGIN
                UPDATE client_allocations
                SET client_name = NEW.full_name,
                    amount_allocated = NEW.amount_allocated,
                    amount_allocated_total = NEW.amount_allocated_total,
                    updated_at = NOW()
                WHERE source_uuid = NEW.uuid
                AND source_type = 'partner';
            END
        ");

        // ==========================================
        // FREE CLIENTS
        // ==========================================
        DB::unprepared("
            CREATE TRIGGER after_insert_free_client_allocation
            AFTER INSERT ON free_clients_restaurants
            FOR EACH ROW
            BEGIN
                INSERT INTO client_allocations (
                    uuid,
                    source_type,
                    source_uuid,
                    client_name,
                    amount_allocated,
                    amount_allocated_total,
                    created_at,
                    updated_at
                )
                VALUES (
                    UUID(),
                    'free_client',
                    NEW.uuid,
                    NEW.full_name,
                    NEW.amount_allocated,
                    NEW.amount_allocated_total,
                    NOW(),
                    NOW()
                );
            END
        ");

        DB::unprepared("
            CREATE TRIGGER after_update_free_client_allocation
            AFTER UPDATE ON free_clients_restaurants
            FOR EACH ROW
            BEGIN
                UPDATE client_allocations
                SET client_name = NEW.full_name,
                    amount_allocated = NEW.amount_allocated,
                    amount_allocated_total = NEW.amount_allocated_total,
                    updated_at = NOW()
                WHERE source_uuid = NEW.uuid
                AND source_type = 'free_client';
            END
        ");

        // ==========================================
        // ORDERS (CORRIGÉ : code au lieu de order_number)
        // ==========================================
        DB::unprepared("
            CREATE TRIGGER after_insert_order_allocation
            AFTER INSERT ON orders_menu_restaurants
            FOR EACH ROW
            BEGIN
                IF NEW.amount_allocated > 0 THEN
                    INSERT INTO client_allocations (
                        uuid,
                        source_type,
                        source_uuid,
                        order_number,
                        client_name,
                        amount_allocated,
                        amount_allocated_total,
                        created_at,
                        updated_at
                    )
                    VALUES (
                        UUID(),
                        'order',
                        NEW.uuid,
                        NEW.code,
                        NEW.full_name,
                        NEW.amount_allocated,
                        NEW.amount_allocated,
                        NOW(),
                        NOW()
                    );
                END IF;
            END
        ");

        DB::unprepared("
            CREATE TRIGGER after_update_order_allocation
            AFTER UPDATE ON orders_menu_restaurants
            FOR EACH ROW
            BEGIN
                IF NEW.amount_allocated > 0 THEN

                    IF EXISTS (
                        SELECT 1
                        FROM client_allocations
                        WHERE source_uuid = NEW.uuid
                        AND source_type = 'order'
                    ) THEN

                        UPDATE client_allocations
                        SET client_name = NEW.full_name,
                            amount_allocated = NEW.amount_allocated,
                            amount_allocated_total = NEW.amount_allocated,
                            order_number = NEW.code,
                            updated_at = NOW()
                        WHERE source_uuid = NEW.uuid
                        AND source_type = 'order';

                    ELSE

                        INSERT INTO client_allocations (
                            uuid,
                            source_type,
                            source_uuid,
                            order_number,
                            client_name,
                            amount_allocated,
                            amount_allocated_total,
                            created_at,
                            updated_at
                        )
                        VALUES (
                            UUID(),
                            'order',
                            NEW.uuid,
                            NEW.code,
                            NEW.full_name,
                            NEW.amount_allocated,
                            NEW.amount_allocated,
                            NOW(),
                            NOW()
                        );

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
