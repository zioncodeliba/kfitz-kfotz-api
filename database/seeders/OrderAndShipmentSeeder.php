<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\OrderItem;
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
                'customer_email' => 'user1@example.com',
                'merchant_email' => 'seller@example.com',
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
                'customer_email' => 'ops@example.com',
                'merchant_email' => 'seller@example.com',
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
                'customer_email' => 'viewer@example.com',
                'merchant_email' => 'seller@example.com',
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
                'customer_email' => 'user1@example.com',
                'merchant_email' => 'seller@example.com',
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
                'customer_email' => 'ops@example.com',
                'merchant_email' => 'seller@example.com',
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

        DB::transaction(function () use (
            $ordersConfig,
            $users,
            $products,
            $carriers,
            $warehouseAddress
        ) {
            foreach ($ordersConfig as $orderConfig) {
                $customer = $users[$orderConfig['customer_email']];
                $merchant = $users[$orderConfig['merchant_email']];

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
                $now = Carbon::now();

                $shippedAt = in_array($orderConfig['status'], ['processing', 'shipped', 'delivered'])
                    ? $now->copy()->subDays(2)
                    : null;
                $deliveredAt = $orderConfig['status'] === 'delivered'
                    ? $now->copy()->subDay()
                    : null;

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
                        'shipped_at' => $shippedAt,
                        'delivered_at' => $deliveredAt,
                    ]
                );

                $order->items()->delete();
                foreach ($itemsPayload as $itemPayload) {
                    $order->items()->create($itemPayload);
                }

                $shipmentConfig = $orderConfig['shipment'];

                if ($shipmentConfig) {
                    $carrier = $carriers[$shipmentConfig['carrier_code']];

                    $trackingEvents = [
                        [
                            'timestamp' => $now->copy()->subDays(3)->toIso8601String(),
                            'event' => 'Shipment created',
                            'description' => 'Shipment initialized in system',
                        ],
                        [
                            'timestamp' => $now->copy()->subDays(2)->toIso8601String(),
                            'event' => 'Picked up',
                            'description' => 'Package collected from warehouse',
                        ],
                    ];

                    if (in_array($shipmentConfig['status'], ['in_transit', 'out_for_delivery', 'delivered'])) {
                        $trackingEvents[] = [
                            'timestamp' => $now->copy()->subDay()->toIso8601String(),
                            'event' => 'In transit',
                            'description' => 'Package en route to sorting hub',
                        ];
                    }

                    if (in_array($shipmentConfig['status'], ['out_for_delivery', 'delivered'])) {
                        $trackingEvents[] = [
                            'timestamp' => $now->copy()->subHours(12)->toIso8601String(),
                            'event' => 'Out for delivery',
                            'description' => 'Courier is on the way to the recipient',
                        ];
                    }

                    if ($shipmentConfig['status'] === 'delivered') {
                        $trackingEvents[] = [
                            'timestamp' => $now->copy()->subHours(4)->toIso8601String(),
                            'event' => 'Delivered',
                            'description' => 'Package delivered to recipient',
                        ];
                    }

                    Shipment::updateOrCreate(
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
                            'picked_up_at' => $now->copy()->subDays(2),
                            'in_transit_at' => in_array($shipmentConfig['status'], ['in_transit', 'out_for_delivery', 'delivered'])
                                ? $now->copy()->subDay()
                                : null,
                            'out_for_delivery_at' => in_array($shipmentConfig['status'], ['out_for_delivery', 'delivered'])
                                ? $now->copy()->subHours(12)
                                : null,
                            'delivered_at' => $shipmentConfig['status'] === 'delivered'
                                ? $now->copy()->subHours(4)
                                : null,
                        ]
                    );

                    $order->update([
                        'tracking_number' => $shipmentConfig['tracking_number'],
                        'shipping_company' => $carrier->name,
                        'carrier_id' => $carrier->id,
                        'carrier_service_type' => $shipmentConfig['service_type'],
                    ]);
                } else {
                    Shipment::where('order_id', $order->id)->delete();
                    $order->update([
                        'tracking_number' => null,
                        'shipping_company' => null,
                        'carrier_id' => null,
                        'carrier_service_type' => null,
                    ]);
                }
            }
        });
    }
}
