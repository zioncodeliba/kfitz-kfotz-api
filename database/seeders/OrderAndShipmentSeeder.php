<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShippingCarrier;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class OrderAndShipmentSeeder extends Seeder
{
    public function run(): void
    {
        $ordersConfig = [
            [
                'order_number' => 'SEED-ORD-001',
                'days_ago' => 2,
                'customer_email' => 'user1@example.com',
                'merchant_email' => 'seller@example.com',
                'source' => 'pos',
                'source_reference' => 'POS-TLV-001',
                'source_metadata' => [
                    'register' => 'TLV-1',
                    'operator' => 'Dana',
                ],
                'status' => 'pending',
                'payment_status' => 'pending',
                'shipping_type' => 'delivery',
                'shipping_method' => 'regular',
                'shipping_cost' => 25.00,
                'discount' => 0,
                'cod_payment' => false,
                'notes' => 'Awaiting confirmation from warehouse.',
                'items' => [
                    ['sku' => 'ELEC-TV-55-001', 'quantity' => 1],
                    ['sku' => 'CLOT-JEANS-002', 'quantity' => 1],
                ],
                'shipment' => null,
            ],
            [
                'order_number' => 'SEED-ORD-002',
                'days_ago' => 5,
                'customer_email' => 'ops@example.com',
                'merchant_email' => 'seller@example.com',
                'source' => 'online_store',
                'source_reference' => 'WEB-7845',
                'source_metadata' => [
                    'campaign' => 'retargeting_october',
                    'utm_source' => 'facebook',
                ],
                'status' => 'processing',
                'payment_status' => 'paid',
                'shipping_type' => 'delivery',
                'shipping_method' => 'express',
                'shipping_cost' => 32.50,
                'discount' => 15.00,
                'cod_payment' => false,
                'notes' => 'Priority handling required.',
                'items' => [
                    ['sku' => 'PHN-IP15P-002', 'quantity' => 1],
                    ['sku' => 'AUD-ANC-001', 'quantity' => 1],
                ],
                'shipment' => [
                    'tracking_number' => 'TRK-SEED-2001',
                    'status' => 'in_transit',
                    'carrier_code' => 'dhl',
                    'service_type' => 'express',
                    'package_type' => 'box',
                    'shipping_cost' => 32.50,
                ],
            ],
            [
                'order_number' => 'SEED-ORD-003',
                'days_ago' => 12,
                'customer_email' => 'viewer@example.com',
                'merchant_email' => 'seller@example.com',
                'source' => 'online_store',
                'source_reference' => 'WEB-7901',
                'source_metadata' => [
                    'campaign' => 'newsletter',
                    'utm_medium' => 'email',
                ],
                'status' => 'shipped',
                'payment_status' => 'paid',
                'shipping_type' => 'delivery',
                'shipping_method' => 'regular',
                'shipping_cost' => 18.00,
                'discount' => 0,
                'cod_payment' => false,
                'notes' => 'Customer notified of shipment.',
                'items' => [
                    ['sku' => 'HK-AIRF-002', 'quantity' => 1],
                    ['sku' => 'HK-PIL-003', 'quantity' => 2],
                ],
                'shipment' => [
                    'tracking_number' => 'TRK-SEED-3001',
                    'status' => 'out_for_delivery',
                    'carrier_code' => 'fedex',
                    'service_type' => 'regular',
                    'package_type' => 'box',
                    'shipping_cost' => 18.00,
                ],
            ],
            [
                'order_number' => 'SEED-ORD-004',
                'days_ago' => 35,
                'customer_email' => 'user1@example.com',
                'merchant_email' => 'seller@example.com',
                'source' => 'marketplace',
                'source_reference' => 'AMZ-220045',
                'source_metadata' => [
                    'marketplace' => 'Amazon',
                    'country' => 'US',
                ],
                'status' => 'delivered',
                'payment_status' => 'paid',
                'shipping_type' => 'delivery',
                'shipping_method' => 'express',
                'shipping_cost' => 28.00,
                'discount' => 10.00,
                'cod_payment' => false,
                'notes' => 'Delivered and signed by recipient.',
                'items' => [
                    ['sku' => 'LAP-GAME16-002', 'quantity' => 1],
                    ['sku' => 'AUD-GAME-004', 'quantity' => 1],
                ],
                'shipment' => [
                    'tracking_number' => 'TRK-SEED-4001',
                    'status' => 'delivered',
                    'carrier_code' => 'ups',
                    'service_type' => 'express',
                    'package_type' => 'box',
                    'shipping_cost' => 28.00,
                ],
            ],
            [
                'order_number' => 'SEED-ORD-005',
                'days_ago' => 70,
                'customer_email' => 'ops@example.com',
                'merchant_email' => 'seller@example.com',
                'source' => 'manual',
                'source_reference' => 'IMPORT-PO-1034',
                'source_metadata' => [
                    'import_batch' => '2025-Q2-B2B',
                ],
                'status' => 'confirmed',
                'payment_status' => 'pending',
                'shipping_type' => 'delivery',
                'shipping_method' => 'regular',
                'shipping_cost' => 22.00,
                'discount' => 0,
                'cod_payment' => false,
                'notes' => 'Waiting for stock allocation.',
                'items' => [
                    ['sku' => 'FUR-SOFA3-001', 'quantity' => 1],
                ],
                'shipment' => null,
            ],
            [
                'order_number' => 'SEED-ORD-006',
                'days_ago' => 120,
                'customer_email' => 'viewer@example.com',
                'merchant_email' => 'seller@example.com',
                'source' => 'marketplace',
                'source_reference' => 'EBAY-112233',
                'source_metadata' => [
                    'marketplace' => 'eBay',
                    'seller_account' => 'kfitz-global',
                ],
                'status' => 'delivered',
                'payment_status' => 'paid',
                'shipping_type' => 'delivery',
                'shipping_method' => 'express',
                'shipping_cost' => 45.00,
                'discount' => 20.00,
                'cod_payment' => false,
                'notes' => 'Seasonal sale order.',
                'items' => [
                    ['sku' => 'CAMP-TENT2-001', 'quantity' => 1],
                    ['sku' => 'CAMP-SLP0-002', 'quantity' => 2],
                ],
                'shipment' => [
                    'tracking_number' => 'TRK-SEED-6001',
                    'status' => 'delivered',
                    'carrier_code' => 'israelpost',
                    'service_type' => 'express',
                    'package_type' => 'box',
                    'shipping_cost' => 45.00,
                ],
            ],
            [
                'order_number' => 'SEED-ORD-007',
                'days_ago' => 180,
                'customer_email' => 'user1@example.com',
                'merchant_email' => 'seller@example.com',
                'source' => 'online_store',
                'source_reference' => 'WEB-6123',
                'source_metadata' => [
                    'campaign' => 'fitness_bundle',
                    'utm_source' => 'google_ads',
                ],
                'status' => 'processing',
                'payment_status' => 'paid',
                'shipping_type' => 'delivery',
                'shipping_method' => 'regular',
                'shipping_cost' => 30.00,
                'discount' => 5.00,
                'cod_payment' => false,
                'notes' => 'Fitness bundle order.',
                'items' => [
                    ['sku' => 'FIT-RB-002', 'quantity' => 3],
                    ['sku' => 'FIT-KB12-003', 'quantity' => 1],
                ],
                'shipment' => [
                    'tracking_number' => 'TRK-SEED-7001',
                    'status' => 'in_transit',
                    'carrier_code' => 'ups',
                    'service_type' => 'regular',
                    'package_type' => 'box',
                    'shipping_cost' => 30.00,
                ],
            ],
            [
                'order_number' => 'SEED-ORD-008',
                'days_ago' => 310,
                'customer_email' => 'ops@example.com',
                'merchant_email' => 'seller@example.com',
                'source' => 'manual',
                'source_reference' => 'CSR-ADI',
                'source_metadata' => [
                    'csr_name' => 'Adi',
                    'note' => 'Annual corporate order',
                ],
                'status' => 'delivered',
                'payment_status' => 'paid',
                'shipping_type' => 'delivery',
                'shipping_method' => 'regular',
                'shipping_cost' => 25.00,
                'discount' => 0,
                'cod_payment' => false,
                'notes' => 'Annual procurement order.',
                'items' => [
                    ['sku' => 'HK-PAN28-001', 'quantity' => 2],
                    ['sku' => 'HK-PIL-003', 'quantity' => 3],
                ],
                'shipment' => [
                    'tracking_number' => 'TRK-SEED-8001',
                    'status' => 'delivered',
                    'carrier_code' => 'fedex',
                    'service_type' => 'regular',
                    'package_type' => 'box',
                    'shipping_cost' => 25.00,
                ],
            ],
        ];

        $userEmails = collect($ordersConfig)
            ->flatMap(fn ($order) => [$order['customer_email'], $order['merchant_email']])
            ->unique()
            ->all();

        $users = User::whereIn('email', $userEmails)->get()->keyBy('email');

        $missingUsers = collect($userEmails)->diff($users->keys());
        if ($missingUsers->isNotEmpty()) {
            $this->command?->warn(
                'Skipping OrderAndShipmentSeeder. Missing users: ' . $missingUsers->implode(', ')
            );
            return;
        }

        $allSkus = collect($ordersConfig)
            ->flatMap(fn ($order) => collect($order['items'])->pluck('sku'))
            ->unique()
            ->all();

        $products = Product::whereIn('sku', $allSkus)->get()->keyBy('sku');
        $missingSkus = collect($allSkus)->diff($products->keys());
        if ($missingSkus->isNotEmpty()) {
            $this->command?->warn(
                'Skipping OrderAndShipmentSeeder. Missing products: ' . $missingSkus->implode(', ')
            );
            return;
        }

        $carrierCodes = collect($ordersConfig)
            ->pluck('shipment')
            ->filter()
            ->pluck('carrier_code')
            ->unique()
            ->all();

        $carriers = ShippingCarrier::whereIn('code', $carrierCodes)->get()->keyBy('code');
        $missingCarriers = collect($carrierCodes)->diff($carriers->keys());
        if ($missingCarriers->isNotEmpty()) {
            $this->command?->warn(
                'Skipping OrderAndShipmentSeeder. Missing carriers: ' . $missingCarriers->implode(', ')
            );
            return;
        }

        $warehouseAddress = [
            'name' => 'KFitz Logistics',
            'address' => '123 Warehouse Ave',
            'city' => 'Tel Aviv',
            'zip' => '6100000',
            'phone' => '+972-3-555-0101',
        ];

        $seedNow = Carbon::now();

        DB::transaction(function () use (
            $ordersConfig,
            $users,
            $products,
            $carriers,
            $warehouseAddress,
            $seedNow
        ) {
            foreach ($ordersConfig as $orderConfig) {
                $customer = $users[$orderConfig['customer_email']];
                $merchant = $users[$orderConfig['merchant_email']];
                $orderDate = $seedNow->copy()->subDays($orderConfig['days_ago'] ?? 0)->setTime(10, 0, 0);

                $shippingAddress = [
                    'name' => $customer->name,
                    'address' => '45 Customer Street',
                    'city' => 'Tel Aviv',
                    'zip' => '6100200',
                    'phone' => $customer->phone ?? '+972-50-123-4567',
                ];

                $billingAddress = $shippingAddress;

                $itemsPayload = [];
                $subtotal = 0;

                foreach ($orderConfig['items'] as $itemConfig) {
                    $product = $products[$itemConfig['sku']];
                    $unitPrice = $product->getCurrentPrice();
                    $quantity = $itemConfig['quantity'];
                    $lineTotal = $unitPrice * $quantity;
                    $subtotal += $lineTotal;

                    $itemsPayload[] = [
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'product_sku' => $product->sku,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'total_price' => $lineTotal,
                        'product_data' => [
                            'description' => $product->description,
                            'images' => $product->images,
                        ],
                    ];
                }

                $tax = round($subtotal * 0.17, 2);
                $total = $subtotal + $tax + $orderConfig['shipping_cost'] - $orderConfig['discount'];

                $shippedAt = in_array($orderConfig['status'], ['processing', 'shipped', 'delivered'])
                    ? $orderDate->copy()->addDays(1)
                    : null;
                $deliveredAt = $orderConfig['status'] === 'delivered'
                    ? $orderDate->copy()->addDays(3)
                    : null;
                $orderUpdatedAt = $deliveredAt
                    ? $deliveredAt->copy()
                    : ($shippedAt ? $shippedAt->copy() : $orderDate->copy()->addDay());

                $order = Order::updateOrCreate(
                    ['order_number' => $orderConfig['order_number']],
                    [
                        'user_id' => $customer->id,
                        'merchant_id' => $merchant->id,
                        'status' => $orderConfig['status'],
                        'payment_status' => $orderConfig['payment_status'],
                        'subtotal' => $subtotal,
                        'tax' => $tax,
                        'shipping_cost' => $orderConfig['shipping_cost'],
                        'discount' => $orderConfig['discount'],
                        'total' => $total,
                        'notes' => $orderConfig['notes'],
                        'shipping_address' => $shippingAddress,
                        'billing_address' => $billingAddress,
                        'shipping_type' => $orderConfig['shipping_type'],
                        'shipping_method' => $orderConfig['shipping_method'],
                        'cod_payment' => $orderConfig['cod_payment'],
                        'shipping_company' => null,
                        'carrier_id' => null,
                        'carrier_service_type' => null,
                        'source' => $orderConfig['source'] ?? 'manual',
                        'source_reference' => $orderConfig['source_reference'] ?? null,
                        'source_metadata' => $orderConfig['source_metadata'] ?? null,
                        'shipped_at' => $shippedAt,
                        'delivered_at' => $deliveredAt,
                    ]
                );

                $order->items()->delete();
                foreach ($itemsPayload as $itemPayload) {
                    $order->items()->create($itemPayload);
                }

                $order->timestamps = false;
                $order->created_at = $orderDate;
                $order->updated_at = $orderUpdatedAt;
                $order->save();
                $order->timestamps = true;

                $shipmentConfig = $orderConfig['shipment'];

                if ($shipmentConfig) {
                    $carrier = $carriers[$shipmentConfig['carrier_code']];

                    $shipmentCreatedAt = $orderDate->copy()->addHours(4);
                    $pickedUpAt = $orderDate->copy()->addDay()->setTime(9, 0, 0);
                    $inTransitAt = $pickedUpAt->copy()->addDay()->setTime(8, 30, 0);
                    $outForDeliveryAt = $inTransitAt->copy()->addHours(18);
                    $deliveredAtTs = $shipmentConfig['status'] === 'delivered'
                        ? $outForDeliveryAt->copy()->addHours(6)
                        : null;

                    $trackingEvents = [
                        [
                            'timestamp' => $shipmentCreatedAt->toIso8601String(),
                            'event' => 'Shipment created',
                            'description' => 'Shipment initialized in system',
                        ],
                        [
                            'timestamp' => $pickedUpAt->toIso8601String(),
                            'event' => 'Picked up',
                            'description' => 'Package collected from warehouse',
                        ],
                    ];

                    if (in_array($shipmentConfig['status'], ['in_transit', 'out_for_delivery', 'delivered'])) {
                        $trackingEvents[] = [
                            'timestamp' => $inTransitAt->toIso8601String(),
                            'event' => 'In transit',
                            'description' => 'Package en route to sorting hub',
                        ];
                    }

                    if (in_array($shipmentConfig['status'], ['out_for_delivery', 'delivered'])) {
                        $trackingEvents[] = [
                            'timestamp' => $outForDeliveryAt->toIso8601String(),
                            'event' => 'Out for delivery',
                            'description' => 'Courier is on the way to the recipient',
                        ];
                    }

                    if ($deliveredAtTs) {
                        $trackingEvents[] = [
                            'timestamp' => $deliveredAtTs->toIso8601String(),
                            'event' => 'Delivered',
                            'description' => 'Package delivered to recipient',
                        ];
                    }

                    $shipment = Shipment::updateOrCreate(
                        ['tracking_number' => $shipmentConfig['tracking_number']],
                        [
                            'order_id' => $order->id,
                            'status' => $shipmentConfig['status'],
                            'carrier' => $carrier->name,
                            'carrier_id' => $carrier->id,
                            'carrier_service_type' => $shipmentConfig['service_type'],
                            'service_type' => $shipmentConfig['service_type'],
                            'package_type' => $shipmentConfig['package_type'],
                            'weight' => 5.5,
                            'length' => 40,
                            'width' => 30,
                            'height' => 20,
                            'origin_address' => $warehouseAddress,
                            'destination_address' => $shippingAddress,
                            'shipping_cost' => $shipmentConfig['shipping_cost'],
                            'cod_payment' => false,
                            'cod_amount' => null,
                            'notes' => $orderConfig['notes'],
                            'tracking_events' => $trackingEvents,
                            'picked_up_at' => $pickedUpAt,
                            'in_transit_at' => in_array($shipmentConfig['status'], ['in_transit', 'out_for_delivery', 'delivered'])
                                ? $inTransitAt
                                : null,
                            'out_for_delivery_at' => in_array($shipmentConfig['status'], ['out_for_delivery', 'delivered'])
                                ? $outForDeliveryAt
                                : null,
                            'delivered_at' => $deliveredAtTs,
                        ]
                    );

                    $shipmentUpdatedAt = $deliveredAtTs
                        ? $deliveredAtTs->copy()
                        : (in_array($shipmentConfig['status'], ['out_for_delivery'])
                            ? $outForDeliveryAt->copy()
                            : (in_array($shipmentConfig['status'], ['in_transit'])
                                ? $inTransitAt->copy()
                                : $pickedUpAt->copy()));

                    $shipment->timestamps = false;
                    $shipment->created_at = $shipmentCreatedAt;
                    $shipment->updated_at = $shipmentUpdatedAt;
                    $shipment->save();
                    $shipment->timestamps = true;

                    $order->tracking_number = $shipmentConfig['tracking_number'];
                    $order->shipping_company = $carrier->name;
                    $order->carrier_id = $carrier->id;
                    $order->carrier_service_type = $shipmentConfig['service_type'];
                    $order->timestamps = false;
                    $order->save();
                    $order->timestamps = true;
                } else {
                    Shipment::where('order_id', $order->id)->delete();
                    $order->tracking_number = null;
                    $order->shipping_company = null;
                    $order->carrier_id = null;
                    $order->carrier_service_type = null;
                    $order->timestamps = false;
                    $order->save();
                    $order->timestamps = true;
                }
            }
        });
    }
}
