<?php

namespace Database\Seeders;

use App\Models\Merchant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ResetMerchantsSeeder extends Seeder
{
    /**
     * Reset merchants and keep a single sample merchant.
     *
     * This clears related merchant tables and recreates one merchant + user for reference.
     */
    public function run(): void
    {
        Schema::disableForeignKeyConstraints();

        $tables = [
            'merchant_payment_orders',
            'merchant_payment_submissions',
            'merchant_payments',
            'merchant_banners',
            'merchant_popups',
            'merchant_sites',
            'merchant_customers',
            'orders',
            'order_items',
            'shipments',
            'merchants',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->truncate();
            }
        }

        // Remove all non-admin users (merchants/agents/etc). Keep admins intact.
        User::where('role', '!=', 'admin')->delete();

        Schema::enableForeignKeyConstraints();

        $this->command?->info('Merchants reset. All merchant data cleared; only admin users remain.');
    }
}
