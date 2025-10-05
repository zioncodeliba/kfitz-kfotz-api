<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;
use App\Models\User;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Ensure base roles exist
        $this->call(RoleSeeder::class);

        // Example admin user (existing)
        $adminUser = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin User',
                'password' => bcrypt('password123'),
            ]
        );

        $adminRole = Role::where('name', 'admin')->first();
        if ($adminRole) {
            $adminUser->roles()->syncWithoutDetaching([$adminRole->id]);
        }

        // Other seeders (order matters)
        $this->call([
            ShippingCarrierSeeder::class,
            CategorySeeder::class,
            ProductSeeder::class,
            UserSeeder::class, // <-- add this
        ]);
    }
}
