<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\ShippingCarrier;
use App\Models\Shipment;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Discount;
use App\Models\Merchant;
use App\Services\ShippingSettingsService;
use App\Services\EmailTemplateService;
use App\Services\YpayInvoiceService;
use App\Http\Resources\ShippingCarrierResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    use ApiResponse;

    protected array $agentMerchantCache = [];
    protected array $productQuantityDiscountCache = [];
    protected array $storewideDiscountCache = [];
    protected array $merchantSpecificDiscountCache = [];

    public function __construct(
        protected ShippingSettingsService $shippingSettingsService,
        protected EmailTemplateService $emailTemplateService
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
    public function store(Request $request, YpayInvoiceService $ypayInvoiceService)
    {
        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.variation_id' => 'nullable|integer|exists:product_variations,id',
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
            'carrier_id' => 'nullable|exists:shipping_carriers,id',
            'carrier_service_type' => 'nullable|string|max:100',
        ]);

        $selectedCarrier = null;
        if (!empty($data['carrier_id'])) {
            $selectedCarrier = ShippingCarrier::findOrFail($data['carrier_id']);
            if (!$selectedCarrier->isActive()) {
                return $this->errorResponse('Selected carrier is not active', 422);
            }
        }

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

            $rawItems = $data['items'];
            $normalizedItems = [];
            $subtotal = 0.0;
            $benefitTotal = 0.0;
            $grossPaidSubtotal = 0.0;
            $storewideDiscountTotal = 0.0;
            $merchantDiscountTotal = 0.0;

            foreach ($rawItems as $item) {
                $productId = (int) $item['product_id'];
                $quantity = (int) $item['quantity'];

                $product = Product::with(['productVariations', 'category'])->findOrFail($productId);

                if ($product->stock_quantity < $quantity) {
                    return $this->errorResponse("Insufficient stock for product: {$product->name}", 400);
                }

                $variation = null;
                if (array_key_exists('variation_id', $item) && $item['variation_id'] !== null) {
                    $variationId = (int) $item['variation_id'];
                    $variation = $product->productVariations->firstWhere('id', $variationId);

                    if (!$variation) {
                        return $this->errorResponse('Selected variation does not belong to the chosen product.', 422);
                    }

                    if ($variation->inventory !== null && $variation->inventory < $quantity) {
                        return $this->errorResponse("Insufficient stock for selected variation of product: {$product->name}", 400);
                    }
                }

                if ($product->productVariations->isNotEmpty() && !$variation) {
                    return $this->errorResponse('Variation is required for products that have defined variations.', 422);
                }

                $pricing = $this->determineUnitPrice($product, $variation, $merchantId);
                $baseUnitPrice = $pricing['unit_price'];

                $merchantDiscount = $this->applyMerchantSpecificDiscount($product, $merchantId, $baseUnitPrice);
                $storewideDiscount = $this->applyStorewideDiscount($product, $merchantId, $baseUnitPrice);

                $unitPrice = $baseUnitPrice;
                $appliedDiscountType = 'none';

                if ($merchantDiscount['applied'] && $merchantDiscount['unit_price'] < $unitPrice) {
                    $unitPrice = $merchantDiscount['unit_price'];
                    $appliedDiscountType = 'merchant';
                }

                if ($storewideDiscount['applied'] && $storewideDiscount['unit_price'] < $unitPrice) {
                    $unitPrice = $storewideDiscount['unit_price'];
                    $appliedDiscountType = 'storewide';
                }

                $pricing['base_unit_price'] = $baseUnitPrice;
                $pricing['unit_price'] = $unitPrice;
                $pricing['applied_discount_type'] = $appliedDiscountType;
                $pricing['merchant_discount'] = $merchantDiscount;
                $pricing['storewide_discount'] = $storewideDiscount;

                $quantityDiscount = $this->calculateQuantityDiscount($product, $quantity, $unitPrice);
                $chargedQuantity = $quantityDiscount['charged_quantity'];
                $freeUnits = $quantityDiscount['free_units'];
                $deliveredQuantity = $quantityDiscount['delivered_units'];

                $lineGrossPaid = round($baseUnitPrice * $chargedQuantity, 2);
                $lineNetTotal = round($unitPrice * $chargedQuantity, 2);
                $benefitValue = round($quantityDiscount['benefit_value'], 2);

                $lineStorewideDiscount = 0.0;
                $lineMerchantDiscount = 0.0;

                if ($appliedDiscountType === 'storewide') {
                    $lineStorewideDiscount = round(($baseUnitPrice - $unitPrice) * $chargedQuantity, 2);
                } elseif ($appliedDiscountType === 'merchant') {
                    $lineMerchantDiscount = round(($baseUnitPrice - $unitPrice) * $chargedQuantity, 2);
                }

                $subtotal += $lineNetTotal;
                $benefitTotal += $benefitValue;
                $grossPaidSubtotal += $lineGrossPaid;
                $storewideDiscountTotal += $lineStorewideDiscount;
                $merchantDiscountTotal += $lineMerchantDiscount;

                $pricing['original_line_total'] = $lineGrossPaid;
                $pricing['net_line_total'] = $lineNetTotal;
                $pricing['charged_quantity'] = $chargedQuantity;
                $pricing['delivered_quantity'] = $deliveredQuantity;
                $pricing['discount_details'] = $quantityDiscount;
                $pricing['storewide_discount_total'] = $lineStorewideDiscount;
                $pricing['merchant_discount_total'] = $lineMerchantDiscount;
                $pricing['benefit_value'] = $benefitValue;

                $storewideWithTotal = $storewideDiscount;
                $storewideWithTotal['total'] = $lineStorewideDiscount;

                $merchantWithTotal = $merchantDiscount;
                $merchantWithTotal['total'] = $lineMerchantDiscount;

                $normalizedItems[] = [
                    'product' => $product,
                    'variation' => $variation,
                    'charged_quantity' => $chargedQuantity,
                    'delivered_quantity' => $deliveredQuantity,
                    'free_units' => $freeUnits,
                    'unit_price' => $unitPrice,
                    'base_unit_price' => $baseUnitPrice,
                    'discount_details' => $quantityDiscount,
                    'storewide_discount' => $storewideWithTotal,
                    'storewide_discount_total' => $lineStorewideDiscount,
                    'merchant_discount' => $merchantWithTotal,
                    'merchant_discount_total' => $lineMerchantDiscount,
                    'benefit_value' => $benefitValue,
                    'line_total' => $lineNetTotal,
                    'original_line_total' => $lineGrossPaid,
                    'pricing' => $pricing,
                ];
            }

            $subtotal = round($subtotal, 2);
            $benefitTotal = round($benefitTotal, 2);
            $grossPaidSubtotal = round($grossPaidSubtotal, 2);
            $storewideDiscountTotal = round($storewideDiscountTotal, 2);

            // Calculate totals
            $vatRate = $this->getVatRate();
            $tax = round($subtotal * $vatRate, 2);
            if ($data['shipping_type'] === 'pickup') {
                $shippingCost = 0.0;
            } elseif (array_key_exists('shipping_cost', $data) && $data['shipping_cost'] !== null) {
                $shippingCost = max(0, (float) $data['shipping_cost']);
            } else {
                $shippingCost = 30.0; // Basic default shipping cost
            }
            $shippingCost = round($shippingCost, 2);
            $discount = 0.0;
            $total = round($subtotal + $tax + $shippingCost, 2);

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

            $pricingSummary = [
                'gross_paid_subtotal' => $grossPaidSubtotal,
                'storewide_discount_total' => $storewideDiscountTotal,
                'merchant_discount_total' => $merchantDiscountTotal,
                'net_subtotal' => $subtotal,
                'tax' => $tax,
                'shipping_cost' => $shippingCost,
                'total' => $total,
                'benefit_value' => $benefitTotal,
                'total_value_with_benefits' => round($grossPaidSubtotal + $benefitTotal, 2),
            ];

            $sourceMetadata = array_merge([
                'initiator' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'role' => $user->role,
                ],
                'pricing_summary' => $pricingSummary,
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

            if ($selectedCarrier) {
                $serviceType = $data['carrier_service_type'] ?? $data['shipping_method'] ?? 'regular';
                $order->assignCarrier($selectedCarrier, $serviceType);
            }

            // Create order items and update stock
            $productsToRefresh = [];

            foreach ($normalizedItems as $itemData) {
                /** @var Product $product */
                $product = $itemData['product'];
                /** @var ProductVariation|null $variation */
                $variation = $itemData['variation'];
                $chargedQuantity = $itemData['charged_quantity'];
                $deliveredQuantity = $itemData['delivered_quantity'];
                $freeUnits = $itemData['free_units'];
                $unitPrice = $itemData['unit_price'];
                $itemTotal = $itemData['line_total'];
                $pricing = $itemData['pricing'];
                $benefitValue = $itemData['benefit_value'];

                $productSku = $product->sku;
                if ($variation && $variation->sku) {
                    $productSku = $variation->sku;
                }

                $productData = [
                    'description' => $product->description,
                    'images' => $product->images,
                    'pricing' => $pricing,
                ];

                $discountDetails = $itemData['discount_details'] ?? null;

                $productData['discount'] = $discountDetails;
                $productData['quantity_summary'] = [
                    'ordered' => $chargedQuantity,
                    'charged' => $chargedQuantity,
                    'free_units' => $freeUnits,
                    'delivered' => $deliveredQuantity,
                ];
                $productData['benefit_value'] = $benefitValue;
                $productData['storewide_discount'] = $itemData['storewide_discount'] ?? null;
                $productData['storewide_discount_total'] = $itemData['storewide_discount_total'] ?? 0.0;
                $productData['merchant_discount'] = $itemData['merchant_discount'] ?? null;
                $productData['merchant_discount_total'] = $itemData['merchant_discount_total'] ?? 0.0;

                if ($variation) {
                    $productData['variation'] = [
                        'id' => $variation->id,
                        'sku' => $variation->sku,
                        'attributes' => $variation->attributes,
                        'image' => $variation->image,
                    ];
                }

                $order->items()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_sku' => $productSku,
                    'quantity' => $deliveredQuantity,
                    'unit_price' => $unitPrice,
                    'total_price' => $itemTotal,
                    'product_data' => $productData,
                ]);

                // Update stock
                $product->decrement('stock_quantity', $deliveredQuantity);
                if ($variation && $variation->inventory !== null) {
                    $variation->decrement('inventory', $deliveredQuantity);
                }

                $productsToRefresh[$product->id] = $product;
            }

            foreach ($productsToRefresh as $productToRefresh) {
                $this->refreshProductVariationsSnapshot($productToRefresh);
            }

            DB::commit();

            if ($merchantUser) {
                $merchantUser->refreshOrderFinancials();
            }

            $order->load(['items.product', 'user', 'merchant', 'merchantCustomer', 'carrier']);

            if ($order->merchant_id
                && strtolower((string) $order->source) !== 'cashcow'
                && $order->payment_status !== 'paid'
                && (!is_string($order->invoice_url) || trim((string) $order->invoice_url) === '')
            ) {
                try {
                    $invoice = $ypayInvoiceService->createInvoiceForOrder($order);
                    $order->forceFill([
                        'invoice_provider' => 'ypay',
                        'invoice_url' => $invoice['invoice_url'],
                        'invoice_payload' => $invoice['payload'],
                    ])->save();
                } catch (\Throwable $exception) {
                    Log::warning('YPAY invoice generation failed during order creation', [
                        'order_id' => $order->id,
                        'merchant_id' => $order->merchant_id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            $this->notifyOrderEvent($order, 'order.created');

            return $this->createdResponse($order, 'Order created successfully');

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

    protected function determineUnitPrice(
        Product $product,
        ?ProductVariation $variation,
        ?int $merchantUserId
    ): array {
        $productPrice = max(0, (float) $product->getCurrentPrice());
        $merchantPrice = $this->resolveMerchantUnitPrice($product, $merchantUserId);

        if ($merchantPrice !== null && $merchantPrice < 0) {
            $merchantPrice = null;
        }

        $basePrice = $merchantPrice !== null ? $merchantPrice : $productPrice;

        $variationPrice = null;
        if ($variation && $variation->price !== null && is_numeric($variation->price)) {
            $candidateVariationPrice = (float) $variation->price;
            if ($candidateVariationPrice >= 0) {
                $variationPrice = $candidateVariationPrice;
            }
        }

        $unitPrice = $basePrice;
        if ($variationPrice !== null && $variationPrice < $basePrice) {
            $unitPrice = $variationPrice;
        }

        $unitPrice = round($unitPrice, 2);
        $productPrice = round($productPrice, 2);
        if ($merchantPrice !== null) {
            $merchantPrice = round($merchantPrice, 2);
        }
        if ($variationPrice !== null) {
            $variationPrice = round($variationPrice, 2);
        }

        return [
            'unit_price' => $unitPrice,
            'product_price' => $productPrice,
            'merchant_price' => $merchantPrice,
            'variation_price' => $variationPrice,
        ];
    }

    protected function getActiveQuantityDiscountForProduct(int $productId): ?Discount
    {
        if (array_key_exists($productId, $this->productQuantityDiscountCache)) {
            return $this->productQuantityDiscountCache[$productId];
        }

        $today = now()->toDateString();

        $discount = Discount::query()
            ->where('type', Discount::TYPE_QUANTITY)
            ->where('product_id', $productId)
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->orderByDesc('start_date')
            ->orderByDesc('created_at')
            ->first();

        if ($discount) {
            $status = $discount->computeStatus();
            if ($status === Discount::STATUS_EXPIRED) {
                $discount = null;
            } elseif ((int) $discount->buy_quantity <= 0 || (int) $discount->get_quantity <= 0) {
                $discount = null;
            }
        }

        $this->productQuantityDiscountCache[$productId] = $discount;

        return $discount;
    }

    protected function calculateQuantityDiscount(Product $product, int $quantity, float $unitPrice): array
    {
        $quantity = max(0, $quantity);
        $unitPrice = max(0.0, $unitPrice);

        $default = [
            'applied' => false,
            'discount_id' => null,
            'type' => null,
            'name' => null,
            'buy_quantity' => null,
            'get_quantity' => null,
            'free_per_bundle' => 0,
            'eligible_bundles' => 0,
            'free_units' => 0,
            'charged_quantity' => $quantity,
            'benefit_value' => 0.0,
            'delivered_units' => $quantity,
            'status' => null,
            'valid_from' => null,
            'valid_until' => null,
        ];

        if ($quantity <= 0 || $unitPrice <= 0) {
            return $default;
        }

        $discount = $this->getActiveQuantityDiscountForProduct($product->id);

        if (!$discount) {
            return $default;
        }

        $buyQuantity = (int) $discount->buy_quantity;
        $getQuantity = (int) $discount->get_quantity;

        if ($buyQuantity <= 0 || $getQuantity <= 0) {
            return $default;
        }

        $eligibleBundles = intdiv($quantity, $buyQuantity);
        $freePerBundle = max($getQuantity - $buyQuantity, 0);
        $freeUnits = $eligibleBundles * $freePerBundle;
        $chargedQuantity = $quantity;
        $benefitValue = round($freeUnits * $unitPrice, 2);
        $deliveredFromBundles = $eligibleBundles * $getQuantity;
        $remainingCharged = $quantity - ($eligibleBundles * $buyQuantity);
        $deliveredUnits = $deliveredFromBundles + $remainingCharged;

        return [
            'applied' => $freeUnits > 0,
            'discount_id' => $discount->id,
            'type' => $discount->type,
            'name' => $discount->name,
            'buy_quantity' => $buyQuantity,
            'get_quantity' => $getQuantity,
            'free_per_bundle' => $freePerBundle,
            'eligible_bundles' => $eligibleBundles,
            'free_units' => $freeUnits,
            'charged_quantity' => $chargedQuantity,
            'benefit_value' => $benefitValue,
            'delivered_units' => $deliveredUnits,
            'status' => $discount->computeStatus(),
            'valid_from' => $discount->start_date?->toDateString(),
            'valid_until' => $discount->end_date?->toDateString(),
        ];
    }

    protected function findMerchantSpecificDiscount(Product $product, ?int $merchantUserId): ?Discount
    {
        if (!$merchantUserId) {
            return null;
        }

        $cacheKey = implode(':', [
            $merchantUserId,
            $product->id,
            $product->category_id ?? 'null',
        ]);

        if (array_key_exists($cacheKey, $this->merchantSpecificDiscountCache)) {
            return $this->merchantSpecificDiscountCache[$cacheKey];
        }

        $today = now()->toDateString();

        $query = Discount::query()
            ->where('type', Discount::TYPE_MERCHANT)
            ->where('target_merchant_id', $merchantUserId)
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->whereNotNull('discount_percentage')
            ->where(function ($scopeQuery) use ($product) {
                $scopeQuery->where(function ($storeScope) {
                    $storeScope->whereNull('apply_scope')
                        ->orWhere('apply_scope', Discount::SCOPE_STORE);
                });

                $scopeQuery->orWhere(function ($productScope) use ($product) {
                    $productScope->where('apply_scope', Discount::SCOPE_PRODUCT)
                        ->where('product_id', $product->id);
                });

                if ($product->category_id) {
                    $categoryId = $product->category_id;
                    $scopeQuery->orWhere(function ($categoryScope) use ($categoryId) {
                        $categoryScope->where('apply_scope', Discount::SCOPE_CATEGORY)
                            ->where('category_id', $categoryId);
                    });
                }
            })
            ->orderByDesc(DB::raw(sprintf(
                "CASE WHEN apply_scope = '%s' THEN 3 WHEN apply_scope = '%s' THEN 2 ELSE 1 END",
                Discount::SCOPE_PRODUCT,
                Discount::SCOPE_CATEGORY
            )))
            ->orderByDesc('discount_percentage')
            ->orderByDesc('created_at');

        $discount = $query->first();

        if ($discount) {
            $status = $discount->computeStatus();
            if ($status === Discount::STATUS_EXPIRED) {
                $discount = null;
            }
        }

        $this->merchantSpecificDiscountCache[$cacheKey] = $discount;

        return $discount;
    }

    protected function applyMerchantSpecificDiscount(
        Product $product,
        ?int $merchantUserId,
        float $unitPrice
    ): array {
        $unitPrice = max(0.0, $unitPrice);
        $originalUnitPrice = round($unitPrice, 2);

        $default = [
            'applied' => false,
            'unit_price' => $originalUnitPrice,
            'original_unit_price' => $originalUnitPrice,
            'discount_per_unit' => 0.0,
            'percentage' => 0.0,
            'discount_id' => null,
            'apply_scope' => null,
            'name' => null,
            'product_id' => null,
            'category_id' => null,
            'target_merchant_id' => null,
            'start_date' => null,
            'end_date' => null,
        ];

        $discount = $this->findMerchantSpecificDiscount($product, $merchantUserId);

        if (!$discount || $discount->discount_percentage === null) {
            return $default;
        }

        $percentage = (float) $discount->discount_percentage;
        if ($percentage <= 0) {
            return $default;
        }
        $percentage = min($percentage, 100);

        $discountedPrice = round($unitPrice * (1 - ($percentage / 100)), 2);
        if ($discountedPrice < 0) {
            $discountedPrice = 0.0;
        }

        $discountPerUnit = round($originalUnitPrice - $discountedPrice, 2);
        if ($discountPerUnit <= 0) {
            return $default;
        }

        return [
            'applied' => true,
            'unit_price' => $discountedPrice,
            'original_unit_price' => $originalUnitPrice,
            'discount_per_unit' => $discountPerUnit,
            'percentage' => $percentage,
            'discount_id' => $discount->id,
            'apply_scope' => $discount->apply_scope ?? Discount::SCOPE_STORE,
            'name' => $discount->name,
            'product_id' => $discount->product_id,
            'category_id' => $discount->category_id,
            'target_merchant_id' => $discount->target_merchant_id,
            'start_date' => $discount->start_date?->toDateString(),
            'end_date' => $discount->end_date?->toDateString(),
        ];
    }

    protected function findStorewideDiscount(Product $product, ?int $merchantUserId): ?Discount
    {
        $cacheKey = implode(':', [
            $merchantUserId ?? 'null',
            $product->id,
            $product->category_id ?? 'null',
        ]);

        if (array_key_exists($cacheKey, $this->storewideDiscountCache)) {
            return $this->storewideDiscountCache[$cacheKey];
        }

        $today = now()->toDateString();

        $query = Discount::query()
            ->where('type', Discount::TYPE_STOREWIDE)
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->whereNotNull('discount_percentage')
            ->where(function ($targetQuery) use ($merchantUserId) {
                $targetQuery->whereNull('target_merchant_id');

                if ($merchantUserId) {
                    $targetQuery->orWhere('target_merchant_id', $merchantUserId);
                }
            })
            ->where(function ($scopeQuery) use ($product) {
                $scopeQuery->where(function ($storeScope) {
                    $storeScope->whereNull('apply_scope')
                        ->orWhere('apply_scope', Discount::SCOPE_STORE);
                });

                $scopeQuery->orWhere(function ($productScope) use ($product) {
                    $productScope->where('apply_scope', Discount::SCOPE_PRODUCT)
                        ->where('product_id', $product->id);
                });

                if ($product->category_id) {
                    $categoryId = $product->category_id;
                    $scopeQuery->orWhere(function ($categoryScope) use ($categoryId) {
                        $categoryScope->where('apply_scope', Discount::SCOPE_CATEGORY)
                            ->where('category_id', $categoryId);
                    });
                }
            })
            ->orderByDesc(DB::raw(sprintf(
                "CASE WHEN apply_scope = '%s' THEN 3 WHEN apply_scope = '%s' THEN 2 ELSE 1 END",
                Discount::SCOPE_PRODUCT,
                Discount::SCOPE_CATEGORY
            )))
            ->orderByDesc('discount_percentage')
            ->orderByDesc('created_at');

        $discount = $query->first();

        if ($discount) {
            $status = $discount->computeStatus();
            if ($status === Discount::STATUS_EXPIRED) {
                $discount = null;
            }
        }

        $this->storewideDiscountCache[$cacheKey] = $discount;

        return $discount;
    }

    protected function applyStorewideDiscount(
        Product $product,
        ?int $merchantUserId,
        float $unitPrice
    ): array {
        $unitPrice = max(0.0, $unitPrice);
        $originalUnitPrice = round($unitPrice, 2);

        $default = [
            'applied' => false,
            'unit_price' => $originalUnitPrice,
            'original_unit_price' => $originalUnitPrice,
            'discount_per_unit' => 0.0,
            'percentage' => 0.0,
            'discount_id' => null,
            'apply_scope' => null,
            'name' => null,
            'product_id' => null,
            'category_id' => null,
            'target_merchant_id' => null,
            'start_date' => null,
            'end_date' => null,
        ];

        $discount = $this->findStorewideDiscount($product, $merchantUserId);

        if (!$discount || $discount->discount_percentage === null) {
            return $default;
        }

        $percentage = (float) $discount->discount_percentage;
        if ($percentage <= 0) {
            return $default;
        }
        $percentage = min($percentage, 100);

        $discountedPrice = round($unitPrice * (1 - ($percentage / 100)), 2);
        if ($discountedPrice < 0) {
            $discountedPrice = 0.0;
        }

        $discountPerUnit = round($originalUnitPrice - $discountedPrice, 2);
        if ($discountPerUnit <= 0) {
            return $default;
        }

        return [
            'applied' => true,
            'unit_price' => $discountedPrice,
            'original_unit_price' => $originalUnitPrice,
            'discount_per_unit' => $discountPerUnit,
            'percentage' => $percentage,
            'discount_id' => $discount->id,
            'apply_scope' => $discount->apply_scope ?? Discount::SCOPE_STORE,
            'name' => $discount->name,
            'product_id' => $discount->product_id,
            'category_id' => $discount->category_id,
            'target_merchant_id' => $discount->target_merchant_id,
            'start_date' => $discount->start_date?->toDateString(),
            'end_date' => $discount->end_date?->toDateString(),
        ];
    }

    protected function refreshProductVariationsSnapshot(Product $product): void
    {
        $collection = $product->productVariations()->orderBy('id')->get();

        $snapshot = $collection->map(function (ProductVariation $variation) {
            return [
                'id' => $variation->id,
                'sku' => $variation->sku,
                'inventory' => (int) $variation->inventory,
                'price' => $variation->price !== null ? (float) $variation->price : null,
                'attributes' => $variation->attributes ?? [],
                'image' => $variation->image,
            ];
        })->values()->toArray();

        $product->forceFill([
            'variations' => $snapshot,
        ])->save();

        $product->setRelation('productVariations', $collection);
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
            'payment_status' => 'sometimes|in:pending,paid,failed,refunded,cancelled,canceled',
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

        if (array_key_exists('status', $updates) && is_string($updates['status'])) {
            $normalizedStatus = strtolower($updates['status']);
            if ($normalizedStatus === 'canceled') {
                $normalizedStatus = 'cancelled';
            }
            $updates['status'] = $normalizedStatus;
        }

        if (array_key_exists('payment_status', $updates) && is_string($updates['payment_status'])) {
            $normalizedPaymentStatus = strtolower($updates['payment_status']);
            if ($normalizedPaymentStatus === 'canceled') {
                $normalizedPaymentStatus = 'cancelled';
            }
            $updates['payment_status'] = $normalizedPaymentStatus;
        }

        $previousStatus = $order->status;

        $order->update($updates);

        // Update timestamps based on status
        if ($request->has('status')) {
            if ($request->status === 'shipped' && !$order->shipped_at) {
                $order->update(['shipped_at' => now()]);
            } elseif ($request->status === 'delivered' && !$order->delivered_at) {
                $order->update(['delivered_at' => now()]);
            }
        }

        $order->load(['items.product', 'user', 'merchant', 'merchantCustomer', 'carrier']);

        if ($previousStatus !== Order::STATUS_SHIPPED && $order->status === Order::STATUS_SHIPPED) {
            $this->notifyOrderEvent($order, 'order.shipped');
        }

        return $this->successResponse($order, 'Order updated successfully');
    }

    /**
     * Update order items and recalculate totals.
     */
    public function updateItems(Request $request, string $id)
    {
        $order = Order::with('items')->findOrFail($id);
        $user = $request->user();

        // Permissions: admins always, merchants only their orders, agents according to scope
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

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.variation_id' => 'nullable|integer|exists:product_variations,id',
            'items.*.quantity' => 'required|integer|min:1',
            'shipping_cost' => 'nullable|numeric|min:0',
        ]);

        DB::beginTransaction();

        try {
            // Restore previous stock
            foreach ($order->items as $existingItem) {
                if ($existingItem->product_id) {
                    $product = Product::find($existingItem->product_id);
                    if ($product) {
                        $product->increment('stock_quantity', $existingItem->quantity);
                    }
                }

                $existingVariationId = $existingItem->product_data['variation']['id'] ?? null;
                if ($existingVariationId) {
                    $variation = ProductVariation::find($existingVariationId);
                    if ($variation && $variation->inventory !== null) {
                        $variation->increment('inventory', $existingItem->quantity);
                    }
                }
            }

            $order->items()->delete();

            $subtotal = 0.0;
            $productsToRefresh = [];

            foreach ($validated['items'] as $itemData) {
                /** @var Product $product */
                $product = Product::findOrFail($itemData['product_id']);
                $quantity = (int) $itemData['quantity'];
                $variation = null;

                if (!empty($itemData['variation_id'])) {
                    $variation = ProductVariation::find($itemData['variation_id']);
                    if (!$variation || $variation->product_id !== $product->id) {
                        throw ValidationException::withMessages([
                            'items' => ['Variation does not belong to the selected product'],
                        ]);
                    }
                }

                if ($product->stock_quantity !== null && $product->stock_quantity < $quantity) {
                    throw ValidationException::withMessages([
                        'items' => ["Insufficient stock for {$product->name}"],
                    ]);
                }

                if ($variation && $variation->inventory !== null && $variation->inventory < $quantity) {
                    throw ValidationException::withMessages([
                        'items' => ["Insufficient variation stock for {$product->name}"],
                    ]);
                }

                $unitPrice = (float) ($variation && $variation->price !== null
                    ? $variation->price
                    : ($product->sale_price ?? $product->price ?? 0));
                $lineTotal = round($unitPrice * $quantity, 2);

                $productData = [
                    'description' => $product->description,
                    'images' => $product->images,
                ];

                if ($variation) {
                    $productData['variation'] = [
                        'id' => $variation->id,
                        'sku' => $variation->sku,
                        'attributes' => $variation->attributes,
                        'image' => $variation->image,
                    ];
                }

                $order->items()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_sku' => $variation?->sku ?? $product->sku,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_price' => $lineTotal,
                    'product_data' => $productData,
                ]);

                $product->decrement('stock_quantity', $quantity);
                if ($variation && $variation->inventory !== null) {
                    $variation->decrement('inventory', $quantity);
                }

                $productsToRefresh[$product->id] = $product;
                $subtotal += $lineTotal;
            }

            foreach ($productsToRefresh as $productToRefresh) {
                $this->refreshProductVariationsSnapshot($productToRefresh);
            }

            $subtotal = round($subtotal, 2);
            $vatRate = $this->getVatRate();
            $tax = round($subtotal * $vatRate, 2);
            $shippingCost = array_key_exists('shipping_cost', $validated)
                ? max(0, (float) $validated['shipping_cost'])
                : (float) ($order->shipping_cost ?? 0);
            $shippingCost = round($shippingCost, 2);
            $discount = (float) ($order->discount ?? 0);
            $total = round($subtotal + $tax + $shippingCost - $discount, 2);

            $order->update([
                'subtotal' => $subtotal,
                'tax' => $tax,
                'shipping_cost' => $shippingCost,
                'total' => $total,
            ]);

            $order->load(['items.product', 'user', 'merchant', 'merchantCustomer', 'carrier']);

            DB::commit();

            return $this->successResponse($order, 'Order items updated successfully');
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Failed to update order items', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, string $id)
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

    public function adminSummary(Request $request)
    {
        $user = $request->user();
        if (!$user->hasRole('admin')) {
            return $this->errorResponse('Unauthorized', 403);
        }

        $pendingOrders = Order::where('status', 'pending')->count();
        $processingOrders = Order::where('status', 'processing')->count();
        $shippedOrders = Order::where('status', 'shipped')->count();
        $deliveredOrders = Order::where('status', 'delivered')->count();

        $outstandingQuery = Order::query()
            ->where(function ($query) {
                $query->whereNull('payment_status')
                    ->orWhere('payment_status', '!=', 'paid');
            })
            ->whereNotIn('status', ['cancelled', 'canceled']);
        $outstandingTotal = (float) (clone $outstandingQuery)->sum('total');
        $outstandingCount = (clone $outstandingQuery)->count();

        $paidOrdersQuery = Order::where('payment_status', 'paid');
        $paidOrdersTotal = (float) (clone $paidOrdersQuery)->sum('total');
        $paidOrdersCount = (clone $paidOrdersQuery)->count();

        $startOfCurrentMonth = Carbon::now()->startOfMonth();
        $endOfCurrentMonth = $startOfCurrentMonth->copy()->endOfMonth();

        $currentMonthOutstandingQuery = (clone $outstandingQuery)->whereBetween(
            'created_at',
            [$startOfCurrentMonth, $endOfCurrentMonth]
        );
        $currentMonthOutstandingTotal = (float) (clone $currentMonthOutstandingQuery)->sum('total');
        $currentMonthOutstandingCount = (clone $currentMonthOutstandingQuery)->count();

        $currentMonthPaidQuery = (clone $paidOrdersQuery)->whereBetween(
            'created_at',
            [$startOfCurrentMonth, $endOfCurrentMonth]
        );
        $currentMonthPaidTotal = (float) (clone $currentMonthPaidQuery)->sum('total');
        $currentMonthPaidCount = (clone $currentMonthPaidQuery)->count();

        $historicalPaidQuery = Order::query()
            ->where('created_at', '<', $startOfCurrentMonth)
            ->where('payment_status', 'paid');
        $historicalPaidTotal = (float) (clone $historicalPaidQuery)->sum('total');
        $historicalPaidCount = (clone $historicalPaidQuery)->count();

        $historicalUnpaidQuery = Order::query()
            ->where('created_at', '<', $startOfCurrentMonth)
            ->where(function ($query) {
                $query->whereNull('payment_status')
                    ->orWhere('payment_status', '!=', 'paid');
            })
            ->whereNotIn('status', ['cancelled', 'canceled']);
        $historicalUnpaidTotal = (float) (clone $historicalUnpaidQuery)->sum('total');
        $historicalUnpaidCount = (clone $historicalUnpaidQuery)->count();

        return $this->successResponse([
            'pending_orders' => $pendingOrders,
            'processing_orders' => $processingOrders,
            'shipped_orders' => $shippedOrders,
            'delivered_orders' => $deliveredOrders,
            'outstanding_balance' => round($outstandingTotal, 2),
            'historical_orders_summary' => [
                'paid_total' => round($historicalPaidTotal, 2),
                'paid_count' => $historicalPaidCount,
                'unpaid_total' => round($historicalUnpaidTotal, 2),
                'unpaid_count' => $historicalUnpaidCount,
            ],
            'payment_overview' => [
                'outstanding_total' => round($currentMonthOutstandingTotal, 2),
                'outstanding_count' => $currentMonthOutstandingCount,
                'paid_total' => round($currentMonthPaidTotal, 2),
                'paid_count' => $currentMonthPaidCount,
                'all_time_outstanding_total' => round($outstandingTotal, 2),
                'all_time_outstanding_count' => $outstandingCount,
                'all_time_paid_total' => round($paidOrdersTotal, 2),
                'all_time_paid_count' => $paidOrdersCount,
                'period' => [
                    'label' => 'current_month',
                    'start' => $startOfCurrentMonth->toDateString(),
                    'end' => $endOfCurrentMonth->toDateString(),
                ],
            ],
        ]);
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
                'items' => $order->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'product_id' => $item->product_id,
                        'product_name' => $item->product_name,
                        'product_sku' => $item->product_sku,
                        'quantity' => $item->quantity,
                        'price' => $item->unit_price,
                        'total' => $item->total_price,
                    ];
                }),
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
        $previousShippingCost = (float) ($order->shipping_cost ?? 0);
        $requestedShippingCost = array_key_exists('shipping_cost', $validated) && $validated['shipping_cost'] !== null
            ? (float) $validated['shipping_cost']
            : null;

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

            $order = $order->fresh(['items', 'carrier']);
            $shippingCost = $order->calculateShippingCost();

            if ($shippingCost <= 0) {
                $shippingCost = $requestedShippingCost ?? $previousShippingCost;
            }

            $this->recalculateOrderTotals($order, $shippingCost);
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

        $order = $order->fresh(['items', 'carrier', 'user', 'merchant']);
        $merchant?->refresh();

        $finalShippingCost = $requestedShippingCost ?? (float) ($order->shipping_cost ?? $previousShippingCost);
        $this->recalculateOrderTotals($order, $finalShippingCost);

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

    protected function recalculateOrderTotals(Order $order, ?float $shippingCostOverride = null): void
    {
        $shippingCost = $shippingCostOverride !== null
            ? (float) $shippingCostOverride
            : (float) ($order->shipping_cost ?? 0);

        $subtotal = (float) ($order->subtotal ?? 0);
        $tax = (float) ($order->tax ?? 0);
        $discount = (float) ($order->discount ?? 0);

        $order->update([
            'shipping_cost' => $shippingCost,
            'total' => $subtotal + $tax + $shippingCost - $discount,
        ]);
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

    protected function notifyOrderEvent(Order $order, string $eventKey): void
    {
        try {
            $payload = $this->buildOrderEmailPayload($order);
            $recipientList = $this->resolveOrderRecipients($order);
            $recipients = [];

            if (!empty($recipientList)) {
                $recipients['to'] = $recipientList;
            }

            $this->emailTemplateService->send($eventKey, $payload, $recipients);
        } catch (\Throwable $exception) {
            Log::warning('Failed to send order email notification', [
                'event_key' => $eventKey,
                'order_id' => $order->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    protected function buildOrderEmailPayload(Order $order): array
    {
        $order->loadMissing(['items.product', 'user', 'merchant', 'merchantCustomer', 'carrier']);
        $customerDetails = $this->resolveOrderCustomerDetails($order);

        return [
            'order' => [
                'id' => $order->id,
                'number' => $order->order_number,
                'status' => $order->status,
                'total' => $order->total,
                'shipping_cost' => $order->shipping_cost,
                'created_at' => optional($order->created_at)->toIso8601String(),
            ],
            'customer' => [
                'name' => $customerDetails['name'],
                'email' => $customerDetails['email'],
                'phone' => data_get($order->shipping_address, 'phone')
                    ?? data_get($order->billing_address, 'phone')
                    ?? optional($order->merchantCustomer)->phone,
            ],
            'shipping' => [
                'type' => $order->shipping_type,
                'method' => $order->shipping_method,
                'address' => $order->shipping_address,
            ],
            'shipment' => [
                'carrier' => $order->shipping_company ?? optional($order->carrier)->name,
                'tracking_number' => $order->tracking_number,
                'shipped_at' => optional($order->shipped_at)->toIso8601String(),
            ],
            'merchant' => [
                'name' => optional($order->merchant)->name,
                'email' => optional($order->merchant)->email,
            ],
            'items' => $order->items->map(function ($item) {
                return [
                    'name' => $item->product_name,
                    'sku' => $item->product_sku,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'total_price' => $item->total_price,
                ];
            })->toArray(),
        ];
    }

    protected function resolveOrderRecipients(Order $order): array
    {
        $customerDetails = $this->resolveOrderCustomerDetails($order);

        if (!empty($customerDetails['email'])) {
            return [[
                'email' => $customerDetails['email'],
                'name' => $customerDetails['name'],
            ]];
        }

        if ($order->merchant && $order->merchant->email) {
            return [[
                'email' => $order->merchant->email,
                'name' => $order->merchant->name,
            ]];
        }

        return [];
    }

    protected function resolveOrderCustomerDetails(Order $order): array
    {
        $name = data_get($order->shipping_address, 'name')
            ?? data_get($order->shipping_address, 'contact_name')
            ?? data_get($order->billing_address, 'name')
            ?? optional($order->merchantCustomer)->name
            ?? optional($order->user)->name;

        $email = data_get($order->shipping_address, 'email')
            ?? data_get($order->billing_address, 'email')
            ?? optional($order->merchantCustomer)->email
            ?? optional($order->user)->email;

        return [
            'name' => $name,
            'email' => $email,
        ];
    }

    /**
     * Resolve VAT rate from system settings (shipping_pricing.vat_rate) with a default of 17%.
     */
    protected function getVatRate(): float
    {
        $setting = SystemSetting::where('key', 'shipping_pricing')->first();
        $value = is_array($setting?->value) ? $setting->value : [];
        $vat = $value['vat_rate'] ?? null;

        if (is_numeric($vat)) {
            $rate = (float) $vat;
            if ($rate < 0) {
                return 0.0;
            }
            if ($rate > 1) {
                return 1.0;
            }
            return $rate;
        }

        return 0.17;
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
