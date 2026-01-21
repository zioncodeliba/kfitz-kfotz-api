<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class ResetMerchantsSeeder extends Seeder
{
    /**
     * Non-destructive placeholder to avoid removing data during seeding.
     */
    public function run(): void
    {
        $this->command?->warn('ResetMerchantsSeeder is disabled to avoid deleting data.');
    }
}
