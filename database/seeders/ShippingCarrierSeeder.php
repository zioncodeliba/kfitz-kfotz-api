<?php

namespace Database\Seeders;

use App\Models\ShippingCarrier;
use Illuminate\Database\Seeder;

class ShippingCarrierSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $carriers = [
            [
                'name' => "צ'יטה",
                'code' => 'chita',
                'description' => 'שירותי משלוחים צ׳יטה',
                'api_url' => 'https://api.chita.com/v1',
                'api_key' => 'test_chita_key',
                'api_secret' => 'test_chita_secret',
                'service_types' => ['regular', 'express', 'pickup'],
                'package_types' => ['envelope', 'box', 'pallet'],
                'base_rate' => 18.00,
                'rate_per_kg' => 3.00,
                'is_active' => true,
                'is_test_mode' => true,
            ],
        ];

        foreach ($carriers as $carrierData) {
            ShippingCarrier::updateOrCreate(
                ['code' => $carrierData['code']], // unique key
                $carrierData
            );
        }
    }
}
