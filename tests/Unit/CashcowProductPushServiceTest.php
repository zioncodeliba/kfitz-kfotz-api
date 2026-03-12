<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\ProductVariation;
use App\Services\CashcowProductPushService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CashcowProductPushServiceTest extends TestCase
{
    public function test_it_pushes_product_and_variation_inventory_without_hiding_product(): void
    {
        config([
            'cashcow.base_url' => 'https://api.cashcow.example',
            'cashcow.token' => 'cashcow-token',
            'cashcow.store_id' => 'store-1',
        ]);

        Http::fake([
            'https://api.cashcow.example/Api/Stores/CreateOrUpdatePrtoduct' => Http::response([
                'success' => true,
            ], 200),
        ]);

        $product = new class extends Product
        {
            public function loadMissing($relations)
            {
                return $this;
            }
        };

        $product->forceFill([
            'name' => 'Cashcow Product',
            'description' => 'Cashcow payload test',
            'sku' => 'CC-1234',
            'price' => 80,
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
        $product->id = 3001;
        $product->setRelation('productVariations', collect([
            new ProductVariation([
                'product_id' => $product->id,
                'sku' => 'CC-1234-BLACK',
                'inventory' => 0,
                'attributes' => ['color' => 'Black'],
                'price' => null,
                'image' => null,
            ]),
        ]));

        $result = app(CashcowProductPushService::class)->syncInventoryForProduct($product, false);

        $this->assertSame(2, $result['requested']);
        $this->assertSame(2, $result['updated']);
        $this->assertSame(0, $result['errors']);

        Http::assertSentCount(2);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.cashcow.example/Api/Stores/CreateOrUpdatePrtoduct'
                && $request['sku'] === 'CC-1234'
                && (int) $request['qty'] === 0
                && (bool) $request['is_visible'] === true;
        });
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.cashcow.example/Api/Stores/CreateOrUpdatePrtoduct'
                && $request['sku'] === 'CC-1234-BLACK'
                && (int) $request['qty'] === 0
                && (bool) $request['is_visible'] === true;
        });
    }
}
