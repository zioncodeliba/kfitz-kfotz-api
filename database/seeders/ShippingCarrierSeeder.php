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
                'name' => 'דואר ישראל',
                'code' => 'israelpost',
                'description' => 'Israel Postal Company services',
                'api_url' => 'https://api.israelpost.co.il/v1',
                'api_key' => 'test_israelpost_key',
                'api_secret' => 'test_israelpost_secret',
                'service_types' => ['regular', 'express', 'ems'],
                'package_types' => ['envelope', 'box'],
                'base_rate' => 10.00,
                'rate_per_kg' => 2.00,
                'is_active' => true,
                'is_test_mode' => true,
            ],
            [
                'name' => 'בלדר',
                'code' => 'baldar',
                'description' => 'Federal Express shipping services',
                'api_url' => 'https://api.baldar.com/v1',
                'api_key' => 'test_baldar_key',
                'api_secret' => 'test_baldar_secret',
                'service_types' => ['regular', 'express', 'overnight'],
                'package_types' => ['envelope', 'box', 'pallet'],
                'base_rate' => 15.00,
                'rate_per_kg' => 2.50,
                'is_active' => true,
                'is_test_mode' => true,
            ],
            [
                'name' => 'דוד הובלות',
                'code' => 'david',
                'description' => 'United Parcel Service shipping services',
                'api_url' => 'https://api.david.com/v1',
                'api_key' => 'test_david_key',
                'api_secret' => 'test_david_secret',
                'service_types' => ['regular', 'express', 'ground'],
                'package_types' => ['envelope', 'box', 'pallet'],
                'base_rate' => 12.00,
                'rate_per_kg' => 2.00,
                'is_active' => true,
                'is_test_mode' => true,
            ],
            [
                'name' => "צ'יטה",
                'code' => 'chita',
                'description' => 'DHL Express shipping services',
                'api_url' => 'https://api.chita.com/v1',
                'api_key' => 'test_chita_key',
                'api_secret' => 'test_chita_secret',
                'service_types' => ['regular', 'express', 'worldwide'],
                'package_types' => ['envelope', 'box', 'pallet'],
                'base_rate' => 18.00,
                'rate_per_kg' => 3.00,
                'is_active' => true,
                'is_test_mode' => true,
            ],
            [
                'name' => 'USPS',
                'code' => 'usps',
                'description' => 'United States Postal Service',
                'api_url' => 'https://api.usps.com/v1',
                'api_key' => 'test_usps_key',
                'api_secret' => 'test_usps_secret',
                'service_types' => ['regular', 'priority', 'express'],
                'package_types' => ['envelope', 'box', 'flat'],
                'base_rate' => 8.00,
                'rate_per_kg' => 1.50,
                'is_active' => true,
                'is_test_mode' => true,
            ],
            [
                'name' => 'FedEx',
                'code' => 'fedex',
                'description' => 'Federal Express shipping services',
                'api_url' => 'https://api.fedex.com/v1',
                'api_key' => 'test_fedex_key',
                'api_secret' => 'test_fedex_secret',
                'service_types' => ['regular', 'express', 'overnight'],
                'package_types' => ['envelope', 'box', 'pallet'],
                'base_rate' => 15.00,
                'rate_per_kg' => 2.50,
                'is_active' => true,
                'is_test_mode' => true,
            ],
            [
                'name' => 'UPS',
                'code' => 'ups',
                'description' => 'United Parcel Service shipping services',
                'api_url' => 'https://api.ups.com/v1',
                'api_key' => 'test_ups_key',
                'api_secret' => 'test_ups_secret',
                'service_types' => ['regular', 'express', 'ground'],
                'package_types' => ['envelope', 'box', 'pallet'],
                'base_rate' => 12.00,
                'rate_per_kg' => 2.00,
                'is_active' => true,
                'is_test_mode' => true,
            ],
            [
                'name' => 'DHL',
                'code' => 'dhl',
                'description' => 'DHL Express shipping services',
                'api_url' => 'https://api.dhl.com/v1',
                'api_key' => 'test_dhl_key',
                'api_secret' => 'test_dhl_secret',
                'service_types' => ['regular', 'express', 'worldwide'],
                'package_types' => ['envelope', 'box', 'pallet'],
                'base_rate' => 18.00,
                'rate_per_kg' => 3.00,
                'is_active' => true,
                'is_test_mode' => true,
            ],
            [
                'name' => 'USPS',
                'code' => 'usps',
                'description' => 'United States Postal Service',
                'api_url' => 'https://api.usps.com/v1',
                'api_key' => 'test_usps_key',
                'api_secret' => 'test_usps_secret',
                'service_types' => ['regular', 'priority', 'express'],
                'package_types' => ['envelope', 'box', 'flat'],
                'base_rate' => 8.00,
                'rate_per_kg' => 1.50,
                'is_active' => true,
                'is_test_mode' => true,
            ],
            [
                'name' => 'Local Pickup',
                'code' => 'pickup',
                'description' => 'Local pickup service',
                'api_url' => null,
                'api_key' => null,
                'api_secret' => null,
                'service_types' => ['pickup'],
                'package_types' => ['any'],
                'base_rate' => 0.00,
                'rate_per_kg' => 0.00,
                'is_active' => true,
                'is_test_mode' => false,
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
