<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShippingType;
use App\Models\SystemSetting;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class SystemSettingController extends Controller
{
    use ApiResponse;

    public function getShippingPricing(Request $request)
    {
        $settings = $this->resolveShippingPricing();

        return $this->successResponse($settings);
    }

    public function updateShippingPricing(Request $request)
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'store' => 'nullable|numeric|min:0',
            'courier' => 'nullable|numeric|min:0',
            'vat_rate' => 'required|numeric|min:0|max:1',
        ]);

        $existing = SystemSetting::where('key', 'shipping_pricing')->first();
        $existingValue = is_array($existing?->value) ? $existing->value : [];

        $payload = [
            'store' => array_key_exists('store', $data)
                ? $this->normalizePrice($data['store'])
                : $this->normalizePrice($existingValue['store'] ?? null),
            'courier' => array_key_exists('courier', $data)
                ? $this->normalizePrice($data['courier'])
                : $this->normalizePrice($existingValue['courier'] ?? null),
            'vat_rate' => $this->normalizeVatRate($data['vat_rate'] ?? ($existingValue['vat_rate'] ?? null)),
        ];

        $setting = SystemSetting::updateOrCreate(
            ['key' => 'shipping_pricing'],
            ['value' => $payload]
        );

        return $this->successResponse($setting->value, 'Shipping pricing updated successfully');
    }

    protected function resolveShippingPricing(): array
    {
        $setting = SystemSetting::where('key', 'shipping_pricing')->first();
        $value = is_array($setting?->value) ? $setting->value : [];

        return [
            'store' => $this->normalizePrice($value['store'] ?? null),
            'courier' => $this->normalizePrice($value['courier'] ?? null),
            'vat_rate' => $this->normalizeVatRate($value['vat_rate'] ?? null),
            'shipping_types' => $this->resolveShippingTypes(),
        ];
    }

    protected function resolveShippingTypes(): array
    {
        return ShippingType::orderBy('name')
            ->get()
            ->map(function (ShippingType $type) {
                return [
                    'id' => $type->id,
                    'name' => $type->name,
                    'price' => (float) $type->price,
                    'is_default' => (bool) $type->is_default,
                    'created_at' => $type->created_at,
                    'updated_at' => $type->updated_at,
                ];
            })
            ->toArray();
    }

    protected function normalizePrice($value): float
    {
        if (is_numeric($value)) {
            return max(0, (float) $value);
        }

        return 0.0;
    }

    protected function normalizeVatRate($value): float
    {
        if (is_numeric($value)) {
            $rate = (float) $value;
            if ($rate < 0) {
                return 0.0;
            }
            if ($rate > 1) {
                return 1.0;
            }
            return $rate;
        }

        // Default VAT to 17%
        return 0.17;
    }

    protected function authorizeAdmin(Request $request): void
    {
        $user = $request->user();
        if (!$user || !$user->hasRole('admin')) {
            abort(403, 'Unauthorized');
        }
    }
}
