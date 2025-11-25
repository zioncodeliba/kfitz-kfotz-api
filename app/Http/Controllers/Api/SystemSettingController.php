<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
            'store' => 'required|numeric|min:0',
            'courier' => 'required|numeric|min:0',
        ]);

        $setting = SystemSetting::updateOrCreate(
            ['key' => 'shipping_pricing'],
            ['value' => $data]
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
        ];
    }

    protected function normalizePrice($value): float
    {
        if (is_numeric($value)) {
            return max(0, (float) $value);
        }

        return 0.0;
    }

    protected function authorizeAdmin(Request $request): void
    {
        $user = $request->user();
        if (!$user || !$user->hasRole('admin')) {
            abort(403, 'Unauthorized');
        }
    }
}
