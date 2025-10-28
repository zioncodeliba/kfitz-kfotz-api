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
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement("
                ALTER TABLE orders
                MODIFY payment_status ENUM('pending','paid','failed','refunded','cancelled','canceled')
                    NOT NULL DEFAULT 'pending'
            ");
        } elseif ($driver === 'pgsql') {
            DB::statement("DO $$
                BEGIN
                    IF NOT EXISTS (
                        SELECT 1 FROM pg_type t
                        JOIN pg_enum e ON t.oid = e.enumtypid
                        WHERE t.typname = 'orders_payment_status_enum'
                          AND e.enumlabel = 'cancelled'
                    ) THEN
                        ALTER TYPE orders_payment_status_enum ADD VALUE 'cancelled';
                    END IF;
                    IF NOT EXISTS (
                        SELECT 1 FROM pg_type t
                        JOIN pg_enum e ON t.oid = e.enumtypid
                        WHERE t.typname = 'orders_payment_status_enum'
                          AND e.enumlabel = 'canceled'
                    ) THEN
                        ALTER TYPE orders_payment_status_enum ADD VALUE 'canceled';
                    END IF;
                END
            $$;");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::table('orders')
                ->whereIn('payment_status', ['cancelled', 'canceled'])
                ->update(['payment_status' => 'pending']);

            DB::statement("
                ALTER TABLE orders
                MODIFY payment_status ENUM('pending','paid','failed','refunded')
                    NOT NULL DEFAULT 'pending'
            ");
        } elseif ($driver === 'pgsql') {
            DB::table('orders')
                ->whereIn('payment_status', ['cancelled', 'canceled'])
                ->update(['payment_status' => 'pending']);
            // PostgreSQL does not support removing enum values without recreating the type.
        }
    }
};
