<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\Shipment;
use App\Models\Order;
use Illuminate\Http\Request;

class ShipmentController extends Controller
{
    use ApiResponse;

    protected array $agentMerchantCache = [];

    protected function getAgentManagedMerchantUserIds($user): array
    {
        if (!isset($this->agentMerchantCache[$user->id])) {
            $this->agentMerchantCache[$user->id] = $user->agentMerchants()
                ->pluck('user_id')
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        return $this->agentMerchantCache[$user->id];
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Shipment::with(['order.user', 'order.merchant']);

        // Apply role-based filtering
        if ($user->hasRole('admin')) {
            // Admin can see all shipments
        } elseif ($user->hasRole('merchant')) {
            // Merchant can see shipments for their orders
            $query->whereHas('order', function($q) use ($user) {
                $q->where('merchant_id', $user->id);
            });
        } elseif ($user->hasRole('agent')) {
            // Agent can see shipments for orders assigned to them or their merchants
            $managedMerchantIds = $this->getAgentManagedMerchantUserIds($user);
            $query->whereHas('order', function($q) use ($user, $managedMerchantIds) {
                $q->where(function ($inner) use ($user, $managedMerchantIds) {
                    $inner->where('agent_id', $user->id);

                    if (!empty($managedMerchantIds)) {
                        $inner->orWhereIn('merchant_id', $managedMerchantIds);
                    }
                });
            });
        } else {
            // Customer can see their own shipments
            $query->whereHas('order', function($q) use ($user) {
                $q->where('user_id', $user->id);
            });
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by carrier
        if ($request->has('carrier')) {
            $query->where('carrier', $request->carrier);
        }

        // Search by tracking number
        if ($request->has('search')) {
            $query->where('tracking_number', 'like', '%' . $request->search . '%');
        }

        $shipments = $query->orderBy('created_at', 'desc')->paginate($request->get('per_page', 15));

        return $this->successResponse($shipments);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        // Only admin and merchant can create shipments
        if (!$user->hasRole('admin') && !$user->hasRole('merchant')) {
            return $this->errorResponse('Unauthorized', 403);
        }

        $request->validate([
            'order_id' => 'required|exists:orders,id',
            'carrier' => 'required|string',
            'service_type' => 'required|in:regular,express,pickup',
            'package_type' => 'required|in:regular,oversized,pallet,box',
            'weight' => 'nullable|numeric|min:0',
            'length' => 'nullable|numeric|min:0',
            'width' => 'nullable|numeric|min:0',
            'height' => 'nullable|numeric|min:0',
            'origin_address' => 'required|array',
            'destination_address' => 'required|array',
            'shipping_cost' => 'required|numeric|min:0',
            'cod_payment' => 'boolean',
            'cod_amount' => 'nullable|numeric|min:0',
            'cod_method' => 'nullable|string|max:191',
            'notes' => 'nullable|string',
        ]);

        // Check if shipment already exists for this order
        if (Shipment::where('order_id', $request->order_id)->exists()) {
            return $this->errorResponse('Shipment already exists for this order', 400);
        }

        // Check permissions for merchant
        if ($user->hasRole('merchant')) {
            $order = Order::findOrFail($request->order_id);
            if ($order->merchant_id !== $user->id) {
                return $this->errorResponse('Unauthorized', 403);
            }
        }

        $shipment = Shipment::create($request->all());

        // Add initial tracking event
        $shipment->addTrackingEvent('Shipment created', 'Shipment has been created and is pending pickup');

        return $this->createdResponse($shipment->load(['order.user']), 'Shipment created successfully');
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, string $id)
    {
        $user = $request->user();
        $shipment = Shipment::with(['order.user', 'order.merchant'])->findOrFail($id);

        // Check permissions
        if (!$user->hasRole('admin')) {
            if ($user->hasRole('merchant') && $shipment->order->merchant_id !== $user->id) {
                return $this->errorResponse('Unauthorized', 403);
            } elseif ($user->hasRole('agent')) {
                $managedMerchantIds = $this->getAgentManagedMerchantUserIds($user);
                if (
                    $shipment->order->agent_id !== $user->id &&
                    (empty($managedMerchantIds) || !in_array($shipment->order->merchant_id, $managedMerchantIds, true))
                ) {
                    return $this->errorResponse('Unauthorized', 403);
                }
            } elseif ($shipment->order->user_id !== $user->id) {
                return $this->errorResponse('Unauthorized', 403);
            }
        }

        return $this->successResponse($shipment);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $shipment = Shipment::findOrFail($id);
        $user = $request->user();

        // Only admin can update shipments
        if (!$user->hasRole('admin')) {
            return $this->errorResponse('Unauthorized', 403);
        }

        $validated = $request->validate([
            'status' => 'sometimes|in:pending,picked_up,in_transit,out_for_delivery,delivered,failed,returned',
            'carrier' => 'sometimes|string',
            'service_type' => 'sometimes|in:regular,express,pickup',
            'package_type' => 'sometimes|in:regular,oversized,pallet,box',
            'weight' => 'nullable|numeric|min:0',
            'length' => 'nullable|numeric|min:0',
            'width' => 'nullable|numeric|min:0',
            'height' => 'nullable|numeric|min:0',
            'origin_address' => 'sometimes|array',
            'destination_address' => 'sometimes|array',
            'shipping_cost' => 'sometimes|numeric|min:0',
            'cod_payment' => 'boolean',
            'cod_amount' => 'nullable|numeric|min:0',
            'cod_method' => 'nullable|string|max:191',
            'cod_collected' => 'sometimes|boolean',
            'cod_collected_at' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $oldStatus = $shipment->status;
        $shipment->fill($validated);

        if (array_key_exists('cod_collected', $validated)) {
            $collected = (bool) $validated['cod_collected'];
            $shipment->cod_collected = $collected;
            $shipment->cod_collected_at = $collected
                ? ($shipment->cod_collected_at ?? now())
                : null;
        }

        if (array_key_exists('cod_collected_at', $validated) && !$validated['cod_collected_at']) {
            $shipment->cod_collected_at = null;
        }

        $shipment->save();

        // Update status with timestamp and tracking event
        if ($request->has('status') && $request->status !== $oldStatus) {
            $shipment->updateStatus($request->status);
            
            // Add tracking event
            $statusDescriptions = [
                'picked_up' => 'Package has been picked up by carrier',
                'in_transit' => 'Package is in transit',
                'out_for_delivery' => 'Package is out for delivery',
                'delivered' => 'Package has been delivered',
                'failed' => 'Delivery attempt failed',
                'returned' => 'Package has been returned',
            ];
            
            $description = $statusDescriptions[$request->status] ?? 'Status updated';
            $shipment->addTrackingEvent('Status updated', $description);
        }

        return $this->successResponse($shipment->load(['order.user']), 'Shipment updated successfully');
    }

    /**
     * Bulk update COD collection status for shipments.
     */
    public function updateCodCollectionStatus(Request $request)
    {
        $user = $request->user();

        if (!$user->hasRole('admin')) {
            return $this->forbiddenResponse('Unauthorized');
        }

        $data = $request->validate([
            'shipment_ids' => 'required|array|min:1',
            'shipment_ids.*' => 'required|integer|exists:shipments,id',
            'collected' => 'required|boolean',
        ]);

        $shipmentIds = collect($data['shipment_ids'])
            ->filter()
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $collected = (bool) $data['collected'];
        $timestamp = $collected ? now() : null;

        $updated = Shipment::whereIn('id', $shipmentIds)
            ->where('cod_payment', true)
            ->update([
                'cod_collected' => $collected,
                'cod_collected_at' => $timestamp,
                'updated_at' => now(),
            ]);

        $updatedShipments = Shipment::whereIn('id', $shipmentIds)
            ->get(['id', 'cod_payment', 'cod_amount', 'cod_method', 'cod_collected', 'cod_collected_at']);

        return $this->successResponse([
            'updated' => $updated,
            'collected' => $collected,
            'shipments' => $updatedShipments,
        ], 'COD collection statuses updated successfully');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, string $id)
    {
        $shipment = Shipment::findOrFail($id);
        $user = $request->user();

        // Only admin can delete shipments
        if (!$user->hasRole('admin')) {
            return $this->errorResponse('Unauthorized', 403);
        }

        $shipment->delete();

        return $this->successResponse(null, 'Shipment deleted successfully');
    }

    /**
     * Track shipment by tracking number
     */
    public function track(Request $request, $trackingNumber)
    {
        $shipment = Shipment::with(['order.user'])
            ->where('tracking_number', $trackingNumber)
            ->first();

        if (!$shipment) {
            return $this->errorResponse('Shipment not found', 404);
        }

        return $this->successResponse($shipment);
    }

    /**
     * Get shipments by status
     */
    public function byStatus(Request $request, $status)
    {
        $user = $request->user();
        $query = Shipment::with(['order.user']);

        // Apply role-based filtering
        if ($user->hasRole('admin')) {
            // Admin can see all shipments
        } elseif ($user->hasRole('merchant')) {
            $query->whereHas('order', function($q) use ($user) {
                $q->where('merchant_id', $user->id);
            });
        } elseif ($user->hasRole('agent')) {
            $query->whereHas('order', function($q) use ($user) {
                $q->where('agent_id', $user->id);
            });
        } else {
            $query->whereHas('order', function($q) use ($user) {
                $q->where('user_id', $user->id);
            });
        }

        $shipments = $query->where('status', $status)
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 15));

        return $this->successResponse($shipments);
    }

    /**
     * Add tracking event
     */
    public function addTrackingEvent(Request $request, $id)
    {
        $shipment = Shipment::findOrFail($id);
        $user = $request->user();

        // Only admin can add tracking events
        if (!$user->hasRole('admin')) {
            return $this->errorResponse('Unauthorized', 403);
        }

        $request->validate([
            'event' => 'required|string',
            'description' => 'nullable|string',
            'location' => 'nullable|string',
        ]);

        $shipment->addTrackingEvent(
            $request->event,
            $request->description,
            $request->location
        );

        return $this->successResponse($shipment->load(['order.user']), 'Tracking event added successfully');
    }
}
