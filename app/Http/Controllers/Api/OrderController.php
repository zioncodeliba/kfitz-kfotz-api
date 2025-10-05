<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShippingCarrier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    use ApiResponse;

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Order::with(['items.product', 'user', 'merchant', 'agent']);

        // Filter by user role
        if ($user->hasRole('admin')) {
            // Admin can see all orders
        } elseif ($user->hasRole('merchant')) {
            // Merchant can see their own orders
            $query->where('merchant_id', $user->id);
        } elseif ($user->hasRole('agent')) {
            // Agent can see orders they created
            $query->where('agent_id', $user->id);
        } else {
            // Customer can see their own orders
            $query->where('user_id', $user->id);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by payment status
        if ($request->has('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        // Filter by date range
        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Search by order number
        if ($request->has('search')) {
            $query->where('order_number', 'like', '%' . $request->search . '%');
        }

        $orders = $query->orderBy('created_at', 'desc')->paginate($request->get('per_page', 15));

        return $this->successResponse($orders);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'shipping_address' => 'required|array',
            'billing_address' => 'required|array',
            'shipping_type' => 'required|in:delivery,pickup',
            'shipping_method' => 'required|in:regular,express,pickup',
            'cod_payment' => 'boolean',
            'notes' => 'nullable|string',
        ]);

        try {
            DB::beginTransaction();

            $user = $request->user();
            $items = $request->items;
            $subtotal = 0;

            // Calculate subtotal and validate stock
            foreach ($items as $item) {
                $product = Product::findOrFail($item['product_id']);
                
                if ($product->stock_quantity < $item['quantity']) {
                    return $this->errorResponse("Insufficient stock for product: {$product->name}", 400);
                }

                $unitPrice = $product->getCurrentPrice();
                $itemTotal = $unitPrice * $item['quantity'];
                $subtotal += $itemTotal;
            }

            // Calculate totals
            $tax = $subtotal * 0.17; // 17% VAT
            $shippingCost = $request->shipping_type === 'pickup' ? 0 : 30; // Basic shipping cost
            $discount = 0; // Can be calculated based on discounts
            $total = $subtotal + $tax + $shippingCost - $discount;

            // Create order
            $order = Order::create([
                'user_id' => $user->id,
                'merchant_id' => $user->hasRole('merchant') ? $user->id : null,
                'agent_id' => $user->hasRole('agent') ? $user->id : null,
                'status' => 'pending',
                'payment_status' => $request->cod_payment ? 'pending' : 'pending',
                'subtotal' => $subtotal,
                'tax' => $tax,
                'shipping_cost' => $shippingCost,
                'discount' => $discount,
                'total' => $total,
                'notes' => $request->notes,
                'shipping_address' => $request->shipping_address,
                'billing_address' => $request->billing_address,
                'shipping_type' => $request->shipping_type,
                'shipping_method' => $request->shipping_method,
                'cod_payment' => $request->cod_payment ?? false,
            ]);

            // Create order items and update stock
            foreach ($items as $item) {
                $product = Product::findOrFail($item['product_id']);
                $unitPrice = $product->getCurrentPrice();
                $itemTotal = $unitPrice * $item['quantity'];

                $order->items()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_sku' => $product->sku,
                    'quantity' => $item['quantity'],
                    'unit_price' => $unitPrice,
                    'total_price' => $itemTotal,
                    'product_data' => [
                        'description' => $product->description,
                        'images' => $product->images,
                    ],
                ]);

                // Update stock
                $product->decrement('stock_quantity', $item['quantity']);
            }

            DB::commit();

            return $this->createdResponse($order->load(['items.product', 'user']), 'Order created successfully');

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Failed to create order: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, string $id)
    {
        $user = $request->user();
        $order = Order::with(['items.product', 'user', 'merchant', 'agent'])->findOrFail($id);

        // Check permissions
        if (!$user->hasRole('admin') && 
            $order->user_id !== $user->id && 
            $order->merchant_id !== $user->id && 
            $order->agent_id !== $user->id) {
            return $this->errorResponse('Unauthorized', 403);
        }

        return $this->successResponse($order);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $order = Order::findOrFail($id);
        $user = $request->user();

        // Check permissions
        if (!$user->hasRole('admin') && 
            $order->merchant_id !== $user->id && 
            $order->agent_id !== $user->id) {
            return $this->errorResponse('Unauthorized', 403);
        }

        $request->validate([
            'status' => 'sometimes|in:pending,confirmed,processing,shipped,delivered,cancelled,refunded',
            'payment_status' => 'sometimes|in:pending,paid,failed,refunded',
            'tracking_number' => 'nullable|string',
            'shipping_company' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $order->update($request->only([
            'status', 'payment_status', 'tracking_number', 'shipping_company', 'notes'
        ]));

        // Update timestamps based on status
        if ($request->has('status')) {
            if ($request->status === 'shipped' && !$order->shipped_at) {
                $order->update(['shipped_at' => now()]);
            } elseif ($request->status === 'delivered' && !$order->delivered_at) {
                $order->update(['delivered_at' => now()]);
            }
        }

        return $this->successResponse($order->load(['items.product', 'user']), 'Order updated successfully');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $order = Order::findOrFail($id);
        $user = $request->user();

        // Only admin can delete orders
        if (!$user->hasRole('admin')) {
            return $this->errorResponse('Unauthorized', 403);
        }

        $order->delete();

        return $this->successResponse(null, 'Order deleted successfully');
    }

    /**
     * Get orders by status
     */
    public function byStatus(Request $request, $status)
    {
        $user = $request->user();
        $query = Order::with(['items.product', 'user']);

        // Apply role-based filtering
        if ($user->hasRole('admin')) {
            // Admin can see all orders
        } elseif ($user->hasRole('merchant')) {
            $query->where('merchant_id', $user->id);
        } elseif ($user->hasRole('agent')) {
            $query->where('agent_id', $user->id);
        } else {
            $query->where('user_id', $user->id);
        }

        $orders = $query->where('status', $status)
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 15));

        return $this->successResponse($orders);
    }

    /**
     * Get dashboard statistics
     */
    public function dashboard(Request $request)
    {
        $user = $request->user();
        $query = Order::query();

        // Apply role-based filtering
        if ($user->hasRole('admin')) {
            // Admin can see all orders
        } elseif ($user->hasRole('merchant')) {
            $query->where('merchant_id', $user->id);
        } elseif ($user->hasRole('agent')) {
            $query->where('agent_id', $user->id);
        } else {
            $query->where('user_id', $user->id);
        }

        $stats = [
            'total_orders' => $query->count(),
            'pending_orders' => $query->where('status', 'pending')->count(),
            'processing_orders' => $query->where('status', 'processing')->count(),
            'shipped_orders' => $query->where('status', 'shipped')->count(),
            'delivered_orders' => $query->where('status', 'delivered')->count(),
            'total_revenue' => $query->where('payment_status', 'paid')->sum('total'),
            'pending_payments' => $query->where('payment_status', 'pending')->sum('total'),
        ];

        return $this->successResponse($stats);
    }

    /**
     * Assign carrier to order
     */
    public function assignCarrier(Request $request, string $id)
    {
        $request->validate([
            'carrier_id' => 'required|exists:shipping_carriers,id',
            'service_type' => 'required|in:regular,express,pickup',
        ]);

        $order = Order::findOrFail($id);
        $carrier = ShippingCarrier::findOrFail($request->carrier_id);

        // Check if carrier is active
        if (!$carrier->isActive()) {
            return $this->errorResponse('Selected carrier is not active', 422);
        }

        // Assign carrier to order
        $order->assignCarrier($carrier, $request->service_type);

        // Calculate and update shipping cost
        $shippingCost = $order->calculateShippingCost();
        $order->update([
            'shipping_cost' => $shippingCost,
            'total' => $order->subtotal + $order->tax + $shippingCost - $order->discount,
        ]);

        return $this->successResponse([
            'order' => $order->load(['items.product', 'user', 'carrier']),
            'carrier_info' => $order->getCarrierInfo(),
            'shipping_cost' => $shippingCost,
        ], 'Carrier assigned successfully');
    }

    /**
     * Get available carriers for order
     */
    public function getAvailableCarriers(Request $request, string $id)
    {
        $order = Order::findOrFail($id);
        
        // Get active carriers
        $carriers = ShippingCarrier::active()->get();
        
        $availableCarriers = [];
        
        foreach ($carriers as $carrier) {
            // Calculate weight from order items
            $weight = $order->items->sum('weight') ?? 0;
            
            $carrierInfo = [
                'id' => $carrier->id,
                'name' => $carrier->name,
                'code' => $carrier->code,
                'description' => $carrier->description,
                'service_types' => $carrier->service_types,
                'package_types' => $carrier->package_types,
                'base_rate' => $carrier->base_rate,
                'rate_per_kg' => $carrier->rate_per_kg,
                'costs' => [],
            ];

            // Calculate costs for each service type
            foreach ($carrier->service_types as $serviceType) {
                $cost = $carrier->calculateShippingCost($weight, $serviceType);
                $carrierInfo['costs'][$serviceType] = $cost;
            }

            $availableCarriers[] = $carrierInfo;
        }

        return $this->successResponse([
            'order_id' => $order->id,
            'order_weight' => $order->items->sum('weight') ?? 0,
            'available_carriers' => $availableCarriers,
        ]);
    }

    /**
     * Calculate shipping cost for order (public)
     */
    public function calculateShippingCost(Request $request, string $id = null)
    {
        $request->validate([
            'carrier_id' => 'required|exists:shipping_carriers,id',
            'service_type' => 'required|in:regular,express,pickup',
            'weight' => 'required_if:order_id,null|numeric|min:0',
            'order_id' => 'nullable|exists:orders,id',
        ]);

        $carrier = ShippingCarrier::findOrFail($request->carrier_id);
        
        // If order_id is provided, use order weight, otherwise use provided weight
        if ($id || $request->order_id) {
            $orderId = $id ?? $request->order_id;
            $order = Order::findOrFail($orderId);
            $weight = $order->items->sum('weight') ?? 0;
        } else {
            $weight = $request->weight;
        }
        
        // Calculate shipping cost
        $shippingCost = $carrier->calculateShippingCost($weight, $request->service_type);

        $response = [
            'carrier' => [
                'id' => $carrier->id,
                'name' => $carrier->name,
                'code' => $carrier->code,
            ],
            'service_type' => $request->service_type,
            'weight' => $weight,
            'shipping_cost' => $shippingCost,
        ];

        // Add order info if available
        if (isset($order)) {
            $response['order_id'] = $order->id;
            $response['total_with_shipping'] = $order->subtotal + $order->tax + $shippingCost - $order->discount;
        }

        return $this->successResponse($response);
    }
}
