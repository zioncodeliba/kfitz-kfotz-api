<?php

namespace App\Services;

use App\Models\MerchantCustomer;
use App\Models\MerchantSite;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Shipment;
use App\Models\User;
use App\Services\EmailTemplateService;
use App\Services\OrderEmailPayloadService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CashcowOrderSyncService
{
    protected string $baseUrl;
    protected string $token;
    protected string $storeId;
    protected int $pageSize;
    protected string $targetSiteUrl;

    public function __construct(
        protected EmailTemplateService $emailTemplateService,
        protected OrderEmailPayloadService $orderEmailPayloadService
    )
    {
        $this->baseUrl = rtrim((string) config('cashcow.base_url'), '/');
        $this->token = (string) config('cashcow.token');
        $this->storeId = (string) config('cashcow.store_id');
        $this->pageSize = (int) config('cashcow.page_size', 20);
        $this->targetSiteUrl = $this->normalizeUrl((string) config('cashcow.orders_site_url', 'https://www.kfitzkfotz.co.il/'));
    }

    /**
     * Sync a single page of orders from Cashcow.
     *
     * @return array{
     *     page:int,
     *     page_size:int,
     *     total_records:int|null,
     *     store_id:string|null,
     *     merchant_site_id:int|null,
     *     merchant_user_id:int|null,
     *     orders_received:int,
     *     created:int,
     *     updated:int,
     *     skipped:int,
     *     skipped_orders:array<int, array{cashcow_id:int|string|null, reason:string}>
     * }
     */
    public function sync(int $page, ?int $pageSize = null, ?User $actor = null): array
    {
        if ($page < 1) {
            throw new \InvalidArgumentException('Page must be at least 1.');
        }

        $size = $pageSize ? max(1, (int) $pageSize) : $this->pageSize;

        if (empty($this->token) || empty($this->storeId)) {
            throw new \RuntimeException('CASHCOW_TOKEN or CASHCOW_STORE_ID is not configured.');
        }

        $site = $this->resolveMerchantSite();
        $this->assertActorAuthorized($actor, $site);

        $payload = $this->fetchPage($page, $size);
        $orders = $payload['result'] ?? [];

        $summary = [
            'page' => $payload['page'] ?? $page,
            'page_size' => $payload['page_size'] ?? $size,
            'total_records' => $payload['total_records'] ?? null,
            'store_id' => $payload['store_id'] ?? $this->storeId,
            'merchant_site_id' => $site->id,
            'merchant_user_id' => $site->user_id,
            'orders_received' => count($orders),
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'skipped_orders' => [],
        ];

        foreach ($orders as $remoteOrder) {
            try {
                $result = $this->syncOrder($remoteOrder, $site);
                if ($result === 'created') {
                    $summary['created']++;
                } elseif ($result === 'updated') {
                    $summary['updated']++;
                } elseif ($result === 'skipped_existing') {
                    $summary['skipped']++;
                    $summary['skipped_orders'][] = [
                        'cashcow_id' => $remoteOrder['Id'] ?? null,
                        'reason' => 'Order already synced',
                    ];
                }
            } catch (\Throwable $exception) {
                $summary['skipped']++;
                $summary['skipped_orders'][] = [
                    'cashcow_id' => $remoteOrder['Id'] ?? null,
                    'reason' => $exception->getMessage(),
                ];

                Log::warning('Cashcow order sync skipped', [
                    'cashcow_id' => $remoteOrder['Id'] ?? null,
                    'reason' => $exception->getMessage(),
                ]);
            }
        }

        return $summary;
    }

    protected function fetchPage(int $page, int $pageSize): array
    {
        $url = "{$this->baseUrl}/Api/Stores/Orders";

        $response = Http::timeout(30)
            ->retry(3, 1000)
            ->get($url, [
                'token' => $this->token,
                'store_id' => $this->storeId,
                'page' => $page,
                'page_size' => $pageSize,
            ]);

        if ($response->failed()) {
            Log::error('Cashcow order fetch failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'page' => $page,
                'page_size' => $pageSize,
            ]);
            $response->throw();
        }

        return $response->json() ?? [];
    }

    protected function resolveMerchantSite(): MerchantSite
    {
        $normalized = $this->targetSiteUrl;
        $withTrailingSlash = "{$normalized}/";

        $site = MerchantSite::where('site_url', $normalized)
            ->orWhere('site_url', $withTrailingSlash)
            ->first();

        if (!$site) {
            throw new \RuntimeException(sprintf(
                'Merchant site %s not found. Please create it before syncing orders.',
                $normalized
            ));
        }

        $site->loadMissing('user');

        if (!$site->user) {
            throw new \RuntimeException('Merchant user is missing for site ' . $normalized);
        }

        return $site;
    }

    protected function assertActorAuthorized(?User $actor, MerchantSite $site): void
    {
        if (!$actor) {
            return;
        }

        if ($actor->hasRole('admin')) {
            return;
        }

        if ($actor->hasRole('merchant') && $actor->id === $site->user_id) {
            return;
        }

        throw new \RuntimeException('Insufficient permissions to sync Cashcow orders for this site.');
    }

    protected function syncOrder(array $payload, MerchantSite $site): string
    {
        $cashcowId = $payload['Id'] ?? null;

        if ($cashcowId === null) {
            throw new \RuntimeException('Missing order Id from Cashcow payload.');
        }

        $merchantUser = $site->user;
        $orderDate = $this->parseDate($payload['OrderDate'] ?? null);

        $shipping = $this->mapShippingType((int) ($payload['ShipingType'] ?? 0));
        $statusContext = $this->mapStatus((int) ($payload['OrderStatus'] ?? 0));
        $orderStatusId = (int) ($payload['OrderStatus'] ?? 0);
        $customer = $this->syncMerchantCustomer($merchantUser->id, $payload);
        $itemsContext = $this->normalizeItems($payload['Products'] ?? []);

        if (!empty($itemsContext['missing_skus'])) {
            throw new \RuntimeException('Missing products: ' . implode(', ', $itemsContext['missing_skus']));
        }

        if (empty($itemsContext['items'])) {
            throw new \RuntimeException('No valid items found for Cashcow order ' . $cashcowId);
        }

        $shippingCost = round((float) ($payload['ShipingPrice'] ?? 0), 2);
        $discount = round((float) ($payload['DiscountPrice'] ?? 0), 2);
        $totalFromPayload = (float) ($payload['TotalPrice'] ?? 0);
        $total = $totalFromPayload > 0
            ? round($totalFromPayload, 2)
            : round($itemsContext['subtotal'] + $shippingCost - $discount, 2);
        $subtotal = round(max(0, $itemsContext['subtotal'] - $discount), 2);
        $tax = 0.0;

        $orderNumber = $this->buildOrderNumber((string) $cashcowId);

        $address = $this->buildAddress($payload);

        $invoiceUrl = isset($payload['InvoiceUrl']) && is_string($payload['InvoiceUrl'])
            ? trim($payload['InvoiceUrl'])
            : '';

        if ($invoiceUrl === '' && isset($payload['CopyInvoiceUrl']) && is_string($payload['CopyInvoiceUrl'])) {
            $invoiceUrl = trim($payload['CopyInvoiceUrl']);
        }

        $invoiceUrl = $invoiceUrl !== '' ? $invoiceUrl : null;

        $metadata = [
            'cashcow_id' => $cashcowId,
            'order_status_id' => $payload['OrderStatus'] ?? null,
            'order_status_label' => $statusContext['label'],
            'shipping_type_id' => $payload['ShipingType'] ?? null,
            'payment_option_type' => $payload['PaymentOptionType'] ?? null,
            'transaction_id' => $payload['TransactionId'] ?? null,
            'last_digits' => $payload['LastDigits'] ?? null,
            'invoice_url' => $payload['InvoiceUrl'] ?? null,
            'copy_invoice_url' => $payload['CopyInvoiceUrl'] ?? null,
            'customer_instructions' => $payload['CustomerInstructions'] ?? null,
            'shiping_price' => $payload['ShipingPrice'] ?? null,
            'provided_total_price' => $payload['TotalPrice'] ?? null,
            'source_site_url' => $this->targetSiteUrl,
            'raw_product_skus' => collect($payload['Products'] ?? [])
                ->map(fn ($item) => $item['sku'] ?? null)
                ->filter()
                ->values()
                ->all(),
        ];

        $orderPayload = [
            'user_id' => $merchantUser->id,
            'merchant_id' => $merchantUser->id,
            'merchant_customer_id' => $customer->id,
            'merchant_site_id' => $site->id,
            'status' => $statusContext['status'],
            'payment_status' => $statusContext['payment_status'],
            'source' => 'cashcow',
            'source_reference' => (string) $cashcowId,
            'source_metadata' => $metadata,
            'invoice_provider' => $invoiceUrl ? 'cashcow' : null,
            'invoice_url' => $invoiceUrl,
            'shipping_address' => $address,
            'billing_address' => $address,
            'shipping_type' => $shipping['type'],
            'shipping_method' => $shipping['method'],
            'subtotal' => $subtotal,
            'tax' => $tax,
            'shipping_cost' => $shippingCost,
            'discount' => $discount,
            'total' => $total,
            'notes' => $payload['CustomerInstructions'] ?? null,
        ];

        $createdOrder = null;

        $action = DB::transaction(function () use ($orderPayload, $itemsContext, $orderNumber, $orderDate, &$createdOrder) {
            $order = Order::where('source', 'cashcow')
                ->where('source_reference', $orderPayload['source_reference'])
                ->first();

            $action = 'updated';

            if (!$order) {
                $order = new Order();
                $order->fill($orderPayload);
                $order->order_number = $orderNumber;
                $order->save();
                $action = 'created';
                $createdOrder = $order;
            } else {
                // Existing order: skip to avoid overriding manual changes.
                return 'skipped_existing';
            }

            if ($action === 'created' && $orderDate) {
                $order->forceFill([
                    'created_at' => $orderDate,
                    'updated_at' => $orderDate,
                ])->saveQuietly();
            }

            $productsToRefresh = [];

            foreach ($itemsContext['items'] as $item) {
                $order->items()->create([
                    'product_id' => $item['product']->id,
                    'product_name' => $item['name'],
                    'product_sku' => $item['sku'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'total_price' => $item['total_price'],
                    'product_data' => $item['product_data'],
                ]);

                $item['product']->decrement('stock_quantity', $item['quantity']);
                if ($item['variation'] && $item['variation']->inventory !== null) {
                    $item['variation']->decrement('inventory', $item['quantity']);
                }

                $productsToRefresh[$item['product']->id] = $item['product'];
            }

            foreach ($productsToRefresh as $productToRefresh) {
                $this->refreshProductVariationsSnapshot($productToRefresh);
            }

            // If Cashcow marked it as shipped (status 6), ensure we have an active shipment row.
            if ($order->status === Order::STATUS_SHIPPED) {
                $hasShipment = $order->shipment()
                    ->whereIn('status', array_merge(Shipment::ACTIVE_STATUSES, Shipment::FINAL_STATUSES))
                    ->exists();

                if (!$hasShipment) {
                    $serviceType = $shipping['method'] ?? 'regular';
                    if (!in_array($serviceType, ['regular', 'express', 'pickup'], true)) {
                        $serviceType = 'regular';
                    }

                    $carrierName = $order->carrier?->name ?? 'chita';
                    $originAddress = is_array($order->billing_address) ? $order->billing_address : [];
                    if (empty($originAddress) && is_array($order->shipping_address)) {
                        $originAddress = $order->shipping_address;
                    }

                    if (empty($originAddress)) {
                        $originAddress = [
                            'name' => $order->merchant?->name ?? null,
                        ];
                    }

                    $destinationAddress = is_array($order->shipping_address) ? $order->shipping_address : [];
                    $shippingCostValue = is_numeric($order->shipping_cost) ? (float) $order->shipping_cost : 0.0;

                    $order->shipment()->create([
                        'status' => Shipment::STATUS_PENDING,
                        'carrier' => $carrierName,
                        'origin_address' => $originAddress,
                        'destination_address' => $destinationAddress,
                        'service_type' => $serviceType,
                        'package_type' => 'regular',
                        'carrier_id' => 14,
                        'shipping_cost' => $shippingCostValue,
                    ]);
                }
            }

            return $action;
        });

        if ($action === 'created' && $createdOrder) {
            $this->triggerCashcowOrderEvent($createdOrder, $orderStatusId, $shipping, $statusContext);
        }

        return $action;
    }

    protected function triggerCashcowOrderEvent(
        Order $order,
        int $orderStatusId,
        array $shipping,
        array $statusContext
    ): void {
        $eventKey = null;
        $shippingType = $shipping['type'] ?? $order->shipping_type;
        $paymentStatus = $order->payment_status ?? ($statusContext['payment_status'] ?? null);

        if ($orderStatusId === 2) {
            $eventKey = 'order.cashcow.lead';
        } elseif ($shippingType === 'pickup') {
            $eventKey = 'order.cashcow.pickup_created';
        } elseif ($paymentStatus === 'paid') {
            $eventKey = 'order.created_paid';
        } else {
            $eventKey = 'order.cashcow.unpaid';
        }

        if (!$eventKey) {
            return;
        }

        try {
            $payload = $this->orderEmailPayloadService->build($order);
            $this->emailTemplateService->send(
                'order.created',
                $payload,
                [],
                includeMailingList: true,
                ignoreOverrideRecipients: true
            );
            if ($eventKey !== 'order.created') {
                $this->emailTemplateService->send(
                    $eventKey,
                    $payload,
                    [],
                    includeMailingList: true,
                    ignoreOverrideRecipients: true
                );
            }
        } catch (\Throwable $exception) {
            Log::warning('Failed to send Cashcow order notification', [
                'order_id' => $order->id,
                'event_key' => $eventKey,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    protected function normalizeItems(array $items): array
    {
        $normalized = [];
        $missingSkus = [];
        $subtotal = 0.0;

        foreach ($items as $item) {
            $sku = trim((string) ($item['sku'] ?? ''));
            if ($sku === '') {
                $missingSkus[] = '(missing sku)';
                continue;
            }

            $quantity = (float) ($item['Qty'] ?? 0);
            if ($quantity <= 0) {
                $missingSkus[] = "{$sku} (invalid quantity)";
                continue;
            }

            $variation = ProductVariation::with('product')->where('sku', $sku)->first();
            $product = $variation?->product ?? Product::where('sku', $sku)->first();

            if (!$product) {
                $missingSkus[] = $sku;
                continue;
            }

            $totalPrice = round((float) ($item['Total'] ?? 0), 2);
            $unitPrice = $quantity > 0 ? round($totalPrice / $quantity, 2) : 0.0;

            if ($unitPrice <= 0) {
                $unitPrice = round((float) ($item['price_before_discount'] ?? 0), 2);
            }

            if ($totalPrice <= 0) {
                $totalPrice = round($unitPrice * $quantity, 2);
            }

            $productData = [
                'customer_product_id' => $item['customer_product_id'] ?? null,
                'cashcow_product_id' => $item['Id'] ?? null,
                'attributes' => $item['Attributes'] ?? null,
                'order_code' => $item['Order_Code'] ?? null,
                'discount_price' => $item['discount_price'] ?? null,
                'price_before_discount' => $item['price_before_discount'] ?? null,
                'cost_price' => $item['cost_price'] ?? null,
                'provided_qty' => $item['Qty'] ?? null,
                'provided_total' => $item['Total'] ?? null,
            ];

            if ($variation) {
                $productData['variation'] = [
                    'id' => $variation->id,
                    'sku' => $variation->sku,
                    'attributes' => $variation->attributes,
                    'price' => $variation->price,
                    'inventory' => $variation->inventory,
                ];
            }

            $normalized[] = [
                'product' => $product,
                'variation' => $variation,
                'sku' => $sku,
                'name' => (string) ($item['Name'] ?? $product->name),
                'quantity' => (int) round($quantity),
                'unit_price' => $unitPrice,
                'total_price' => $totalPrice,
                'product_data' => $productData,
            ];

            $subtotal += $totalPrice;
        }

        return [
            'items' => $normalized,
            'subtotal' => round($subtotal, 2),
            'missing_skus' => array_values(array_unique($missingSkus)),
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

    protected function syncMerchantCustomer(int $merchantUserId, array $payload): MerchantCustomer
    {
        $nameParts = [
            $payload['FirstName'] ?? null,
            $payload['LastName'] ?? null,
        ];

        $name = trim(implode(' ', array_filter($nameParts, fn ($part) => $part !== null && trim((string) $part) !== '')));
        $name = $name !== '' ? $name : 'Cashcow Customer';

        $email = isset($payload['Email']) ? strtolower(trim((string) $payload['Email'])) : null;
        $email = $email === '' ? null : $email;
        $phone = $this->normalizePhone($payload['Phone'] ?? ($payload['ExtraField1'] ?? null));
        $address = $this->buildAddress($payload);
        $notes = isset($payload['CustomerInstructions']) ? trim((string) $payload['CustomerInstructions']) : null;

        $existingCustomer = null;

        if ($email || $phone) {
            $existingCustomer = MerchantCustomer::where('merchant_user_id', $merchantUserId)
                ->where(function ($query) use ($email, $phone) {
                    if ($email && $phone) {
                        $query->where('email', $email)
                            ->orWhere('phone', $phone);
                    } elseif ($email) {
                        $query->where('email', $email);
                    } elseif ($phone) {
                        $query->where('phone', $phone);
                    }
                })
                ->first();
        }

        $payloadData = [
            'merchant_user_id' => $merchantUserId,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'notes' => $notes,
            'address' => !empty($address) ? $address : null,
        ];

        if ($existingCustomer) {
            $existingCustomer->fill($payloadData)->save();
            return $existingCustomer;
        }

        return MerchantCustomer::create($payloadData);
    }

    protected function mapShippingType(int $shipingType): array
    {
        if ($shipingType === 1) {
            return [
                'type' => 'delivery',
                'method' => 'regular',
                'label' => 'courier',
            ];
        }

        return [
            'type' => 'pickup',
            'method' => 'pickup',
            'label' => 'pickup',
        ];
    }

    protected function mapStatus(int $orderStatus): array
    {
        return match ($orderStatus) {
            4 => [
                'status' => Order::STATUS_PROCESSING,
                'payment_status' => 'paid',
                'label' => 'Paid - processing',
            ],
            6 => [
                'status' => Order::STATUS_PROCESSING,
                'payment_status' => 'paid',
                'label' => 'Paid - processing',
            ],
            1 => [
                'status' => Order::STATUS_PROCESSING,
                'payment_status' => 'pending',
                'label' => 'Awaiting bank transfer',
            ],
            2 => [
                'status' => Order::STATUS_LEAD,
                'payment_status' => 'pending',
                'label' => 'Lead',
            ],
            default => [
                'status' => Order::STATUS_PROCESSING,
                'payment_status' => 'pending',
                'label' => 'Unknown',
            ],
        };
    }

    protected function buildAddress(array $payload): array
    {
        $firstName = isset($payload['FirstName']) ? trim((string) $payload['FirstName']) : '';
        $lastName = isset($payload['LastName']) ? trim((string) $payload['LastName']) : '';

        $city = isset($payload['City']) ? trim((string) $payload['City']) : null;
        if (!$city && isset($payload['Address'])) {
            $city = trim((string) $payload['Address']);
        }

        $line1 = isset($payload['StreetNameAndNumber']) ? trim((string) $payload['StreetNameAndNumber']) : null;
        if (!$line1 && isset($payload['Address'])) {
            $line1 = trim((string) $payload['Address']);
        }

        $address = [
            'name' => trim($firstName . ' ' . $lastName) ?: null,
            'phone' => $this->normalizePhone($payload['Phone'] ?? null),
            'email' => isset($payload['Email']) ? trim((string) $payload['Email']) : null,
            'line1' => $line1,
            'city' => $city,
            'zip' => isset($payload['ZipCode']) ? trim((string) $payload['ZipCode']) : null,
            'floor' => isset($payload['FloorNumber']) ? trim((string) $payload['FloorNumber']) : null,
            'apartment' => isset($payload['ApartmentNumber']) ? trim((string) $payload['ApartmentNumber']) : null,
            'notes' => isset($payload['CustomerInstructions']) ? trim((string) $payload['CustomerInstructions']) : null,
        ];

        return array_filter($address, fn ($value) => $value !== null && $value !== '');
    }

    protected function normalizeUrl(string $url): string
    {
        $url = trim($url);
        return rtrim($url, '/');
    }

    protected function normalizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);
        return $digits !== '' ? $digits : null;
    }

    protected function parseDate(?string $date): ?Carbon
    {
        if ($date === null || trim($date) === '') {
            return null;
        }

        try {
            $timezone = (string) config('cashcow.timezone', 'Asia/Jerusalem');
            return Carbon::parse($date, $timezone)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function buildOrderNumber(string $cashcowId): string
    {
        return 'CASHCOW-' . $cashcowId;
    }
}
