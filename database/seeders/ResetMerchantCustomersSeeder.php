<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ResetMerchantCustomersSeeder extends Seeder
{
    /**
     * Remove all merchant customers (customer store entries).
     */
    public function run(): void
    {
        Schema::disableForeignKeyConstraints();

        if (Schema::hasTable('merchant_customers')) {
            DB::table('merchant_customers')->truncate();
        }

        Schema::enableForeignKeyConstraints();

        $this->command?->info('Merchant customers have been reset.');
    }
}
