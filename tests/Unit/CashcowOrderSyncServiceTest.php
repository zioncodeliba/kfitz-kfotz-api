<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\User;
use App\Services\CashcowOrderSyncService;
use App\Services\EmailTemplateService;
use App\Services\OrderEmailPayloadService;
use App\Services\ProductInventoryTransitionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class CashcowOrderSyncServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
            $table->string('role')->nullable();
            $table->string('status')->nullable();
            $table->decimal('order_limit', 12, 2)->nullable();
            $table->decimal('order_balance', 12, 2)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('merchant_sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('site_url');
            $table->string('name')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('platform')->nullable();
            $table->timestamp('plugin_installed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->string('status')->nullable();
            $table->decimal('balance', 12, 2)->nullable();
            $table->decimal('credit_limit', 12, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('merchant_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_user_id');
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('notes')->nullable();
            $table->json('address')->nullable();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('sku')->unique();
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('sale_price', 12, 2)->nullable();
            $table->decimal('cost_price', 12, 2)->nullable();
            $table->decimal('shipping_price', 12, 2)->nullable();
            $table->unsignedBigInteger('shipping_type_id')->nullable();
            $table->integer('stock_quantity')->default(0);
            $table->integer('min_stock_alert')->default(0);
            $table->unsignedBigInteger('category_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->json('images')->nullable();
            $table->json('variations')->nullable();
            $table->json('merchant_prices')->nullable();
            $table->json('plugin_site_prices')->nullable();
            $table->decimal('restocked_initial_stock', 12, 2)->nullable();
            $table->timestamp('restocked_at')->nullable();
            $table->decimal('weight', 12, 2)->nullable();
            $table->json('dimensions')->nullable();
            $table->timestamps();
        });

        Schema::create('product_variations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id');
            $table->string('sku')->unique();
            $table->decimal('price', 12, 2)->nullable();
            $table->integer('inventory')->nullable();
            $table->json('attributes')->nullable();
            $table->string('image')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('merchant_id')->nullable();
            $table->unsignedBigInteger('merchant_customer_id')->nullable();
            $table->unsignedBigInteger('merchant_site_id')->nullable();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->string('source')->nullable();
            $table->string('source_reference')->nullable();
            $table->json('source_metadata')->nullable();
            $table->string('status')->default(Order::STATUS_PENDING);
            $table->string('payment_status')->default('pending');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax', 12, 2)->default(0);
            $table->decimal('shipping_cost', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->string('invoice_provider')->nullable();
            $table->text('invoice_url')->nullable();
            $table->json('invoice_payload')->nullable();
            $table->json('shipping_address')->nullable();
            $table->json('billing_address')->nullable();
            $table->string('tracking_number')->nullable();
            $table->string('shipping_company')->nullable();
            $table->unsignedBigInteger('carrier_id')->nullable();
            $table->string('carrier_service_type')->nullable();
            $table->string('shipping_type')->nullable();
            $table->string('shipping_method')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id');
            $table->foreignId('product_id');
            $table->string('product_name');
            $table->string('product_sku');
            $table->integer('quantity');
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('total_price', 12, 2)->default(0);
            $table->json('product_data')->nullable();
            $table->timestamps();
        });

        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id');
            $table->string('tracking_number')->nullable();
            $table->string('status')->default(Shipment::STATUS_PENDING);
            $table->string('carrier')->nullable();
            $table->unsignedBigInteger('carrier_id')->nullable();
            $table->string('carrier_service_type')->nullable();
            $table->string('service_type')->nullable();
            $table->string('package_type')->nullable();
            $table->decimal('weight', 12, 2)->nullable();
            $table->decimal('length', 12, 2)->nullable();
            $table->decimal('width', 12, 2)->nullable();
            $table->decimal('height', 12, 2)->nullable();
            $table->json('origin_address')->nullable();
            $table->json('destination_address')->nullable();
            $table->decimal('shipping_cost', 12, 2)->default(0);
            $table->boolean('cod_payment')->default(false);
            $table->decimal('cod_amount', 12, 2)->nullable();
            $table->string('cod_method')->nullable();
            $table->boolean('cod_collected')->default(false);
            $table->timestamp('cod_collected_at')->nullable();
            $table->json('shipping_units')->nullable();
            $table->text('notes')->nullable();
            $table->json('tracking_events')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('in_transit_at')->nullable();
            $table->timestamp('out_for_delivery_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_existing_cashcow_order_only_updates_payment_status_and_keeps_shipment_state(): void
    {
        config([
            'cashcow.base_url' => 'https://api.cashcow.example',
            'cashcow.token' => 'cashcow-token',
            'cashcow.store_id' => 'store-1',
            'cashcow.orders_site_url' => 'https://www.kfitzkfotz.co.il/',
        ]);

        Http::fake([
            'https://api.cashcow.example/Api/Stores/Orders*' => Http::response([
                'page' => 1,
                'page_size' => 20,
                'total_records' => 1,
                'store_id' => 'store-1',
                'result' => [[
                    'Id' => 9001,
                    'OrderDate' => '2026-03-14 10:30:00',
                    'OrderStatus' => 4,
                    'ShipingType' => 1,
                    'ShipingPrice' => 25,
                    'DiscountPrice' => 0,
                    'TotalPrice' => 125,
                    'FirstName' => 'Dana',
                    'LastName' => 'Levi',
                    'Email' => 'dana@example.com',
                    'Phone' => '0501234567',
                    'StreetNameAndNumber' => 'Herzl 10',
                    'City' => 'Tel Aviv',
                    'ZipCode' => '6100001',
                    'CustomerInstructions' => 'Leave at door',
                    'Products' => [[
                        'Id' => 501,
                        'sku' => 'SKU-9001',
                        'Name' => 'Cashcow Test Product',
                        'Qty' => 1,
                        'Total' => 100,
                    ]],
                ]],
            ], 200),
        ]);

        $merchantUser = User::create([
            'name' => 'Merchant User',
            'email' => 'merchant@example.com',
            'password' => 'secret',
            'role' => 'merchant',
        ]);

        $site = \App\Models\MerchantSite::create([
            'user_id' => $merchantUser->id,
            'site_url' => 'https://www.kfitzkfotz.co.il/',
            'name' => 'Kfitz Site',
        ]);

        $product = Product::create([
            'name' => 'Cashcow Test Product',
            'description' => 'Test product',
            'sku' => 'SKU-9001',
            'price' => 100,
            'stock_quantity' => 5,
            'min_stock_alert' => 1,
            'is_active' => true,
            'is_featured' => false,
            'images' => [],
        ]);

        $order = Order::create([
            'order_number' => 'CC-9001',
            'user_id' => $merchantUser->id,
            'merchant_id' => $merchantUser->id,
            'merchant_site_id' => $site->id,
            'source' => 'cashcow',
            'source_reference' => '9001',
            'status' => Order::STATUS_SHIPPED,
            'payment_status' => 'pending',
            'shipping_type' => 'delivery',
            'shipping_method' => 'express',
            'shipping_cost' => 30,
            'subtotal' => 100,
            'total' => 130,
            'tracking_number' => 'TRK-9001',
            'shipping_company' => 'Chita',
            'source_metadata' => [
                'existing_flag' => true,
            ],
        ]);

        Shipment::create([
            'order_id' => $order->id,
            'tracking_number' => 'TRK-9001',
            'status' => Shipment::STATUS_PENDING,
            'carrier' => 'Chita',
            'service_type' => 'regular',
            'package_type' => 'regular',
            'origin_address' => ['name' => 'Warehouse'],
            'destination_address' => ['name' => 'Dana'],
            'shipping_cost' => 30,
        ]);

        $service = new CashcowOrderSyncService(
            Mockery::mock(EmailTemplateService::class),
            Mockery::mock(OrderEmailPayloadService::class),
            tap(Mockery::mock(ProductInventoryTransitionService::class), function ($mock) {
                $mock->shouldNotReceive('handleOutOfStockTransition');
            })
        );

        $summary = $service->sync(1);

        $order->refresh();

        $this->assertSame(1, $summary['updated']);
        $this->assertSame(0, $summary['created']);
        $this->assertSame(Order::STATUS_SHIPPED, $order->status);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('express', $order->shipping_method);
        $this->assertSame(1, Shipment::query()->where('order_id', $order->id)->count());
        $this->assertSame(5, (int) $product->fresh()->stock_quantity);
    }

    public function test_existing_cashcow_order_is_skipped_when_payment_status_is_unchanged(): void
    {
        config([
            'cashcow.base_url' => 'https://api.cashcow.example',
            'cashcow.token' => 'cashcow-token',
            'cashcow.store_id' => 'store-1',
            'cashcow.orders_site_url' => 'https://www.kfitzkfotz.co.il/',
        ]);

        Http::fake([
            'https://api.cashcow.example/Api/Stores/Orders*' => Http::response([
                'page' => 1,
                'page_size' => 20,
                'total_records' => 1,
                'store_id' => 'store-1',
                'result' => [[
                    'Id' => 9002,
                    'OrderDate' => '2026-03-14 10:30:00',
                    'OrderStatus' => 4,
                    'ShipingType' => 1,
                    'ShipingPrice' => 25,
                    'DiscountPrice' => 0,
                    'TotalPrice' => 125,
                    'FirstName' => 'Dana',
                    'LastName' => 'Levi',
                    'Email' => 'dana@example.com',
                    'Phone' => '0501234567',
                    'StreetNameAndNumber' => 'Herzl 10',
                    'City' => 'Tel Aviv',
                    'ZipCode' => '6100001',
                    'Products' => [[
                        'Id' => 502,
                        'sku' => 'SKU-9002',
                        'Name' => 'Cashcow Test Product 2',
                        'Qty' => 1,
                        'Total' => 100,
                    ]],
                ]],
            ], 200),
        ]);

        $merchantUser = User::create([
            'name' => 'Merchant User',
            'email' => 'merchant-2@example.com',
            'password' => 'secret',
            'role' => 'merchant',
        ]);

        $site = \App\Models\MerchantSite::create([
            'user_id' => $merchantUser->id,
            'site_url' => 'https://www.kfitzkfotz.co.il/',
            'name' => 'Kfitz Site',
        ]);

        Product::create([
            'name' => 'Cashcow Test Product 2',
            'description' => 'Test product',
            'sku' => 'SKU-9002',
            'price' => 100,
            'stock_quantity' => 5,
            'min_stock_alert' => 1,
            'is_active' => true,
            'is_featured' => false,
            'images' => [],
        ]);

        $order = Order::create([
            'order_number' => 'CC-9002',
            'user_id' => $merchantUser->id,
            'merchant_id' => $merchantUser->id,
            'merchant_site_id' => $site->id,
            'source' => 'cashcow',
            'source_reference' => '9002',
            'status' => Order::STATUS_SHIPPED,
            'payment_status' => 'paid',
            'shipping_type' => 'delivery',
            'shipping_method' => 'express',
            'shipping_cost' => 30,
            'subtotal' => 100,
            'total' => 130,
        ]);

        $service = new CashcowOrderSyncService(
            Mockery::mock(EmailTemplateService::class),
            Mockery::mock(OrderEmailPayloadService::class),
            tap(Mockery::mock(ProductInventoryTransitionService::class), function ($mock) {
                $mock->shouldNotReceive('handleOutOfStockTransition');
            })
        );

        $summary = $service->sync(1);

        $order->refresh();

        $this->assertSame(0, $summary['updated']);
        $this->assertSame(1, $summary['skipped']);
        $this->assertSame(Order::STATUS_SHIPPED, $order->status);
        $this->assertSame('paid', $order->payment_status);
    }
}
