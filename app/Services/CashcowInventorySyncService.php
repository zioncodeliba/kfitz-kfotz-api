<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CashcowInventorySyncService
{
    protected string $baseUrl;
    protected string $token;
    protected string $storeId;
    protected int $pageSize;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('cashcow.base_url'), '/');
        $this->token = (string) config('cashcow.token');
        $this->storeId = (string) config('cashcow.store_id');
        $this->pageSize = (int) config('cashcow.page_size', 20);
    }

    /**
    * Sync inventory and variations from Cashcow API.
    *
    * @return array{pages:int,products_updated:int,variations_updated:int,missing_products:array}
    */
    public function sync(): array
    {
        $page = 1;
        $pages = 0;
        $productsUpdated = 0;
        $variationsUpdated = 0;
        $missing = [];

        if (empty($this->token) || empty($this->storeId)) {
            throw new \RuntimeException('CASHCOW_TOKEN or CASHCOW_STORE_ID is not configured.');
        }

        while (true) {
            $payload = $this->fetchPage($page);
            $pages++;

            $items = $payload['result'] ?? [];
            if (empty($items)) {
                break;
            }

            foreach ($items as $item) {
                $sku = trim((string) ($item['sku'] ?? ''));
                if ($sku === '') {
                    continue;
                }

                /** @var Product|null $product */
                $product = Product::where('sku', $sku)->first();
                if (!$product) {
                    $missing[] = $sku;
                    continue;
                }

                $qty = (int) round((float) ($item['qty'] ?? 0));
                $product->stock_quantity = $qty;
                $product->save();
                $productsUpdated++;

                $attributes = $item['attributes'] ?? [];
                foreach ($attributes as $attribute) {
                    $displayName = $attribute['attribute_displayname'] ?? null;
                    $options = $attribute['options'] ?? [];
                    foreach ($options as $option) {
                        $variationSku = trim((string) ($option['sku'] ?? ''));
                        if ($variationSku === '') {
                            continue;
                        }

                        $variationQty = $option['qty'];
                        $inventory = $variationQty === null
                            ? $qty
                            : (int) round((float) $variationQty);

                        ProductVariation::updateOrCreate(
                            [
                                'product_id' => $product->id,
                                'sku' => $variationSku,
                            ],
                            [
                                'inventory' => $inventory,
                                'attributes' => [
                                    'display_name' => $displayName,
                                    'option_text' => $option['option_text'] ?? null,
                                ],
                            ]
                        );

                        $variationsUpdated++;
                    }
                }
            }

            // Stop if fewer than page size (no more pages)
            if (count($items) < $this->pageSize) {
                break;
            }

            $page++;
            sleep(1); // respect remote API
        }

        return [
            'pages' => $pages,
            'products_updated' => $productsUpdated,
            'variations_updated' => $variationsUpdated,
            'missing_products' => array_values(array_unique($missing)),
        ];
    }

    private function fetchPage(int $page): array
    {
        $url = "{$this->baseUrl}/Api/Products/GetQty";

        $response = Http::timeout(30)
            ->retry(3, 1000)
            ->get($url, [
                'token' => $this->token,
                'store_id' => $this->storeId,
                'page' => $page,
                'page_size' => $this->pageSize,
            ]);

        if ($response->failed()) {
            Log::error('Cashcow inventory fetch failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'page' => $page,
            ]);
            $response->throw();
        }

        return $response->json() ?? [];
    }
}
