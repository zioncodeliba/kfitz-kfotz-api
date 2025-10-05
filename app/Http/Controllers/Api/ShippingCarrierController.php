<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ShippingCarrierResource;
use App\Models\ShippingCarrier;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ShippingCarrierController extends Controller
{
    use ApiResponse;

    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        try {
            $carriers = ShippingCarrier::orderBy('name')->get();
            
            return $this->successResponse(
                ShippingCarrierResource::collection($carriers),
                'Shipping carriers retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve shipping carriers: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255|unique:shipping_carriers,name',
                'code' => 'required|string|max:50|unique:shipping_carriers,code',
                'description' => 'nullable|string|max:1000',
                'api_url' => 'nullable|url|max:500',
                'api_key' => 'nullable|string|max:255',
                'api_secret' => 'nullable|string|max:255',
                'username' => 'nullable|string|max:255',
                'password' => 'nullable|string|max:255',
                'api_config' => 'nullable|array',
                'service_types' => 'nullable|array',
                'package_types' => 'nullable|array',
                'base_rate' => 'nullable|numeric|min:0',
                'rate_per_kg' => 'nullable|numeric|min:0',
                'is_active' => 'boolean',
                'is_test_mode' => 'boolean',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $carrier = ShippingCarrier::create($request->all());

            return $this->createdResponse(
                new ShippingCarrierResource($carrier),
                'Shipping carrier created successfully'
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to create shipping carrier: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): JsonResponse
    {
        try {
            $carrier = ShippingCarrier::find($id);

            if (!$carrier) {
                return $this->notFoundResponse('Shipping carrier not found');
            }

            return $this->successResponse(
                new ShippingCarrierResource($carrier),
                'Shipping carrier retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve shipping carrier: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $carrier = ShippingCarrier::find($id);

            if (!$carrier) {
                return $this->notFoundResponse('Shipping carrier not found');
            }

            $validator = Validator::make($request->all(), [
                'name' => [
                    'sometimes',
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('shipping_carriers', 'name')->ignore($id)
                ],
                'code' => [
                    'sometimes',
                    'required',
                    'string',
                    'max:50',
                    Rule::unique('shipping_carriers', 'code')->ignore($id)
                ],
                'description' => 'nullable|string|max:1000',
                'api_url' => 'nullable|url|max:500',
                'api_key' => 'nullable|string|max:255',
                'api_secret' => 'nullable|string|max:255',
                'username' => 'nullable|string|max:255',
                'password' => 'nullable|string|max:255',
                'api_config' => 'nullable|array',
                'service_types' => 'nullable|array',
                'package_types' => 'nullable|array',
                'base_rate' => 'nullable|numeric|min:0',
                'rate_per_kg' => 'nullable|numeric|min:0',
                'is_active' => 'boolean',
                'is_test_mode' => 'boolean',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $carrier->update($request->all());

            return $this->successResponse(
                new ShippingCarrierResource($carrier),
                'Shipping carrier updated successfully'
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to update shipping carrier: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $carrier = ShippingCarrier::find($id);

            if (!$carrier) {
                return $this->notFoundResponse('Shipping carrier not found');
            }

            // Check if carrier has associated shipments
            if ($carrier->shipments()->count() > 0) {
                return $this->errorResponse(
                    'Cannot delete shipping carrier with associated shipments',
                    422
                );
            }

            $carrier->delete();

            return $this->successResponse(
                null,
                'Shipping carrier deleted successfully'
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to delete shipping carrier: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Get active shipping carriers
     */
    public function active(): JsonResponse
    {
        try {
            $carriers = ShippingCarrier::active()->orderBy('name')->get();
            
            return $this->successResponse(
                ShippingCarrierResource::collection($carriers),
                'Active shipping carriers retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve active shipping carriers: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Test API connection for a carrier
     */
    public function testConnection(string $id): JsonResponse
    {
        try {
            $carrier = ShippingCarrier::find($id);

            if (!$carrier) {
                return $this->notFoundResponse('Shipping carrier not found');
            }

            $result = $carrier->testConnection();

            if ($result['success']) {
                return $this->successResponse(
                    $result['data'],
                    $result['message']
                );
            } else {
                return $this->errorResponse(
                    $result['message'],
                    422
                );
            }
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to test connection: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Calculate shipping cost for a carrier
     */
    public function calculateCost(Request $request, string $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'weight' => 'required|numeric|min:0',
                'service_type' => 'nullable|string|in:regular,express,pickup',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $carrier = ShippingCarrier::find($id);

            if (!$carrier) {
                return $this->notFoundResponse('Shipping carrier not found');
            }

            if (!$carrier->isActive()) {
                return $this->errorResponse(
                    'Shipping carrier is not active',
                    422
                );
            }

            $weight = $request->input('weight');
            $serviceType = $request->input('service_type', 'regular');
            
            $cost = $carrier->calculateShippingCost($weight, $serviceType);

            return $this->successResponse([
                'carrier' => $carrier->name,
                'weight' => $weight,
                'service_type' => $serviceType,
                'cost' => $cost,
                'currency' => 'USD', // You can make this configurable
            ], 'Shipping cost calculated successfully');
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to calculate shipping cost: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Get carrier statistics
     */
    public function stats(): JsonResponse
    {
        try {
            $stats = [
                'total_carriers' => ShippingCarrier::count(),
                'active_carriers' => ShippingCarrier::active()->count(),
                'test_mode_carriers' => ShippingCarrier::testMode()->count(),
                'carriers_with_api' => ShippingCarrier::whereNotNull('api_url')->count(),
            ];

            return $this->successResponse(
                $stats,
                'Shipping carrier statistics retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve carrier statistics: ' . $e->getMessage(),
                500
            );
        }
    }
}
