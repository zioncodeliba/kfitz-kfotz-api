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
                MODIFY status ENUM('pending','confirmed','processing','lead','shipped','delivered','cancelled','refunded')
                    NOT NULL DEFAULT 'pending'
            ");
        } elseif ($driver === 'pgsql') {
            DB::statement("DO $$
                BEGIN
                    IF NOT EXISTS (
                        SELECT 1 FROM pg_type t
                        JOIN pg_enum e ON t.oid = e.enumtypid
                        WHERE t.typname = 'orders_status_enum'
                          AND e.enumlabel = 'lead'
                    ) THEN
                        ALTER TYPE orders_status_enum ADD VALUE 'lead';
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
                ->where('status', 'lead')
                ->update(['status' => 'pending']);

            DB::statement("
                ALTER TABLE orders
                MODIFY status ENUM('pending','confirmed','processing','shipped','delivered','cancelled','refunded')
                    NOT NULL DEFAULT 'pending'
            ");
        } elseif ($driver === 'pgsql') {
            DB::table('orders')
                ->where('status', 'lead')
                ->update(['status' => 'pending']);
            // PostgreSQL does not support removing enum values without recreating the type.
        }
    }
};
