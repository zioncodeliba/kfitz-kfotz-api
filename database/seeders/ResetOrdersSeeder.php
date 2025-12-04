<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ResetOrdersSeeder extends Seeder
{
    /**
     * Clear orders and related transactional tables.
     */
    public function run(): void
    {
        Schema::disableForeignKeyConstraints();

        $tables = [
            'merchant_payment_orders',
            'merchant_payment_submissions',
            'merchant_payments',
            'shipments',
            'order_items',
            'orders',
            'order_summaries', // exists in some environments
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->truncate();
            }
        }

        Schema::enableForeignKeyConstraints();

        $this->command?->info('Orders, items, shipments, and merchant payment links have been reset.');
    }
}
