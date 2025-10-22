<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'name'  => 'Admin User',
                'email' => 'admin@example.com',
                'password' => '12345678',
                'phone' => '+972500000001',
                'role' => 'admin',
            ],
            [
                'name'  => 'אור',
                'email' => 'or@example.com',
                'password' => '12345678',
                'phone' => '+972500000012',
                'role' => 'merchant',
            ],
            [
                'name'  => 'עמית',
                'email' => 'amit@example.com',
                'password' => '12345678',
                'phone' => '+972500000022',
                'role' => 'merchant',
            ],
            [
                'name'  => 'ירון',
                'email' => 'yaron@example.com',
                'password' => '12345678',
                'phone' => '+972500000032',
                'role' => 'merchant',
            ],
            [
                'name'  => 'Support Agent',
                'email' => 'agent@example.com',
                'password' => '12345678',
                'phone' => '+972500000003',
                'role' => 'agent',
            ],
            [
                'name'  => 'Merchant Without Website',
                'email' => 'merchant2@example.com',
                'password' => '12345678',
                'phone' => '+972500000004',
                'role' => 'merchant',
            ],
        ];

        foreach ($users as $u) {
            $user = User::firstOrCreate(
                ['email' => $u['email']],
                [
                    'name'     => $u['name'],
                    'password' => bcrypt($u['password']),
                    'phone'    => $u['phone'] ?? null,
                    'role'     => $u['role'],
                ]
            );

            if (($u['phone'] ?? null) && $user->phone !== $u['phone']) {
                $user->update(['phone' => $u['phone']]);
            }

            if ($user->role !== $u['role']) {
                $user->update(['role' => $u['role']]);
            }
        }
    }
}
