<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MerchantCustomer;
use App\Models\MerchantSite;
use App\Models\Order;
use App\Models\Product;
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
            'totals' => 'nullable|array',
            'totals.subtotal' => 'nullable|numeric|min:0',
            'totals.tax' => 'nullable|numeric|min:0',
            'totals.shipping_cost' => 'nullable|numeric|min:0',
            'totals.discount' => 'nullable|numeric|min:0',
            'totals.total' => 'nullable|numeric|min:0',
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

        try {
            DB::beginTransaction();

            $items = collect($data['items']);

            $productIds = $items->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->values();
            $products = Product::whereIn('id', $productIds)->get()->keyBy('id');

            if ($products->count() !== $productIds->count()) {
                DB::rollBack();
                return $this->errorResponse('One or more products were not found for this merchant.', 422);
            }

            $computedSubtotal = 0;
            $orderItemsPayload = [];

            $items->each(function (array $item) use (&$orderItemsPayload, &$computedSubtotal, $merchantUser, $products) {
                $productId = (int) $item['product_id'];
                $product = $products->get($productId);
                if (!$product) {
                    return;
                }

                $quantity = max((float) $item['quantity'], 0.0001);
                $unitPrice = $this->resolveMerchantUnitPrice($product, $merchantUser->id)
                    ?? $product->getCurrentPrice();
                $totalPrice = $unitPrice * $quantity;

                $computedSubtotal += $totalPrice;

                $orderItemsPayload[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_sku' => $item['sku'] ?? $product->sku,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_price' => $totalPrice,
                    'product_data' => [
                        'description' => $product->description,
                        'images' => $product->images,
                    ],
                ];
            });

            $totalsInput = $data['totals'] ?? [];
            $subtotal = (float) ($totalsInput['subtotal'] ?? $computedSubtotal);
            $tax = (float) ($totalsInput['tax'] ?? 0);
            $shippingCost = (float) ($totalsInput['shipping_cost'] ?? 0);
            $discount = (float) ($totalsInput['discount'] ?? 0);
            $total = (float) ($totalsInput['total'] ?? ($subtotal + $tax + $shippingCost - $discount));

            $merchantCustomer = $this->syncMerchantCustomer($merchantUser->id, $data['customer']);

            $order = Order::create([
                'user_id' => $merchantUser->id,
                'merchant_id' => $merchantUser->id,
                'merchant_customer_id' => $merchantCustomer->id,
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
                    'initiator' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'role' => $user->role,
                    ],
                ],
                'shipping_address' => $data['customer']['address'],
                'billing_address' => $data['customer']['address'],
                'subtotal' => $subtotal,
                'tax' => $tax,
                'shipping_cost' => $shippingCost,
                'discount' => $discount,
                'total' => $total,
                'notes' => $data['notes'] ?? null,
            ]);

            $order->items()->createMany($orderItemsPayload);

            DB::commit();

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
}
