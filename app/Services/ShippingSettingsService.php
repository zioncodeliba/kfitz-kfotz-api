<?php

namespace App\Services;

use App\Http\Resources\ShippingCarrierResource;
use App\Models\Merchant;
use App\Models\ShippingCarrier;
use Illuminate\Support\Collection;

class ShippingSettingsService
{
    /**
     * Build the shipping settings payload with available options for a merchant.
     */
    public function buildPayload(?Merchant $merchant): array
    {
        $carriers = ShippingCarrier::active()->orderBy('name')->get();

        $serviceTypes = $this->uniqueFlatValues($carriers->pluck('service_types'));
        $packageTypes = $this->uniqueFlatValues($carriers->pluck('package_types'));

        $settings = $merchant?->shipping_settings ?? [];

        $settings = array_merge([
            'default_destination' => 'local',
            'default_service_type' => $serviceTypes[0] ?? 'regular',
            'default_package_type' => $packageTypes[0] ?? null,
            'shipping_units' => [],
        ], $settings);

        $settings['shipping_units'] = collect($settings['shipping_units'])->map(function ($unit) {
            return array_merge([
                'destination' => null,
                'service_type' => null,
                'carrier_id' => null,
                'carrier_code' => null,
                'carrier_name' => null,
                'package_type' => null,
                'quantity' => 1,
                'price' => null,
                'notes' => null,
            ], $unit ?? []);
        })->toArray();

        return [
            'settings' => $settings,
            'options' => [
                'destinations' => ['local', 'national', 'international'],
                'service_types' => $serviceTypes,
                'package_types' => $packageTypes,
                'carriers' => ShippingCarrierResource::collection($carriers),
                'quantity_range' => ['min' => 1, 'max' => 999],
            ],
        ];
    }

    /**
     * Normalize and enrich the shipping units before persisting.
     *
     * @param array $units
     * @return array
     */
    public function normalizeShippingUnits(array $units): array
    {
        $input = collect($units);

        if ($input->isEmpty()) {
            return [];
        }

        $carrierIds = $input->pluck('carrier_id')->filter()->unique();
        $carrierCodes = $input->pluck('carrier_code')->filter()->unique();

        $carriers = ShippingCarrier::query()
            ->when($carrierIds->isNotEmpty(), fn ($query) => $query->whereIn('id', $carrierIds))
            ->when(
                $carrierCodes->isNotEmpty(),
                fn ($query) => $query->orWhereIn('code', $carrierCodes)
            )
            ->get();

        $carriersById = $carriers->keyBy('id');
        $carriersByCode = $carriers->keyBy('code');

        return $input->map(function ($unit) use ($carriersById, $carriersByCode) {
            $carrier = null;

            if (!empty($unit['carrier_id'])) {
                $carrier = $carriersById->get($unit['carrier_id']);
            } elseif (!empty($unit['carrier_code'])) {
                $carrier = $carriersByCode->get($unit['carrier_code']);
            }

            return array_merge([
                'destination' => null,
                'service_type' => null,
                'carrier_id' => $carrier?->id,
                'carrier_code' => $carrier?->code,
                'carrier_name' => $carrier?->name,
                'package_type' => null,
                'quantity' => 1,
                'price' => null,
                'notes' => null,
            ], $unit ?? [], [
                'carrier_id' => $carrier?->id,
                'carrier_code' => $carrier?->code,
                'carrier_name' => $carrier?->name,
            ]);
        })->toArray();
    }

    /**
     * Prepare the settings array for storage.
     */
    public function prepareForStorage(array $data, array $except = []): array
    {
        $excluded = array_merge(['merchant_id', 'order_id', 'shipping_units'], $except);

        $settings = collect($data)
            ->except($excluded)
            ->all();

        $settings['shipping_units'] = $this->normalizeShippingUnits($data['shipping_units'] ?? []);

        return $settings;
    }

    /**
     * Helper to flatten and unique values from nested arrays.
     */
    protected function uniqueFlatValues(Collection $collection): array
    {
        return $collection
            ->flatten()
            ->filter(fn ($value) => !is_null($value) && $value !== '')
            ->unique()
            ->values()
            ->all();
    }
}
