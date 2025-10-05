<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\Role;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Ensure base roles exist
        $roles = ['admin', 'user', 'seller', 'viewer'];
        foreach ($roles as $r) {
            Role::firstOrCreate(['name' => $r]);
        }

        // Map role name -> id
        $roleIdByName = Role::pluck('id', 'name');

        // Demo users
        $users = [
            [
                'name'  => 'Admin User',
                'email' => 'admin@example.com',
                'password' => '12345678',
                'roles' => ['admin'],
            ],
            [
                'name'  => 'Primary Seller',
                'email' => 'seller@example.com',
                'password' => '12345678',
                'roles' => ['seller'],
            ],
            [
                'name'  => 'Catalog Viewer',
                'email' => 'viewer@example.com',
                'password' => '12345678',
                'roles' => ['viewer'],
            ],
            [
                'name'  => 'Regular User One',
                'email' => 'user1@example.com',
                'password' => '12345678',
                'roles' => ['user'],
            ],
            [
                'name'  => 'Ops Manager',
                'email' => 'ops@example.com',
                'password' => '12345678',
                'roles' => ['seller','viewer'], // multi-role example
            ],
        ];

        foreach ($users as $u) {
            // Create or fetch user
            $user = User::firstOrCreate(
                ['email' => $u['email']],
                [
                    'name'     => $u['name'],
                    'password' => Hash::make($u['password']),
                ]
            );

            // Resolve role IDs
            $roleIds = collect($u['roles'])
                ->map(fn ($name) => $roleIdByName[$name] ?? null)
                ->filter()
                ->values()
                ->all();

            // Sync roles exactly to the defined set (no 'role' column usage)
            $user->roles()->sync($roleIds);
        }
    }
}
