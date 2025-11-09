<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Example admin user (existing)
        $adminUser = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin User',
                'password' => bcrypt('password123'),
                'role' => 'admin',
            ]
        );

        // Other seeders (order matters)
        $this->call([
            ShippingCarrierSeeder::class,
            CategorySeeder::class,
            ProductSeeder::class,
            UserSeeder::class,
            OrderAndShipmentSeeder::class,
            SystemAlertSeeder::class,
            EmailTemplateSeeder::class,
        ]);
    }
}
