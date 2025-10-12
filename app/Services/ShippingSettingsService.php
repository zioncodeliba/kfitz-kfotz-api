<?php

namespace App\Services;

use App\Http\Resources\ShippingCarrierResource;
use App\Models\Merchant;
use App\Models\ShippingCarrier;
use Illuminate\Support\Collection;

class ShippingSettingsService
{
    protected array $shippingSizeOptions = ['regular', 'oversized', 'pallet', 'box'];

    protected array $shippingSizeSynonyms = [
        'standard' => 'regular',
        'package' => 'regular',
        'parcel' => 'regular',
        'envelope' => 'regular',
        'flat' => 'regular',
        'any' => 'regular',
        'small' => 'regular',
        'medium' => 'regular',
        'box' => 'box',
        'carton' => 'box',
        'oversized' => 'oversized',
        'bulky' => 'oversized',
        'large' => 'oversized',
        'huge' => 'oversized',
        'pallet' => 'pallet',
        'pallets' => 'pallet',
        'crate' => 'pallet',
    ];

    public function shippingSizeOptions(): array
    {
        return $this->shippingSizeOptions;
    }

    public function normalizeSize(?string $value): string
    {
        return $this->normalizeShippingSize($value);
    }

    /**
     * Build the shipping settings payload with available options for a merchant.
     */
    public function buildPayload(?Merchant $merchant): array
    {
        $carriers = ShippingCarrier::active()->orderBy('name')->get();

        $serviceTypes = $this->uniqueFlatValues($carriers->pluck('service_types'));

        $settings = $merchant?->shipping_settings ?? [];

        $settings = array_merge([
            'default_destination' => 'customer',
            'default_service_type' => $serviceTypes[0] ?? 'courier',
            'default_shipping_size' => $this->shippingSizeOptions()[0] ?? 'regular',
            'default_package_type' => $this->shippingSizeOptions()[0] ?? 'regular',
            'shipping_units' => [],
        ], $settings);

        $settings['default_shipping_size'] = $this->normalizeShippingSize(
            $settings['default_shipping_size'] ?? $settings['default_package_type'] ?? null
        );
        $settings['default_package_type'] = $settings['default_shipping_size'];

        $settings['shipping_units'] = collect($settings['shipping_units'])->map(function ($unit) {
            $size = $this->normalizeShippingSize($unit['shipping_size'] ?? $unit['package_type'] ?? null);

            return array_merge([
                'destination' => null,
                'service_type' => null,
                'carrier_id' => null,
                'carrier_code' => null,
                'carrier_name' => null,
                'shipping_size' => $size,
                'package_type' => $size,
                'quantity' => 1,
                'price' => null,
                'notes' => null,
            ], $unit ?? [], [
                'shipping_size' => $size,
                'package_type' => $size,
            ]);
        })->toArray();

        return [
            'settings' => $settings,
            'options' => [
                'destinations' => ['customer', 'merchant', 'merchant-client'],
                'service_types' => $serviceTypes,
                'shipping_sizes' => $this->shippingSizeOptions(),
                'package_types' => $this->shippingSizeOptions(),
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

            $size = $this->normalizeShippingSize($unit['shipping_size'] ?? $unit['package_type'] ?? null);

            return array_merge([
                'destination' => null,
                'service_type' => null,
                'carrier_id' => $carrier?->id,
                'carrier_code' => $carrier?->code,
                'carrier_name' => $carrier?->name,
                'shipping_size' => $size,
                'package_type' => $size,
                'quantity' => 1,
                'price' => null,
                'notes' => null,
            ], $unit ?? [], [
                'carrier_id' => $carrier?->id,
                'carrier_code' => $carrier?->code,
                'carrier_name' => $carrier?->name,
                'shipping_size' => $size,
                'package_type' => $size,
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

        $defaultSize = $this->normalizeShippingSize(
            $settings['default_shipping_size'] ?? $settings['default_package_type'] ?? null
        );

        $settings['default_shipping_size'] = $defaultSize;
        $settings['default_package_type'] = $defaultSize;
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

    protected function normalizeShippingSize(?string $value): string
    {
        if (empty($value) || !is_string($value)) {
            return $this->shippingSizeOptions()[0];
        }

        $normalized = strtolower(trim($value));

        if (in_array($normalized, $this->shippingSizeOptions(), true)) {
            return $normalized;
        }

        $synonym = $this->shippingSizeSynonyms[$normalized] ?? null;
        if ($synonym && in_array($synonym, $this->shippingSizeOptions(), true)) {
            return $synonym;
        }

        return $this->shippingSizeOptions()[0];
    }
}
