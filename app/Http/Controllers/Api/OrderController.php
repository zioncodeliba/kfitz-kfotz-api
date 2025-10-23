<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShippingCarrier;
use App\Models\Shipment;
use App\Models\Merchant;
use App\Models\User;
use App\Services\ShippingSettingsService;
use App\Http\Resources\ShippingCarrierResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    use ApiResponse;

    protected array $agentMerchantCache = [];

    public function __construct(
        protected ShippingSettingsService $shippingSettingsService
    ) {
    }

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

    protected function applyOrderVisibilityScope($query, $user, ?array $agentMerchantIds = null)
    {
        if ($user->hasRole('admin')) {
            return $query;
        }

        if ($user->hasRole('merchant')) {
            return $query->where('merchant_id', $user->id);
        }

        if ($user->hasRole('agent')) {
            $managedMerchantIds = $agentMerchantIds ?? $this->getAgentManagedMerchantUserIds($user);

            return $query->where(function ($agentQuery) use ($user, $managedMerchantIds) {
                $agentQuery->where('agent_id', $user->id);

                if (!empty($managedMerchantIds)) {
                    $agentQuery->orWhereIn('merchant_id', $managedMerchantIds);
                }
            });
        }

        return $query->where('user_id', $user->id);
    }

    protected function agentCanAccessOrder($user, Order $order): bool
    {
        if ($order->agent_id === $user->id) {
            return true;
        }

        $managedMerchantIds = $this->getAgentManagedMerchantUserIds($user);

        return !empty($managedMerchantIds) && in_array($order->merchant_id, $managedMerchantIds, true);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Order::with(['items.product', 'user', 'merchant', 'agent', 'merchantCustomer', 'merchantSite']);

        $agentMerchantIds = $user->hasRole('agent') ? $this->getAgentManagedMerchantUserIds($user) : null;
        $query = $this->applyOrderVisibilityScope($query, $user, $agentMerchantIds);

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
     * Get open orders (pending/confirmed/processing).
     */
    public function openOrders(Request $request)
    {
        $user = $request->user();
        $query = Order::with(['items.product', 'user', 'merchant', 'agent', 'merchantCustomer', 'merchantSite'])
            ->whereIn('status', ['pending', 'confirmed', 'processing']);

        $agentMerchantIds = $user->hasRole('agent') ? $this->getAgentManagedMerchantUserIds($user) : null;
        $query = $this->applyOrderVisibilityScope($query, $user, $agentMerchantIds);

        if ($request->has('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $merchantSiteFilter = $request->query('merchant_site_id');

        if ($merchantSiteFilter !== null && $merchantSiteFilter !== '') {
            if (is_array($merchantSiteFilter)) {
                $siteIds = array_map(static function ($value) {
                    return is_numeric($value) ? (int) $value : null;
                }, $merchantSiteFilter);

                $siteIds = array_filter($siteIds, static function ($value) {
                    return $value !== null;
                });

                if (!empty($siteIds)) {
                    $query->whereIn('merchant_site_id', $siteIds);
                }
            } elseif (is_string($merchantSiteFilter) && strtolower($merchantSiteFilter) === 'null') {
                $query->whereNull('merchant_site_id');
            } else {
                $query->where('merchant_site_id', (int) $merchantSiteFilter);
            }
        }

        if ($request->has('search')) {
            $query->where('order_number', 'like', '%' . $request->search . '%');
        }

        $orders = $query->orderBy('created_at', 'desc')->paginate($request->get('per_page', 15));

        return $this->successResponse($orders, 'Open orders retrieved successfully');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'shipping_address' => 'required|array',
            'billing_address' => 'required|array',
            'shipping_type' => 'required|in:delivery,pickup',
            'shipping_method' => 'required|in:regular,express,pickup',
            'notes' => 'nullable|string',
            'source' => 'nullable|string|max:50',
            'source_reference' => 'nullable|string|max:100',
            'source_metadata' => 'nullable|array',
            'shipping_cost' => 'nullable|numeric|min:0',
            'merchant_id' => 'nullable|exists:users,id',
        ]);

        try {
            DB::beginTransaction();

            $user = $request->user();
            $merchantId = null;
            $merchantUser = null;

            if ($user->hasRole('merchant')) {
                $merchantId = (int) $user->id;
            } elseif ($user->hasRole('agent')) {
                if (empty($data['merchant_id'])) {
                    return $this->errorResponse('Agent must specify a merchant for the order', 422);
                }

                $allowedMerchantIds = $this->getAgentManagedMerchantUserIds($user);
                if (!empty($allowedMerchantIds) && !in_array((int) $data['merchant_id'], $allowedMerchantIds, true)) {
                    return $this->errorResponse('Selected merchant is not assigned to this agent', 403);
                }

                $merchantId = (int) $data['merchant_id'];
            } elseif ($user->hasRole('admin')) {
                $merchantId = isset($data['merchant_id']) ? (int) $data['merchant_id'] : null;
            } else {
                $merchantId = isset($data['merchant_id']) ? (int) $data['merchant_id'] : null;
            }

            if ($merchantId) {
                $merchantUser = User::where('id', $merchantId)->where('role', 'merchant')->first();
                if (!$merchantUser) {
                    return $this->errorResponse('Selected merchant is invalid', 422);
                }
            }

            $items = $data['items'];
            $subtotal = 0;

            // Calculate subtotal and validate stock
            foreach ($items as $item) {
                $product = Product::findOrFail($item['product_id']);
                
                if ($product->stock_quantity < $item['quantity']) {
                    return $this->errorResponse("Insufficient stock for product: {$product->name}", 400);
                }

                $unitPrice = $this->resolveMerchantUnitPrice($product, $merchantId)
                    ?? $product->getCurrentPrice();
                $itemTotal = $unitPrice * $item['quantity'];
                $subtotal += $itemTotal;
            }

            // Calculate totals
            $tax = $subtotal * 0.17; // 17% VAT
            if ($data['shipping_type'] === 'pickup') {
                $shippingCost = 0;
            } elseif (array_key_exists('shipping_cost', $data) && $data['shipping_cost'] !== null) {
                $shippingCost = max(0, (float) $data['shipping_cost']);
            } else {
                $shippingCost = 30; // Basic default shipping cost
            }
            $discount = 0; // Can be calculated based on discounts
            $total = $subtotal + $tax + $shippingCost - $discount;

            $source = $data['source'] ?? null;
            $sourceMetadata = $data['source_metadata'] ?? [];

            if (!is_array($sourceMetadata)) {
                $sourceMetadata = [];
            }

            $source = $source && $source !== 'manual' ? strtolower($source) : null;

            if ($user->hasRole('merchant')) {
                $source = 'merchant_portal';
                $merchantModel = $user->merchant()->with('pluginSites')->first();
                if ($merchantModel) {
                    $site = $merchantModel->pluginSites->first();
                    $sourceMetadata['merchant_id'] = $merchantModel->id;
                    $sourceMetadata['merchant_name'] = $merchantModel->business_name;
                    if ($site) {
                        $sourceMetadata['site_url'] = $site->site_url;
                        if ($site->platform) {
                            $sourceMetadata['platform'] = $site->platform;
                        }
                    }
                }
            } elseif ($user->hasRole('agent')) {
                $source = 'agent-portal';
            } elseif (!$source) {
                $source = 'system';
            }

            $sourceMetadata = array_merge([
                'initiator' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'role' => $user->role,
                ],
            ], $sourceMetadata);

            // Create order
            $order = Order::create([
                'user_id' => $user->id,
                'merchant_id' => $merchantId,
                'agent_id' => $user->hasRole('agent') ? $user->id : null,
                'source' => $source,
                'source_reference' => $data['source_reference'] ?? null,
                'source_metadata' => $sourceMetadata,
                'status' => 'pending',
                'payment_status' => 'pending',
                'subtotal' => $subtotal,
                'tax' => $tax,
                'shipping_cost' => $shippingCost,
                'discount' => $discount,
                'total' => $total,
                'notes' => $data['notes'] ?? null,
                'shipping_address' => $data['shipping_address'],
                'billing_address' => $data['billing_address'],
                'shipping_type' => $data['shipping_type'],
                'shipping_method' => $data['shipping_method'],
            ]);

            // Create order items and update stock
            foreach ($items as $item) {
                $product = Product::findOrFail($item['product_id']);
                $unitPrice = $this->resolveMerchantUnitPrice($product, $merchantId)
                    ?? $product->getCurrentPrice();
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

            if ($merchantUser) {
                $merchantUser->refreshOrderFinancials();
            }

            return $this->createdResponse($order->load(['items.product', 'user']), 'Order created successfully');

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Failed to create order: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Resolve merchant-specific price for a product if available.
     */
    protected function resolveMerchantUnitPrice(Product $product, ?int $merchantUserId): ?float
    {
        if (!$merchantUserId) {
            return null;
        }

        $prices = $product->merchant_prices;
        if (!is_array($prices) || empty($prices)) {
            return null;
        }

        foreach ($prices as $entry) {
            if (is_object($entry)) {
                $entry = (array) $entry;
            }
            if (!is_array($entry)) {
                continue;
            }

            $entryMerchantId = $entry['merchant_id'] ?? null;
            if ($entryMerchantId === null) {
                continue;
            }

            if ((int) $entryMerchantId !== $merchantUserId) {
                continue;
            }

            $rawPrice = $entry['price'] ?? null;
            if (is_numeric($rawPrice)) {
                $price = (float) $rawPrice;
                return $price >= 0 ? $price : null;
            }
        }

        return null;
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, string $id)
    {
        $user = $request->user();
        $order = Order::with(['items.product', 'user', 'merchant', 'agent', 'merchantCustomer', 'merchantSite'])->findOrFail($id);

        // Check permissions
        if ($user->hasRole('admin')) {
            // allowed
        } elseif ($user->hasRole('merchant')) {
            if ($order->merchant_id !== $user->id) {
                return $this->errorResponse('Unauthorized', 403);
            }
        } elseif ($user->hasRole('agent')) {
            if (!$this->agentCanAccessOrder($user, $order)) {
                return $this->errorResponse('Unauthorized', 403);
            }
        } else {
            if ($order->user_id !== $user->id) {
                return $this->errorResponse('Unauthorized', 403);
            }
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
        if ($user->hasRole('admin')) {
            // allowed
        } elseif ($user->hasRole('merchant')) {
            if ($order->merchant_id !== $user->id) {
                return $this->errorResponse('Unauthorized', 403);
            }
        } elseif ($user->hasRole('agent')) {
            if (!$this->agentCanAccessOrder($user, $order)) {
                return $this->errorResponse('Unauthorized', 403);
            }
        } else {
            return $this->errorResponse('Unauthorized', 403);
        }

        $request->validate([
            'status' => 'sometimes|in:pending,confirmed,processing,shipped,delivered,cancelled,refunded',
            'payment_status' => 'sometimes|in:pending,paid,failed,refunded',
            'tracking_number' => 'nullable|string',
            'shipping_company' => 'nullable|string',
            'notes' => 'nullable|string',
            'source' => 'sometimes|nullable|string|max:50',
            'source_reference' => 'sometimes|nullable|string|max:100',
            'source_metadata' => 'sometimes|array',
        ]);

        $updates = $request->only([
            'status',
            'payment_status',
            'tracking_number',
            'shipping_company',
            'notes',
            'source',
            'source_reference',
        ]);

        if ($request->has('source_metadata')) {
            $updates['source_metadata'] = $request->input('source_metadata');
        }

        $order->update($updates);

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
        $query = Order::with(['items.product', 'user', 'merchantCustomer', 'merchantSite']);

        // Apply role-based filtering
        $agentMerchantIds = $user->hasRole('agent') ? $this->getAgentManagedMerchantUserIds($user) : null;
        $query = $this->applyOrderVisibilityScope($query, $user, $agentMerchantIds);

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

        $agentMerchantIds = $user->hasRole('agent') ? $this->getAgentManagedMerchantUserIds($user) : null;
        $query = $this->applyOrderVisibilityScope($query, $user, $agentMerchantIds);

        [$currentStart, $currentEnd] = $this->resolveRangeDates('last_week', Carbon::today());
        $daysInWindow = $currentStart->diffInDays($currentEnd) + 1;
        $previousEnd = $currentStart->copy()->subDay()->endOfDay();
        $previousStart = $previousEnd->copy()->subDays($daysInWindow - 1)->startOfDay();

        $totals = [];
        $trends = [];

        // Total orders
        $totals['total_orders'] = (clone $query)->count();
        $currentOrders = (clone $query)->whereBetween('created_at', [$currentStart, $currentEnd])->count();
        $previousOrders = (clone $query)->whereBetween('created_at', [$previousStart, $previousEnd])->count();
        $trends['total_orders'] = $this->buildTrendPayload($currentOrders, $previousOrders);

        // Pending orders
        $pendingQuery = (clone $query)->where('status', 'pending');
        $totals['pending_orders'] = (clone $pendingQuery)->count();
        $currentPending = (clone $pendingQuery)->whereBetween('created_at', [$currentStart, $currentEnd])->count();
        $previousPending = (clone $pendingQuery)->whereBetween('created_at', [$previousStart, $previousEnd])->count();
        $trends['pending_orders'] = $this->buildTrendPayload($currentPending, $previousPending);

        // Processing orders
        $processingQuery = (clone $query)->where('status', 'processing');
        $totals['processing_orders'] = (clone $processingQuery)->count();
        $currentProcessing = (clone $processingQuery)->whereBetween('created_at', [$currentStart, $currentEnd])->count();
        $previousProcessing = (clone $processingQuery)->whereBetween('created_at', [$previousStart, $previousEnd])->count();
        $trends['processing_orders'] = $this->buildTrendPayload($currentProcessing, $previousProcessing);

        // Shipped orders
        $shippedQuery = (clone $query)->where('status', 'shipped');
        $totals['shipped_orders'] = (clone $shippedQuery)->count();
        $currentShipped = (clone $shippedQuery)->whereBetween('created_at', [$currentStart, $currentEnd])->count();
        $previousShipped = (clone $shippedQuery)->whereBetween('created_at', [$previousStart, $previousEnd])->count();
        $trends['shipped_orders'] = $this->buildTrendPayload($currentShipped, $previousShipped);

        // Delivered orders
        $deliveredQuery = (clone $query)->where('status', 'delivered');
        $totals['delivered_orders'] = (clone $deliveredQuery)->count();
        $currentDelivered = (clone $deliveredQuery)->whereBetween('created_at', [$currentStart, $currentEnd])->count();
        $previousDelivered = (clone $deliveredQuery)->whereBetween('created_at', [$previousStart, $previousEnd])->count();
        $trends['delivered_orders'] = $this->buildTrendPayload($currentDelivered, $previousDelivered);

        // Total revenue (paid orders)
        $paidQuery = (clone $query)->where('payment_status', 'paid');
        $totalRevenue = (float) (clone $paidQuery)->sum('total');
        $totals['total_revenue'] = round($totalRevenue, 2);
        $currentRevenue = (float) (clone $paidQuery)->whereBetween('created_at', [$currentStart, $currentEnd])->sum('total');
        $previousRevenue = (float) (clone $paidQuery)->whereBetween('created_at', [$previousStart, $previousEnd])->sum('total');
        $trends['total_revenue'] = $this->buildTrendPayload($currentRevenue, $previousRevenue);

        // Pending payments (sum of totals where payment status pending)
        $pendingPaymentQuery = (clone $query)->where('payment_status', 'pending');
        $pendingPaymentsTotal = (float) (clone $pendingPaymentQuery)->sum('total');
        $totals['pending_payments'] = round($pendingPaymentsTotal, 2);
        $currentPendingPayments = (float) (clone $pendingPaymentQuery)->whereBetween('created_at', [$currentStart, $currentEnd])->sum('total');
        $previousPendingPayments = (float) (clone $pendingPaymentQuery)->whereBetween('created_at', [$previousStart, $previousEnd])->sum('total');
        $trends['pending_payments'] = $this->buildTrendPayload($currentPendingPayments, $previousPendingPayments);

        $periodMeta = [
            'label' => 'last_week',
            'current_start' => $currentStart->toDateString(),
            'current_end' => $currentEnd->toDateString(),
            'previous_start' => $previousStart->toDateString(),
            'previous_end' => $previousEnd->toDateString(),
        ];

        return $this->successResponse(array_merge($totals, [
            'trends' => $trends,
            'period' => $periodMeta,
        ]));
    }

    /**
     * Get sales performance data for dashboard charting.
     */
    public function salesPerformance(Request $request)
    {
        $user = $request->user();
        
        if (!$user->hasRole('admin')) {
            return $this->errorResponse('Unauthorized', 403);
        }

        $range = $request->query('range', 'last_week');
        $customStart = $request->query('start_date');
        $customEnd = $request->query('end_date');

        $today = Carbon::today();
        $endDate = $customEnd ? Carbon::parse($customEnd)->endOfDay() : $today->copy()->endOfDay();
        [$defaultStart, $defaultEnd] = $this->resolveRangeDates($range, $endDate->copy());
        $startDate = $customStart
            ? Carbon::parse($customStart)->startOfDay()
            : $defaultStart->startOfDay();
        $endDate = $customEnd
            ? Carbon::parse($customEnd)->endOfDay()
            : $defaultEnd->endOfDay();

        if ($startDate->gt($endDate)) {
            [$startDate, $endDate] = [$endDate->copy()->startOfDay(), $startDate->copy()->endOfDay()];
        }

        $daysInRange = $startDate->copy()->startOfDay()->diffInDays($endDate->copy()->endOfDay()) + 1;
        $previousEnd = $startDate->copy()->subDay()->endOfDay();
        $previousStart = $previousEnd->copy()->subDays($daysInRange - 1)->startOfDay();

        $baseQuery = Order::query();

        if ($user->hasRole('admin')) {
            // no restriction
        } elseif ($user->hasRole('merchant')) {
            $baseQuery->where('merchant_id', $user->id);
        } elseif ($user->hasRole('agent')) {
            $baseQuery->where('agent_id', $user->id);
        } else {
            $baseQuery->where('user_id', $user->id);
        }

        $currentRangeQuery = (clone $baseQuery)->whereBetween('created_at', [$startDate, $endDate]);
        $previousRangeQuery = (clone $baseQuery)->whereBetween('created_at', [$previousStart, $previousEnd]);

        $currentRevenueQuery = (clone $currentRangeQuery)->where('payment_status', 'paid');
        $previousRevenueQuery = (clone $previousRangeQuery)->where('payment_status', 'paid');

        $currentRevenue = (clone $currentRevenueQuery)->sum('total');
        $previousRevenue = (clone $previousRevenueQuery)->sum('total');
        $ordersCount = (clone $currentRangeQuery)->count();

        $dataset = (clone $currentRevenueQuery)
            ->selectRaw('DATE(created_at) as date_label, SUM(total) as total_revenue, COUNT(*) as orders_count')
            ->groupBy('date_label')
            ->orderBy('date_label')
            ->get()
            ->map(function ($row) {
                return [
                    'date' => $row->date_label,
                    'total_revenue' => (float) $row->total_revenue,
                    'orders_count' => (int) $row->orders_count,
                ];
            })
            ->values();

        $difference = $currentRevenue - $previousRevenue;
        $percentageChange = $previousRevenue > 0
            ? round(($difference / $previousRevenue) * 100, 2)
            : ($currentRevenue > 0 ? 100.0 : 0.0);

        $response = [
            'range' => $customStart || $customEnd ? 'custom' : $range,
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
            'previous_start_date' => $previousStart->toDateString(),
            'previous_end_date' => $previousEnd->toDateString(),
            'days_in_range' => $daysInRange,
            'total_revenue' => round($currentRevenue, 2),
            'orders_count' => $ordersCount,
            'previous_total_revenue' => round($previousRevenue, 2),
            'difference' => round($difference, 2),
            'percentage_change' => $percentageChange,
            'dataset' => $dataset,
        ];

        return $this->successResponse($response);
    }

    /**
     * Resolve the start and end dates for a predefined range.
     */
    private function resolveRangeDates(string $range, Carbon $referenceEnd): array
    {
        $end = $referenceEnd->copy()->endOfDay();

        switch ($range) {
            case 'last_month':
                $start = $end->copy()->subDays(29)->startOfDay();
                break;
            case 'last_quarter':
                $start = $end->copy()->subDays(89)->startOfDay();
                break;
            case 'last_year':
                $start = $end->copy()->subDays(364)->startOfDay();
                break;
            case 'last_week':
            default:
                $start = $end->copy()->subDays(6)->startOfDay();
                break;
        }

        return [$start, $end];
    }

    /**
     * Build trend payload with difference and percentage change.
     */
    private function buildTrendPayload($current, $previous): array
    {
        $difference = $current - $previous;
        $percentage = $previous != 0
            ? round(($difference / $previous) * 100, 2)
            : ($current > 0 ? 100.0 : 0.0);

        $formatter = function ($value) {
            if (is_int($value)) {
                return $value;
            }

            if (is_float($value)) {
                return round($value, 2);
            }

            return is_numeric($value) ? round((float) $value, 2) : 0;
        };

        return [
            'current_period' => $formatter($current),
            'previous_period' => $formatter($previous),
            'difference' => $formatter($difference),
            'percentage_change' => $percentage,
        ];
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
     * Get orders that are awaiting shipment (based on shipment records).
     */
    public function waitingForShipment(Request $request)
    {
        $user = $request->user();

        $query = Shipment::with([
                'order.items.product',
                'order.user',
                'order.merchant',
                'order.merchantCustomer',
                'order.merchantSite',
                'carrier',
            ])
            ->whereIn('status', Shipment::ACTIVE_STATUSES)
            ->whereHas('order', function ($orderQuery) {
                $orderQuery->where('status', Order::STATUS_SHIPPED);
            });

        if ($user->hasRole('admin')) {
            // Admin can see all shipments awaiting delivery
        } elseif ($user->hasRole('merchant')) {
            $query->whereHas('order', function ($orderQuery) use ($user) {
                $orderQuery->where('merchant_id', $user->id);
            });
        } elseif ($user->hasRole('agent')) {
            $managedMerchantIds = $this->getAgentManagedMerchantUserIds($user);
            $query->whereHas('order', function ($orderQuery) use ($user, $managedMerchantIds) {
                $orderQuery->where(function ($inner) use ($user, $managedMerchantIds) {
                    $inner->where('agent_id', $user->id);

                    if (!empty($managedMerchantIds)) {
                        $inner->orWhereIn('merchant_id', $managedMerchantIds);
                    }
                });
            });
        } else {
            $query->whereHas('order', function ($orderQuery) use ($user) {
                $orderQuery->where('user_id', $user->id);
            });
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($shipmentQuery) use ($search) {
                $shipmentQuery->where('tracking_number', 'like', "%{$search}%")
                    ->orWhereHas('order', function ($orderQuery) use ($search) {
                        $orderQuery->where('order_number', 'like', "%{$search}%");
                    });
            });
        }

        $shipments = $query->orderBy('created_at', 'desc')->paginate($request->get('per_page', 15));

        return $this->successResponse($shipments, 'Orders waiting for shipment retrieved successfully');
    }

    public function closedOrders(Request $request)
    {
        $user = $request->user();

        $query = Shipment::with([
                'order.items.product',
                'order.user',
                'order.merchant',
                'order.merchantCustomer',
                'order.merchantSite',
                'carrier',
            ])
            ->whereIn('status', Shipment::FINAL_STATUSES)
            ->whereHas('order', function ($orderQuery) {
                $orderQuery->where('status', Order::STATUS_DELIVERED);
            });

        if ($user->hasRole('admin')) {
            // Admin can see all closed shipments
        } elseif ($user->hasRole('merchant')) {
            $query->whereHas('order', function ($orderQuery) use ($user) {
                $orderQuery->where('merchant_id', $user->id);
            });
        } elseif ($user->hasRole('agent')) {
            $managedMerchantIds = $this->getAgentManagedMerchantUserIds($user);
            $query->whereHas('order', function ($orderQuery) use ($user, $managedMerchantIds) {
                $orderQuery->where(function ($inner) use ($user, $managedMerchantIds) {
                    $inner->where('agent_id', $user->id);

                    if (!empty($managedMerchantIds)) {
                        $inner->orWhereIn('merchant_id', $managedMerchantIds);
                    }
                });
            });
        } else {
            $query->whereHas('order', function ($orderQuery) use ($user) {
                $orderQuery->where('user_id', $user->id);
            });
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($shipmentQuery) use ($search) {
                $shipmentQuery->where('tracking_number', 'like', "%{$search}%")
                    ->orWhereHas('order', function ($orderQuery) use ($search) {
                        $orderQuery->where('order_number', 'like', "%{$search}%");
                    });
            });
        }

        $shipments = $query->orderBy('updated_at', 'desc')->paginate($request->get('per_page', 15));

        return $this->successResponse($shipments, 'Closed orders retrieved successfully');
    }

    /**
     * Get shipping settings context for a specific order.
     */
    public function getShippingSettings(Request $request, string $id)
    {
        $order = Order::with(['items', 'carrier', 'user', 'merchant', 'shipment', 'merchantCustomer', 'merchantSite'])->findOrFail($id);
        $user = $request->user();

        if (!$this->canManageOrderShipping($user, $order)) {
            return $this->errorResponse('Unauthorized', 403);
        }

        $merchant = Merchant::where('user_id', $order->merchant_id)->first();
        $payload = $this->shippingSettingsService->buildPayload($merchant);

        $existingShipment = $order->shipment()->latest('created_at')->first();

        return $this->successResponse([
            'order' => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'shipping_type' => $order->shipping_type,
                'shipping_method' => $order->shipping_method,
                'shipping_cost' => $order->shipping_cost,
                'carrier_id' => $order->carrier_id,
                'carrier_service_type' => $order->carrier_service_type,
                'weight' => $order->items->sum('weight') ?? 0,
                'shipping_address' => $order->shipping_address,
                'billing_address' => $order->billing_address,
                'carrier' => $order->carrier ? new ShippingCarrierResource($order->carrier) : null,
            ],
            'merchant' => $merchant ? [
                'id' => $merchant->id,
                'user_id' => $merchant->user_id,
                'business_name' => $merchant->business_name,
                'address' => $merchant->address,
            ] : null,
            'settings' => $payload['settings'],
            'options' => $payload['options'],
            'shipment' => $existingShipment ? $existingShipment->load(['carrier']) : null,
        ], 'Order shipping settings retrieved successfully');
    }

    /**
     * Update shipping settings context for a specific order.
     */
    public function updateShippingSettings(Request $request, string $id)
    {
        $order = Order::with(['items', 'carrier', 'user', 'merchant', 'shipment', 'merchantCustomer', 'merchantSite'])->findOrFail($id);
        $user = $request->user();

        if (!$this->canManageOrderShipping($user, $order)) {
            return $this->errorResponse('Unauthorized', 403);
        }

        $sizeOptions = $this->shippingSettingsService->shippingSizeOptions();

        $validated = $request->validate([
            'default_destination' => 'nullable|string|max:100',
            'default_service_type' => 'nullable|string|max:100',
            'default_shipping_size' => ['nullable', Rule::in($sizeOptions)],
            'default_package_type' => ['nullable', Rule::in($sizeOptions)],
            'shipping_units' => 'nullable|array',
            'shipping_units.*.destination' => 'required|string|max:100',
            'shipping_units.*.service_type' => 'required|string|max:100',
            'shipping_units.*.carrier_id' => 'nullable|exists:shipping_carriers,id',
            'shipping_units.*.carrier_code' => 'nullable|string|max:191|exists:shipping_carriers,code',
            'shipping_units.*.shipping_size' => ['nullable', Rule::in($sizeOptions)],
            'shipping_units.*.package_type' => ['nullable', Rule::in($sizeOptions)],
            'shipping_units.*.quantity' => 'required|integer|min:1|max:999',
            'shipping_units.*.price' => 'nullable|numeric|min:0',
            'shipping_units.*.notes' => 'nullable|string',
            'carrier_id' => 'nullable|exists:shipping_carriers,id',
            'carrier_service_type' => 'required_with:carrier_id|string|max:100',
            'shipping_type' => 'nullable|in:delivery,pickup',
            'shipping_method' => 'nullable|string|max:100',
            'dispatch_shipment' => 'nullable|boolean',
            'carrier_name' => 'nullable|string|max:191',
            'origin_address' => 'nullable|array',
            'destination_address' => 'nullable|array',
            'shipping_cost' => 'nullable|numeric|min:0',
            'cod_payment' => 'nullable|boolean',
            'cod_amount' => 'nullable|numeric|min:0',
            'cod_method' => 'nullable|string|max:191',
            'shipment_notes' => 'nullable|string',
        ]);

        $merchant = Merchant::where('user_id', $order->merchant_id)->first();

        if ($merchant) {
            $merchant->update([
                'shipping_settings' => $this->shippingSettingsService->prepareForStorage(
                    $validated,
                    [
                        'carrier_id',
                        'carrier_service_type',
                        'shipping_type',
                        'shipping_method',
                        'dispatch_shipment',
                        'origin_address',
                        'destination_address',
                        'carrier_name',
                        'shipping_cost',
                        'cod_payment',
                        'cod_amount',
                        'cod_method',
                        'shipment_notes',
                    ]
                ),
            ]);
        }

        $orderUpdates = collect([
            'shipping_type' => $validated['shipping_type'] ?? null,
            'shipping_method' => $validated['shipping_method'] ?? null,
        ])->filter(fn ($value) => !is_null($value));

        if ($orderUpdates->isNotEmpty()) {
            $order->update($orderUpdates->all());
        }

        $carrier = null;
        $existingShipment = $order->shipment()->latest('created_at')->first();

        if (!empty($validated['carrier_id'])) {
            $carrier = ShippingCarrier::findOrFail($validated['carrier_id']);

            if (!$carrier->isActive()) {
                return $this->errorResponse('Selected carrier is not active', 422);
            }

            $serviceType = $validated['carrier_service_type'];

            if (is_array($carrier->service_types) && !in_array($serviceType, $carrier->service_types)) {
                return $this->errorResponse('Selected service type is not available for this carrier', 422);
            }

            $order->assignCarrier($carrier, $serviceType);

            $shippingCost = $order->fresh(['items', 'carrier'])->calculateShippingCost();

            $order->update([
                'shipping_cost' => $shippingCost,
                'total' => $order->subtotal + $order->tax + $shippingCost - $order->discount,
            ]);
        }

        $shipment = null;

        if ($request->boolean('dispatch_shipment')) {
            $order->refresh();
            $merchant?->refresh();
            $shipment = $this->dispatchShipmentForOrder(
                $order,
                $merchant,
                $validated,
                $request,
                $carrier ?? $order->carrier,
                $existingShipment
            );
            $order = $order->fresh(['items', 'carrier', 'user', 'merchant']);
        }

        $order->refresh();
        $merchant?->refresh();

        $payload = $this->shippingSettingsService->buildPayload($merchant);

        return $this->successResponse([
            'order' => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'shipping_type' => $order->shipping_type,
                'shipping_method' => $order->shipping_method,
                'shipping_cost' => $order->shipping_cost,
                'carrier_id' => $order->carrier_id,
                'carrier_service_type' => $order->carrier_service_type,
                'weight' => $order->items->sum('weight') ?? 0,
                'shipping_address' => $order->shipping_address,
                'billing_address' => $order->billing_address,
                'carrier' => $order->carrier ? new ShippingCarrierResource($order->carrier) : null,
            ],
            'shipment' => $shipment ? $shipment->load(['order', 'carrier']) : null,
            'merchant' => $merchant ? [
                'id' => $merchant->id,
                'user_id' => $merchant->user_id,
                'business_name' => $merchant->business_name,
            ] : null,
            'settings' => $payload['settings'],
            'options' => $payload['options'],
        ], 'Order shipping settings updated successfully');
    }

    protected function dispatchShipmentForOrder(
        Order $order,
        ?Merchant $merchant,
        array $validated,
        Request $request,
        ?ShippingCarrier $carrier = null,
        ?Shipment $existingShipment = null
    ): Shipment {
        $serviceType = $validated['carrier_service_type'] ?? $order->carrier_service_type ?? $order->shipping_method ?? 'regular';
        $normalizedServiceType = $this->normalizeShipmentServiceType($serviceType);

        $destinationCandidate = $validated['default_destination']
            ?? ($validated['shipping_units'][0]['destination'] ?? null)
            ?? ($existingShipment?->shipping_units[0]['destination'] ?? null);
        $normalizedDestination = $this->normalizeShipmentDestination($destinationCandidate);

        $sizeCandidate = $validated['default_shipping_size']
            ?? $validated['default_package_type']
            ?? ($validated['shipping_units'][0]['shipping_size'] ?? null)
            ?? ($validated['shipping_units'][0]['package_type'] ?? null)
            ?? ($existingShipment?->shipping_units[0]['shipping_size'] ?? null)
            ?? ($existingShipment?->shipping_units[0]['package_type'] ?? null);

        $packageType = $this->shippingSettingsService->normalizeSize($sizeCandidate);

        $originAddress = $this->resolveOriginAddress(
            $request->input('origin_address'),
            $merchant,
            $order
        );
        $destinationAddress = $this->resolveDestinationAddress(
            $request->input('destination_address'),
            $order
        );

        $shippingCost = $validated['shipping_cost'] ?? $order->shipping_cost ?? $existingShipment?->shipping_cost ?? 0;
        $codPayment = $request->has('cod_payment')
            ? $request->boolean('cod_payment')
            : ($existingShipment?->cod_payment ?? false);
        $codAmount = $codPayment
            ? ($request->has('cod_amount') ? $validated['cod_amount'] : ($existingShipment?->cod_amount ?? 0))
            : null;
        $codMethod = $codPayment
            ? ($request->input('cod_method') ?? $existingShipment?->cod_method ?? null)
            : null;

        $carrierName = $request->input('carrier_name')
            ?? $carrier?->name
            ?? $order->carrier?->name
            ?? 'Manual';

        $requestedShippingUnits = $validated['shipping_units'] ?? $request->input('shipping_units', []);
        $shippingUnitsPayload = collect($requestedShippingUnits)
            ->map(function ($unit) use ($normalizedDestination, $normalizedServiceType, $packageType, $carrier, $carrierName) {
                $normalizedSize = $this->shippingSettingsService->normalizeSize($unit['shipping_size'] ?? $unit['package_type'] ?? $packageType);
                $destination = $this->normalizeShipmentDestination($unit['destination'] ?? $normalizedDestination);
                $serviceTypeNormalized = $this->normalizeShipmentServiceType($unit['service_type'] ?? $normalizedServiceType);

                return [
                    'destination' => $destination,
                    'service_type' => $serviceTypeNormalized,
                    'carrier_id' => $unit['carrier_id'] ?? $carrier?->id,
                    'carrier_code' => $unit['carrier_code'] ?? $carrier?->code,
                    'carrier_name' => $unit['carrier_name'] ?? $carrierName,
                    'shipping_size' => $normalizedSize,
                    'package_type' => $this->shippingSettingsService->normalizeSize($unit['package_type'] ?? $normalizedSize),
                    'quantity' => isset($unit['quantity']) ? max(1, (int) $unit['quantity']) : 1,
                    'price' => isset($unit['price']) ? (float) $unit['price'] : null,
                    'notes' => $unit['notes'] ?? null,
                ];
            })
            ->filter()
            ->values()
            ->all();

        if (empty($shippingUnitsPayload)) {
            if ($existingShipment && is_array($existingShipment->shipping_units) && count($existingShipment->shipping_units) > 0) {
                $shippingUnitsPayload = $existingShipment->shipping_units;
            } else {
                $shippingUnitsPayload = [[
                    'destination' => $normalizedDestination,
                    'service_type' => $normalizedServiceType,
                    'carrier_id' => $carrier?->id,
                    'carrier_code' => $carrier?->code,
                    'carrier_name' => $carrierName,
                    'shipping_size' => $packageType,
                    'package_type' => $packageType,
                    'quantity' => 1,
                    'price' => null,
                    'notes' => null,
                ]];
            }
        }

        return DB::transaction(function () use (
            $order,
            $carrier,
            $carrierName,
            $serviceType,
            $normalizedServiceType,
            $packageType,
            $shippingCost,
            $originAddress,
            $destinationAddress,
            $codPayment,
            $codAmount,
            $request,
            $shippingUnitsPayload,
            $codMethod
        ) {
            $shipment = Shipment::firstOrNew(['order_id' => $order->id]);

            if (!$shipment->exists) {
                $shipment->status = 'pending';
            }

            $shipment->fill([
                'carrier_id' => $carrier?->id,
                'carrier_service_type' => $serviceType,
                'carrier' => $carrierName,
                'service_type' => $normalizedServiceType,
                'package_type' => $packageType,
                'origin_address' => $originAddress,
                'destination_address' => $destinationAddress,
                'shipping_cost' => $shippingCost,
                'weight' => $request->input('weight') ?? ($order->items->sum('weight') ?? null),
                'cod_payment' => $codPayment,
                'cod_amount' => $codPayment ? $codAmount : null,
                'cod_method' => $codPayment ? $codMethod : null,
                'shipping_units' => $shippingUnitsPayload,
                'notes' => $request->input('shipment_notes'),
            ]);

            $shipment->save();

            if ($shipment->wasRecentlyCreated) {
                $shipment->addTrackingEvent('Shipment created', 'Shipment has been created and is pending pickup');
            }

            $order->update([
                'status' => 'shipped',
                'shipping_cost' => $shippingCost,
                'shipping_company' => $carrierName,
                'carrier_id' => $carrier?->id ?? $order->carrier_id,
                'carrier_service_type' => $serviceType,
                'shipping_method' => $normalizedServiceType,
                'shipped_at' => now(),
            ]);

            return $shipment;
        });
    }

    protected function normalizeShipmentServiceType(?string $serviceType): string
    {
        $value = strtolower((string) $serviceType);

        return match ($value) {
            'pickup' => 'pickup',
            'express' => 'express',
            'regular', 'courier', 'delivery', 'standard' => 'regular',
            default => 'regular',
        };
    }

    protected function normalizeShipmentDestination(?string $destination): string
    {
        $value = strtolower((string) $destination);

        return match ($value) {
            'merchant', 'seller' => 'merchant',
            'merchant-client', 'merchant_client', 'dealer-client', 'reseller-client' => 'merchant-client',
            'customer', 'client', 'end-customer', 'end_customer' => 'customer',
            default => 'customer',
        };
    }

    protected function resolveOriginAddress(?array $provided, ?Merchant $merchant, Order $order): array
    {
        if (is_array($provided) && count($provided)) {
            return $provided;
        }

        if ($merchant && is_array($merchant->address) && count($merchant->address)) {
            return $merchant->address;
        }

        $billing = is_array($order->billing_address) ? $order->billing_address : [];

        return array_merge([
            'name' => $merchant?->business_name ?? 'מחסן החברה',
            'street' => $billing['street'] ?? ($billing['address'] ?? ''),
            'city' => $billing['city'] ?? '',
            'zip' => $billing['zip'] ?? ($billing['postal_code'] ?? ''),
            'phone' => $merchant?->phone ?? ($order->user?->phone ?? null),
        ], $billing);
    }

    protected function resolveDestinationAddress(?array $provided, Order $order): array
    {
        if (is_array($provided) && count($provided)) {
            return $provided;
        }

        $shipping = is_array($order->shipping_address) ? $order->shipping_address : [];

        return array_merge([
            'name' => $shipping['name'] ?? $order->user?->name ?? 'לקוח',
            'street' => $shipping['street'] ?? ($shipping['address'] ?? ''),
            'city' => $shipping['city'] ?? '',
            'zip' => $shipping['zip'] ?? ($shipping['postal_code'] ?? ''),
            'phone' => $shipping['phone'] ?? ($order->user?->phone ?? null),
        ], $shipping);
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

    /**
     * Determine if the authenticated user can manage shipping for the order.
     */
    protected function canManageOrderShipping($user, Order $order): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        if ($user->hasRole('merchant') && $order->merchant_id === $user->id) {
            return true;
        }

        return false;
    }
}
