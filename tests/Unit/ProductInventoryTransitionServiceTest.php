<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Services\CashcowProductPushService;
use App\Services\EmailTemplateService;
use App\Services\ProductInventoryTransitionService;
use App\Services\ToylandInventorySyncService;
use Mockery;
use Tests\TestCase;

class ProductInventoryTransitionServiceTest extends TestCase
{
    public function test_it_sends_notification_and_external_sync_when_product_reaches_zero(): void
    {
        config([
            'cashcow.notify_email' => 'stock-alerts@example.com',
            'mail.from.address' => 'fallback@example.com',
        ]);

        $product = $this->createProduct([
            'sku' => 'SKU-1000',
            'stock_quantity' => 5,
        ]);

        $emailTemplateService = Mockery::mock(EmailTemplateService::class);
        $cashcowProductPushService = Mockery::mock(CashcowProductPushService::class);
        $toylandInventorySyncService = Mockery::mock(ToylandInventorySyncService::class);

        $emailTemplateService
            ->shouldReceive('send')
            ->once()
            ->with(
                'product.out_of_stock',
                Mockery::on(function (array $payload) use ($product) {
                    return ($payload['recipient']['email'] ?? null) === 'stock-alerts@example.com'
                        && ($payload['product']['id'] ?? null) === $product->id
                        && ($payload['product']['sku'] ?? null) === 'SKU-1000'
                        && (int) ($payload['product']['stock_quantity'] ?? -1) === 0;
                })
            );

        $cashcowProductPushService
            ->shouldReceive('syncInventoryForProduct')
            ->once()
            ->with(
                Mockery::on(fn (Product $syncedProduct) => $syncedProduct->id === $product->id && (int) $syncedProduct->stock_quantity === 0),
                false
            )
            ->andReturn([
                'requested' => 1,
                'updated' => 1,
                'errors' => 0,
                'error_samples' => [],
                'skipped' => 0,
                'skipped_skus' => [],
            ]);

        $toylandInventorySyncService
            ->shouldReceive('syncProduct')
            ->once()
            ->with(Mockery::on(fn (Product $syncedProduct) => $syncedProduct->id === $product->id && (int) $syncedProduct->stock_quantity === 0))
            ->andReturn([
                'status' => 'success',
                'items_sent' => 1,
                'error' => null,
            ]);

        $service = new ProductInventoryTransitionService(
            $emailTemplateService,
            $cashcowProductPushService,
            $toylandInventorySyncService
        );

        $product->forceFill(['stock_quantity' => 0]);

        $service->handleOutOfStockTransition($product, 5);
    }

    public function test_it_does_nothing_when_stock_does_not_cross_to_zero(): void
    {
        $product = $this->createProduct([
            'sku' => 'SKU-1001',
            'stock_quantity' => 5,
        ]);

        $emailTemplateService = Mockery::mock(EmailTemplateService::class);
        $cashcowProductPushService = Mockery::mock(CashcowProductPushService::class);
        $toylandInventorySyncService = Mockery::mock(ToylandInventorySyncService::class);

        $emailTemplateService->shouldNotReceive('send');
        $cashcowProductPushService->shouldNotReceive('syncInventoryForProduct');
        $toylandInventorySyncService->shouldNotReceive('syncProduct');

        $service = new ProductInventoryTransitionService(
            $emailTemplateService,
            $cashcowProductPushService,
            $toylandInventorySyncService
        );

        $product->forceFill(['stock_quantity' => 2]);

        $service->handleOutOfStockTransition($product, 5);
    }

    protected function createProduct(array $attributes = []): Product
    {
        $product = new class extends Product
        {
            public function refresh()
            {
                return $this;
            }

            public function loadMissing($relations)
            {
                return $this;
            }
        };

        $product->forceFill(array_merge([
            'name' => 'Inventory Test Product',
            'description' => 'Test product',
            'sku' => 'TEST-SKU-' . uniqid(),
            'price' => 100,
            'sale_price' => null,
            'stock_quantity' => 3,
            'min_stock_alert' => 1,
            'category_id' => 1,
            'is_active' => true,
            'is_featured' => false,
            'images' => [],
            'variations' => null,
            'weight' => null,
            'dimensions' => null,
        ], $attributes));
        $product->id = 1001;
        $product->exists = true;
        $product->setRelation('category', new Category([
            'name' => 'Inventory Test Category',
        ]));
        $product->setRelation('productVariations', collect([
            new ProductVariation([
                'sku' => 'VAR-' . uniqid(),
                'inventory' => 0,
                'attributes' => ['color' => 'Black'],
            ]),
        ]));

        return $product;
    }
}
