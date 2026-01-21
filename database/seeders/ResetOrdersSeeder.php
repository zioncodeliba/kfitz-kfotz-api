<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
class ResetOrdersSeeder extends Seeder
{
    /**
     * Non-destructive placeholder to avoid removing data during seeding.
     */
    public function run(): void
    {
        $this->command?->warn('ResetOrdersSeeder is disabled to avoid deleting data.');
    }
}
