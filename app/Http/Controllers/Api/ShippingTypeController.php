<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShippingType;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class ShippingTypeController extends Controller
{
    use ApiResponse;

    public function index()
    {
        $types = ShippingType::orderBy('name')->get();

        return $this->successResponse($types);
    }

    public function store(Request $request)
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'name' => 'required|string|max:100|unique:shipping_types,name',
            'price' => 'required|numeric|min:0',
            'is_default' => 'sometimes|boolean',
        ]);

        $payload = [
            'name' => $data['name'],
            'price' => round((float) $data['price'], 2),
            'is_default' => (bool) ($data['is_default'] ?? false),
        ];

        if ($payload['is_default']) {
            $this->unsetOtherDefaults();
        }

        $type = ShippingType::create($payload);

        return $this->createdResponse($type, 'Shipping type created successfully');
    }

    public function update(Request $request, ShippingType $shippingType)
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:100|unique:shipping_types,name,' . $shippingType->id,
            'price' => 'sometimes|required|numeric|min:0',
            'is_default' => 'sometimes|boolean',
        ]);

        $isDefault = array_key_exists('is_default', $data)
            ? (bool) $data['is_default']
            : $shippingType->is_default;

        $shippingType->fill([
            'name' => $data['name'] ?? $shippingType->name,
            'price' => array_key_exists('price', $data)
                ? round((float) $data['price'], 2)
                : $shippingType->price,
            'is_default' => $isDefault,
        ])->save();

        if ($isDefault) {
            $this->unsetOtherDefaults($shippingType->id);
        }

        return $this->successResponse($shippingType, 'Shipping type updated successfully');
    }

    public function destroy(Request $request, ShippingType $shippingType)
    {
        $this->authorizeAdmin($request);

        $shippingType->delete();

        return $this->successResponse(null, 'Shipping type deleted successfully');
    }

    protected function unsetOtherDefaults(?int $exceptId = null): void
    {
        ShippingType::query()
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->update(['is_default' => false]);
    }

    protected function authorizeAdmin(Request $request): void
    {
        $user = $request->user();
        if (!$user || !$user->hasRole('admin')) {
            abort(403, 'Unauthorized');
        }
    }
}
