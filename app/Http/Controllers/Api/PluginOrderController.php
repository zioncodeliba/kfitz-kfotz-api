<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\MerchantCustomer;
use App\Models\MerchantSite;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PluginOrderController extends Controller
{
    use ApiResponse;

    public function store(Request $request)
    {
        $user = $request->user();

        if (!$user->hasRole('merchant')) {
            return $this->errorResponse('Insufficient permissions', 403);
        }

        $data = $request->validate([
            'site_url' => 'required|string|max:255',
            'external_id' => 'nullable|string|max:255',
            'customer' => 'required|array',
            'customer.name' => 'required|string|max:255',
            'customer.phone' => 'required|string|max:50',
            'customer.email' => 'nullable|email|max:255',
            'customer.address' => 'required|array',
            'customer.address.line1' => 'required|string|max:255',
            'customer.address.city' => 'required|string|max:255',
            'customer.address.state' => 'nullable|string|max:255',
            'customer.address.zip' => 'nullable|string|max:50',
            'customer.address.country' => 'nullable|string|max:255',
            'customer.notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.sku' => 'nullable|string|max:100',
            'items.*.quantity' => 'required|numeric|min:0.0001',
            'items.*.variation_id' => 'nullable|integer|exists:product_variations,id',
            'totals' => 'nullable|array',
            'totals.subtotal' => 'nullable|numeric|min:0',
            'totals.tax' => 'nullable|numeric|min:0',
            'totals.shipping_cost' => 'nullable|numeric|min:0',
            'totals.discount' => 'nullable|numeric|min:0',
            'totals.total' => 'nullable|numeric|min:0',
            'shipping' => 'nullable|array',
            'shipping.method' => 'nullable|string|max:100',
            'shipping.type' => 'nullable|string|max:50',
            'shipping.cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $site = MerchantSite::where('site_url', trim($data['site_url']))->first();

        if (!$site) {
            return $this->errorResponse('Plugin site not found', 404);
        }

        if ($site->user_id !== $user->id) {
            return $this->errorResponse('Site does not belong to the authenticated merchant', 403);
        }

        $merchantUser = $site->user;
        if (!$merchantUser) {
            return $this->errorResponse('Merchant user not found for the provided site', 422);
        }

        $merchantProfile = $merchantUser->merchant;

        try {
            DB::beginTransaction();

            $items = collect($data['items']);

            $productIds = $items->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->values();
            $products = Product::with('productVariations')->whereIn('id', $productIds)->get()->keyBy('id');

            if ($products->count() !== $productIds->count()) {
                DB::rollBack();
                return $this->errorResponse('One or more products were not found for this merchant.', 422);
            }

            $computedSubtotal = 0;
            $normalizedItems = [];

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $productId = (int) ($item['product_id'] ?? 0);
                $product = $products->get($productId);
                if (!$product) {
                    DB::rollBack();
                    return $this->errorResponse('One or more products were not found for this merchant.', 422);
                }

                if (!$this->isProductAvailableForPluginSite($product, $site->id)) {
                    DB::rollBack();
                    return $this->errorResponse(sprintf(
                        'Product %s is not available for this plugin site.',
                        $product->name
                    ), 422);
                }

                $quantity = max((float) ($item['quantity'] ?? 0), 0.0001);

                if ($product->stock_quantity !== null && $product->stock_quantity < $quantity) {
                    DB::rollBack();
                    return $this->errorResponse(sprintf('Insufficient stock for product: %s', $product->name), 400);
                }

                $variation = null;
                if (array_key_exists('variation_id', $item) && $item['variation_id'] !== null) {
                    $variationId = (int) $item['variation_id'];
                    $variation = $product->productVariations->firstWhere('id', $variationId);

                    if (!$variation) {
                        DB::rollBack();
                        return $this->errorResponse('Selected variation does not belong to the chosen product.', 422);
                    }

                    if ($variation->inventory !== null && $variation->inventory < $quantity) {
                        DB::rollBack();
                        return $this->errorResponse(sprintf('Insufficient stock for selected variation of product: %s', $product->name), 400);
                    }
                }

                if ($product->productVariations->isNotEmpty() && !$variation) {
                    DB::rollBack();
                    return $this->errorResponse(sprintf('Product %s requires selecting a variation.', $product->name), 422);
                }

                $pluginPrice = $this->resolvePluginSiteUnitPrice($product, $site->id);
                $merchantPrice = $this->resolveMerchantUnitPrice($product, $merchantUser->id);
                $pricing = $this->determinePluginUnitPrice($product, $variation, $pluginPrice, $merchantPrice);
                $unitPrice = $pricing['unit_price'];
                $totalPrice = $unitPrice * $quantity;

                $computedSubtotal += $totalPrice;

                $normalizedItems[] = [
                    'product' => $product,
                    'variation' => $variation,
                    'quantity' => $quantity,
                    'provided_sku' => $item['sku'] ?? null,
                    'unit_price' => $unitPrice,
                    'item_total' => $totalPrice,
                    'pricing' => $pricing,
                ];
            }

            $totalsInput = $data['totals'] ?? [];
            $shippingInput = is_array($data['shipping'] ?? null) ? $data['shipping'] : [];

            $subtotal = (float) ($totalsInput['subtotal'] ?? $computedSubtotal);
            if ($subtotal < 0) {
                $subtotal = 0;
            }

            $tax = (float) ($totalsInput['tax'] ?? round($subtotal * 0.17, 2));
            if ($tax < 0) {
                $tax = 0;
            }

            $discount = (float) ($totalsInput['discount'] ?? 0);
            if ($discount < 0) {
                $discount = 0;
            }

            $shippingContext = $this->resolveShippingContext($merchantProfile, $totalsInput, $shippingInput);
            $shippingCost = $shippingContext['cost'];

            $total = (float) ($totalsInput['total'] ?? ($subtotal + $tax + $shippingCost - $discount));
            if ($total <= 0) {
                $total = $subtotal + $tax + $shippingCost - $discount;
            }

            $merchantCustomer = $this->syncMerchantCustomer($merchantUser->id, $data['customer']);

            $order = Order::create([
                'user_id' => $merchantUser->id,
                'merchant_id' => $merchantUser->id,
                'merchant_customer_id' => $merchantCustomer->id,
                'merchant_site_id' => $site->id,
                'status' => Order::STATUS_PENDING,
                'payment_status' => 'pending',
                'source' => 'plugin',
                'source_reference' => $data['external_id'] ?? null,
                'source_metadata' => [
                    'site_url' => $site->site_url,
                    'site_name' => $site->name,
                    'plugin_site' => [
                        'id' => $site->id,
                        'site_url' => $site->site_url,
                        'name' => $site->name,
                        'platform' => $site->platform,
                    ],
                    'customer' => [
                        'name' => $data['customer']['name'],
                        'phone' => $data['customer']['phone'],
                        'email' => $data['customer']['email'] ?? null,
                        'address' => $data['customer']['address'],
                        'notes' => $data['customer']['notes'] ?? null,
                    ],
                    'shipping' => [
                        'type' => $shippingContext['type'],
                        'method' => $shippingContext['method'],
                        'cost' => $shippingCost,
                    ],
                    'initiator' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'role' => $user->role,
                    ],
                ],
                'shipping_address' => $data['customer']['address'],
                'billing_address' => $data['customer']['address'],
                'shipping_type' => $shippingContext['type'],
                'shipping_method' => $shippingContext['method'],
                'subtotal' => $subtotal,
                'tax' => $tax,
                'shipping_cost' => $shippingCost,
                'discount' => $discount,
                'total' => $total,
                'notes' => $data['notes'] ?? null,
            ]);

            $productsToRefresh = [];

            foreach ($normalizedItems as $itemData) {
                /** @var Product $product */
                $product = $itemData['product'];
                /** @var ProductVariation|null $variation */
                $variation = $itemData['variation'];
                $quantity = $itemData['quantity'];
                $unitPrice = $itemData['unit_price'];
                $itemTotal = $itemData['item_total'];
                $providedSku = $itemData['provided_sku'];
                $pricing = $itemData['pricing'];

                $productSku = $providedSku ?? ($variation?->sku ?? $product->sku);

                $productData = [
                    'description' => $product->description,
                    'images' => $product->images,
                    'pricing' => $pricing,
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
                    'product_sku' => $productSku,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_price' => $itemTotal,
                    'product_data' => $productData,
                ]);

                $product->decrement('stock_quantity', $quantity);
                if ($variation && $variation->inventory !== null) {
                    $variation->decrement('inventory', $quantity);
                }

                $productsToRefresh[$product->id] = $product;
            }

            foreach ($productsToRefresh as $productToRefresh) {
                $this->refreshProductVariationsSnapshot($productToRefresh);
            }

            DB::commit();

            $merchantUser->refreshOrderFinancials();

            return $this->createdResponse(
                $order->load(['items', 'merchant', 'user']),
                'Order created from plugin successfully'
            );
        } catch (\Throwable $exception) {
            DB::rollBack();
            report($exception);

            return $this->errorResponse('Failed to create order from plugin', 500);
        }
    }

    protected function resolveShippingContext(?Merchant $merchant, array $totalsInput, array $shippingInput): array
    {
        $type = isset($shippingInput['type']) ? strtolower(trim((string) $shippingInput['type'])) : null;
        if (!in_array($type, ['delivery', 'pickup'], true)) {
            $type = 'delivery';
        }

        $method = isset($shippingInput['method']) ? strtolower(trim((string) $shippingInput['method'])) : null;
        $method = $this->normalizeShippingMethod($method, $type);

        $cost = null;

        if (array_key_exists('cost', $shippingInput) && is_numeric($shippingInput['cost'])) {
            $cost = max(0, (float) $shippingInput['cost']);
        }

        if ($cost === null && array_key_exists('shipping_cost', $totalsInput) && $totalsInput['shipping_cost'] !== null) {
            $candidate = $totalsInput['shipping_cost'];
            if (is_numeric($candidate)) {
                $cost = max(0, (float) $candidate);
            }
        }

        if ($type === 'pickup') {
            $cost = 0.0;
        }

        if ($cost === null && $merchant) {
            $cost = $this->resolveShippingCostFromSettings($merchant, $method, $type);
        }

        if ($cost === null) {
            $cost = 0.0;
        }

        return [
            'type' => $type,
            'method' => $method,
            'cost' => $cost,
        ];
    }

    protected function normalizeShippingMethod(?string $method, string $type): string
    {
        if ($type === 'pickup') {
            return 'pickup';
        }

        $normalized = match ($method) {
            'pickup', 'self', 'self_pickup', 'collection' => 'pickup',
            'express', 'fast', 'same-day', 'overnight' => 'express',
            'regular', 'standard', 'delivery', 'courier', 'door', 'door_to_door' => 'regular',
            default => null,
        };

        if ($normalized) {
            return $normalized;
        }

        return 'regular';
    }

    protected function resolveShippingCostFromSettings(Merchant $merchant, ?string $serviceType, ?string $shippingType): ?float
    {
        $settings = $merchant->shipping_settings ?? [];
        $units = $settings['shipping_units'] ?? [];

        $unitsCollection = collect(is_array($units) ? $units : [])
            ->filter(function ($unit) {
                return is_array($unit) && isset($unit['price']) && is_numeric($unit['price']);
            });

        $match = null;

        if ($serviceType) {
            $match = $unitsCollection->first(function ($unit) use ($serviceType) {
                return isset($unit['service_type'])
                    && strcasecmp((string) $unit['service_type'], $serviceType) === 0;
            });
        }

        if (!$match && $shippingType) {
            $match = $unitsCollection->first(function ($unit) use ($shippingType) {
                return isset($unit['destination'])
                    && strcasecmp((string) $unit['destination'], $shippingType) === 0;
            });
        }

        if (!$match) {
            $match = $unitsCollection->first();
        }

        if ($match && isset($match['price']) && is_numeric($match['price'])) {
            return max(0, (float) $match['price']);
        }

        if (isset($settings['default_shipping_price']) && is_numeric($settings['default_shipping_price'])) {
            return max(0, (float) $settings['default_shipping_price']);
        }

        if (isset($settings['default_shipping_cost']) && is_numeric($settings['default_shipping_cost'])) {
            return max(0, (float) $settings['default_shipping_cost']);
        }

        return null;
    }

    protected function syncMerchantCustomer(int $merchantUserId, array $customerData): MerchantCustomer
    {
        $name = trim($customerData['name']);
        $email = isset($customerData['email']) ? strtolower(trim($customerData['email'])) : null;
        $email = $email === '' ? null : $email;
        $phone = isset($customerData['phone']) ? $this->normalizePhone($customerData['phone']) : null;

        $query = MerchantCustomer::where('merchant_user_id', $merchantUserId);

        if ($email && $phone) {
            $query->where('email', $email)->where('phone', $phone);
        } else {
            if ($email) {
                $query->where('email', $email);
            }

            if ($phone) {
                $query->where('phone', $phone);
            }
        }

        $existingCustomer = $query->first();

        $payload = [
            'merchant_user_id' => $merchantUserId,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'notes' => $customerData['notes'] ?? null,
            'address' => $customerData['address'] ?? null,
        ];

        if ($existingCustomer) {
            $updates = [];

            foreach (['name', 'email', 'phone'] as $attribute) {
                $value = $payload[$attribute];
                if ($value !== null && $existingCustomer->{$attribute} !== $value) {
                    $updates[$attribute] = $value;
                }
            }

            if (isset($payload['notes']) && $payload['notes'] !== null && $existingCustomer->notes !== $payload['notes']) {
                $updates['notes'] = $payload['notes'];
            }

            if (is_array($payload['address']) && $existingCustomer->address != $payload['address']) {
                $updates['address'] = $payload['address'];
            }

            if (!empty($updates)) {
                $existingCustomer->fill($updates)->save();
            }

            return $existingCustomer;
        }

        return MerchantCustomer::create($payload);
    }

    protected function normalizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);

        return $digits !== '' ? $digits : null;
    }

    /**
     * Pick a unit price dedicated to a specific plugin site when available.
     */
    protected function resolvePluginSiteUnitPrice(Product $product, int $siteId): ?float
    {
        if ($siteId <= 0) {
            return null;
        }

        $prices = $product->plugin_site_prices;
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

            $entrySiteId = $entry['site_id'] ?? null;
            if ($entrySiteId === null) {
                continue;
            }

            if ((int) $entrySiteId !== $siteId) {
                continue;
            }

            if (array_key_exists('is_enabled', $entry) && !$entry['is_enabled']) {
                return null;
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
     * Determine whether a product is available for orders coming from a plugin site.
     */
    protected function isProductAvailableForPluginSite(Product $product, int $siteId): bool
    {
        if ($siteId <= 0) {
            return true;
        }

        $prices = $product->plugin_site_prices;
        if (!is_array($prices) || empty($prices)) {
            return true;
        }

        foreach ($prices as $entry) {
            if (is_object($entry)) {
                $entry = (array) $entry;
            }
            if (!is_array($entry)) {
                continue;
            }

            $entrySiteId = $entry['site_id'] ?? null;
            if ($entrySiteId === null) {
                continue;
            }

            if ((int) $entrySiteId !== $siteId) {
                continue;
            }

            if (!array_key_exists('is_enabled', $entry)) {
                return true;
            }

            return filter_var($entry['is_enabled'], FILTER_VALIDATE_BOOLEAN) !== false;
        }

        return true;
    }

    protected function resolveMerchantUnitPrice(Product $product, int $merchantUserId): ?float
    {
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

    protected function determinePluginUnitPrice(
        Product $product,
        ?ProductVariation $variation,
        ?float $pluginPrice,
        ?float $merchantPrice
    ): array {
        $productPrice = max(0, (float) $product->getCurrentPrice());

        if ($pluginPrice !== null && $pluginPrice < 0) {
            $pluginPrice = null;
        }

        if ($merchantPrice !== null && $merchantPrice < 0) {
            $merchantPrice = null;
        }

        $basePrice = $pluginPrice ?? $merchantPrice ?? $productPrice;

        $variationPrice = null;
        if ($variation && $variation->price !== null && is_numeric($variation->price)) {
            $candidate = (float) $variation->price;
            if ($candidate >= 0) {
                $variationPrice = $candidate;
            }
        }

        $unitPrice = $basePrice;
        if ($variationPrice !== null && $variationPrice < $basePrice) {
            $unitPrice = $variationPrice;
        }

        $unitPrice = round($unitPrice, 2);
        $productPrice = round($productPrice, 2);
        if ($pluginPrice !== null) {
            $pluginPrice = round($pluginPrice, 2);
        }
        if ($merchantPrice !== null) {
            $merchantPrice = round($merchantPrice, 2);
        }
        if ($variationPrice !== null) {
            $variationPrice = round($variationPrice, 2);
        }

        return [
            'unit_price' => $unitPrice,
            'product_price' => $productPrice,
            'plugin_price' => $pluginPrice,
            'merchant_price' => $merchantPrice,
            'variation_price' => $variationPrice,
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
}
