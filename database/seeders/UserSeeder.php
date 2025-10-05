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
        $roleIdByName = Role::pluck('id', 'name');

        // Demo users
        $users = [
            [
                'name'  => 'Admin User',
                'email' => 'admin@example.com',
                'password' => '12345678',
                'phone' => '+972500000001',
                'roles' => ['admin'],
            ],
            [
                'name'  => 'Primary Seller',
                'email' => 'seller@example.com',
                'password' => '12345678',
                'phone' => '+972500000002',
                'roles' => ['merchant'],
            ],
            [
                'name'  => 'Catalog Viewer',
                'email' => 'viewer@example.com',
                'password' => '12345678',
                'phone' => '+972500000003',
                'roles' => ['viewer'],
            ],
            [
                'name'  => 'Regular User One',
                'email' => 'user1@example.com',
                'password' => '12345678',
                'phone' => '+972500000004',
                'roles' => ['user'],
            ],
            [
                'name'  => 'Ops Manager',
                'email' => 'ops@example.com',
                'password' => '12345678',
                'phone' => '+972500000005',
                'roles' => ['merchant','viewer'], // multi-role example
            ],
        ];

        foreach ($users as $u) {
            // Create or fetch user
            $user = User::firstOrCreate(
                ['email' => $u['email']],
                [
                    'name'     => $u['name'],
                    'password' => Hash::make($u['password']),
                    'phone'    => $u['phone'] ?? null,
                ]
            );

            if (($u['phone'] ?? null) && $user->phone !== $u['phone']) {
                $user->update(['phone' => $u['phone']]);
            }

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
