<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\ProductVariation;
use App\Services\ToylandInventorySyncService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ToylandInventorySyncServiceTest extends TestCase
{
    public function test_it_posts_product_inventory_in_make_webhook_format(): void
    {
        config([
            'services.toyland.inventory_sync_url' => 'https://hook.eu1.make.com/test-hook',
        ]);

        Http::fake([
            'https://hook.eu1.make.com/test-hook' => Http::response(['ok' => true], 200),
        ]);

        $product = new class extends Product
        {
            public function loadMissing($relations)
            {
                return $this;
            }
        };

        $product->forceFill([
            'name' => 'Toyland Product',
            'description' => 'Toyland payload test',
            'sku' => '1234',
            'price' => 50,
            'sale_price' => null,
            'stock_quantity' => 0,
            'min_stock_alert' => 1,
            'category_id' => 1,
            'is_active' => true,
            'is_featured' => false,
            'images' => [],
            'variations' => null,
            'weight' => null,
            'dimensions' => null,
        ]);
        $product->id = 2001;
        $product->setRelation('productVariations', collect([
            new ProductVariation([
                'product_id' => $product->id,
                'sku' => '1234-BLACK',
                'inventory' => 0,
                'attributes' => ['color' => 'Black'],
                'price' => null,
                'image' => null,
            ]),
        ]));

        $result = app(ToylandInventorySyncService::class)->syncProduct($product);

        $this->assertSame('success', $result['status']);
        $this->assertSame(2, $result['items_sent']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://hook.eu1.make.com/test-hook'
                && $request->method() === 'POST'
                && $request['0']['sku'] === '1234'
                && (int) $request['0']['qty'] === 0
                && $request['1']['sku'] === '1234-BLACK'
                && (int) $request['1']['qty'] === 0;
        });
    }
}
