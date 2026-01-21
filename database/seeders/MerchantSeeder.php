<?php

namespace Database\Seeders;

use App\Models\Merchant;
use App\Models\MerchantSite;
use App\Models\User;
use Illuminate\Database\Seeder;

class MerchantSeeder extends Seeder
{
    public function run(): void
    {
        $merchantUsers = User::where('role', 'merchant')->get();

        if ($merchantUsers->isEmpty()) {
            $this->command?->warn('No merchant users found. Skipping MerchantSeeder.');
            return;
        }

        foreach ($merchantUsers as $user) {
            $name = trim((string) $user->name);
            $businessBase = $name !== '' ? $name : "Merchant {$user->id}";
            $businessName = "{$businessBase} Store";
            $businessId = 'SEED-BIZ-' . str_pad((string) $user->id, 5, '0', STR_PAD_LEFT);
            $phone = $user->phone ?: '+972-50-000-0000';

            $merchant = Merchant::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'business_name' => $businessName,
                    'contact_name' => $user->name,
                    'business_id' => $businessId,
                    'phone' => $phone,
                    'email_for_orders' => $user->email,
                    'website' => null,
                    'address' => [
                        'name' => $businessName,
                        'address' => '1 Seed Street',
                        'city' => 'Tel Aviv',
                        'zip' => '6100000',
                        'phone' => $phone,
                    ],
                    'status' => 'active',
                    'verification_status' => 'verified',
                ]
            );

            if (MerchantSite::where('user_id', $user->id)->exists()) {
                continue;
            }

            $siteUrl = sprintf('https://store-%d.example.com', $user->id);

            MerchantSite::firstOrCreate(
                ['user_id' => $user->id, 'site_url' => $siteUrl],
                [
                    'name' => $merchant->business_name,
                    'contact_name' => $merchant->contact_name,
                    'contact_phone' => $merchant->phone,
                    'platform' => 'custom',
                    'plugin_installed_at' => now(),
                    'metadata' => ['source' => 'seed'],
                    'status' => 'active',
                    'balance' => 0,
                    'credit_limit' => 0,
                ]
            );
        }
    }
}
