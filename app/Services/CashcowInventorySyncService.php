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

    public function __construct(
        protected ToylandInventorySyncService $toylandInventorySyncService
    )
    {
        $this->baseUrl = rtrim(config('cashcow.base_url'), '/');
        $this->token = (string) config('cashcow.token');
        $this->storeId = (string) config('cashcow.store_id');
        $this->pageSize = (int) config('cashcow.page_size', 20);
    }

    /**
     * Sync inventory and variations from Cashcow API.
     *
     * @return array{
     *     pages:int,
     *     products_updated:int,
     *     variations_updated:int,
     *     missing_products:array,
     *     toyland_sync:array{status:string,items_sent:int,error:?string}
     * }
     */
    /**
     * @param callable|null $progress optional progress callback accepting an event array
     */
    public function sync(?callable $progress = null): array
    {
        $page = 1;
        $pages = 0;
        $productsUpdated = 0;
        $variationsUpdated = 0;
        $missing = [];
        $toylandItems = [];

        if (empty($this->token) || empty($this->storeId)) {
            throw new \RuntimeException('CASHCOW_TOKEN or CASHCOW_STORE_ID is not configured.');
        }

        while (true) {
            $pageProductsUpdated = 0;
            $pageVariationsUpdated = 0;
            $pageMissing = [];

            $payload = $this->fetchPage($page);
            $this->report($progress, [
                'type' => 'page',
                'page' => $page,
                'items' => count($payload['result'] ?? []),
            ]);
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

                $qty = (int) round((float) ($item['qty'] ?? 0));
                $toylandItems[$sku] = [
                    'sku' => $sku,
                    'qty' => $qty,
                ];

                $attributes = $item['attributes'] ?? [];
                $remoteVariations = [];
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

                        $remoteVariations[] = [
                            'sku' => $variationSku,
                            'inventory' => $inventory,
                            'display_name' => $displayName,
                            'option_text' => $option['option_text'] ?? null,
                        ];
                        $toylandItems[$variationSku] = [
                            'sku' => $variationSku,
                            'qty' => $inventory,
                        ];
                    }
                }

                /** @var Product|null $product */
                $product = Product::where('sku', $sku)->first();
                if (!$product) {
                    $missing[] = $sku;
                    $pageMissing[] = $sku;
                    continue;
                }

                $product->stock_quantity = $qty;
                $productsUpdated++;
                $pageProductsUpdated++;

                $variationsPayload = [];
                foreach ($remoteVariations as $remoteVariation) {
                    $variationSku = $remoteVariation['sku'];
                    $inventory = $remoteVariation['inventory'];
                    $variation = ProductVariation::updateOrCreate(
                        [
                            'product_id' => $product->id,
                            'sku' => $variationSku,
                        ],
                        [
                            'inventory' => $inventory,
                            'attributes' => $this->buildVariationAttributes(
                                $remoteVariation['display_name'],
                                $remoteVariation['option_text']
                            ),
                        ]
                    );

                    $variationsUpdated++;
                    $pageVariationsUpdated++;
                    $variationsPayload[] = $variation;
                    $this->report($progress, [
                        'type' => 'variation',
                        'sku' => $sku,
                        'variation_sku' => $variationSku,
                        'inventory' => $inventory,
                        'option_text' => $remoteVariation['option_text'],
                        'display_name' => $remoteVariation['display_name'],
                    ]);
                }

                // Update serialized variations column to reflect current variations
                $product->variations = $this->buildVariationsArray($product, $variationsPayload);
                $product->save();
                $this->report($progress, [
                    'type' => 'product',
                    'sku' => $sku,
                    'qty' => $qty,
                    'variations_count' => count($product->variations ?? []),
                ]);
            }

            $this->report($progress, [
                'type' => 'page_summary',
                'page' => $page,
                'items' => count($items),
                'products_updated' => $pageProductsUpdated,
                'variations_updated' => $pageVariationsUpdated,
                'missing_count' => count($pageMissing),
                'missing_skus' => array_values(array_unique($pageMissing)),
            ]);

            // Stop if fewer than page size (no more pages)
            if (count($items) < $this->pageSize) {
                break;
            }

            $page++;
            usleep(500000); // respect remote API
        }

        $toylandSync = $this->toylandInventorySyncService->sync(array_values($toylandItems));

        return [
            'pages' => $pages,
            'products_updated' => $productsUpdated,
            'variations_updated' => $variationsUpdated,
            'missing_products' => array_values(array_unique($missing)),
            'toyland_sync' => $toylandSync,
        ];
    }

    /**
     * Analyze Cashcow inventory against local products without applying updates.
     *
     * @return array{
     *     pages:int,
     *     scanned:int,
     *     unchanged:int,
     *     duplicates:int,
     *     new_from_cashcow:array<int, array{sku:string,cashcow_qty:int,cashcow_product_id:int|string|null,page:int}>,
     *     inventory_changes:array<int, array{sku:string,product_id:int,local_qty:int,cashcow_qty:int,delta:int,page:int}>
     * }
     */
    public function analyzeInventoryDiff(?callable $progress = null, ?int $pageSize = null, ?int $maxPages = null): array
    {
        if (empty($this->token) || empty($this->storeId)) {
            throw new \RuntimeException('CASHCOW_TOKEN or CASHCOW_STORE_ID is not configured.');
        }

        $effectivePageSize = $this->resolvePositiveInt($pageSize, $this->pageSize);
        $maxPages = $this->resolvePositiveInt($maxPages, null);

        $page = 1;
        $pages = 0;
        $scanned = 0;
        $unchanged = 0;
        $duplicates = 0;
        $seenSkus = [];
        $newFromCashcow = [];
        $inventoryChanges = [];

        while (true) {
            if ($maxPages !== null && $pages >= $maxPages) {
                break;
            }

            $payload = $this->fetchPage($page, $effectivePageSize);
            $items = is_array($payload['result'] ?? null) ? $payload['result'] : [];

            $this->report($progress, [
                'type' => 'page',
                'page' => $page,
                'items' => count($items),
                'page_size' => (int) ($payload['page_size'] ?? $effectivePageSize),
                'total_records' => $payload['total_records'] ?? null,
            ]);

            $pages++;
            if (empty($items)) {
                break;
            }

            $pageSkus = [];
            foreach ($items as $item) {
                $sku = trim((string) ($item['sku'] ?? ''));
                if ($sku !== '') {
                    $pageSkus[$sku] = true;
                }
            }

            $productsBySku = Product::query()
                ->select(['id', 'sku', 'stock_quantity'])
                ->whereIn('sku', array_keys($pageSkus))
                ->get()
                ->keyBy('sku');

            foreach ($items as $item) {
                $sku = trim((string) ($item['sku'] ?? ''));
                if ($sku === '') {
                    continue;
                }

                if (isset($seenSkus[$sku])) {
                    $duplicates++;
                    continue;
                }
                $seenSkus[$sku] = true;

                $scanned++;
                $remoteQty = $this->normalizeQuantity($item['qty'] ?? 0);
                $localProduct = $productsBySku->get($sku);

                if (!$localProduct) {
                    $newFromCashcow[] = [
                        'sku' => $sku,
                        'cashcow_qty' => $remoteQty,
                        'cashcow_product_id' => $item['product_id'] ?? null,
                        'page' => $page,
                    ];

                    $this->report($progress, [
                        'type' => 'new_product',
                        'sku' => $sku,
                        'cashcow_qty' => $remoteQty,
                        'cashcow_product_id' => $item['product_id'] ?? null,
                        'page' => $page,
                    ]);
                    continue;
                }

                $localQty = $this->normalizeQuantity($localProduct->stock_quantity ?? 0);
                if ($localQty === $remoteQty) {
                    $unchanged++;
                    continue;
                }

                $inventoryChanges[] = [
                    'sku' => $sku,
                    'product_id' => (int) $localProduct->id,
                    'local_qty' => $localQty,
                    'cashcow_qty' => $remoteQty,
                    'delta' => $remoteQty - $localQty,
                    'page' => $page,
                ];

                $this->report($progress, [
                    'type' => 'qty_change',
                    'sku' => $sku,
                    'product_id' => (int) $localProduct->id,
                    'local_qty' => $localQty,
                    'cashcow_qty' => $remoteQty,
                    'delta' => $remoteQty - $localQty,
                    'page' => $page,
                ]);
            }

            $this->report($progress, [
                'type' => 'page_summary',
                'page' => $page,
                'items' => count($items),
                'new_count' => count($newFromCashcow),
                'changes_count' => count($inventoryChanges),
                'unchanged' => $unchanged,
                'scanned' => $scanned,
            ]);

            if (count($items) < $effectivePageSize) {
                break;
            }

            $page++;
            usleep(500000); // respect remote API
        }

        return [
            'pages' => $pages,
            'scanned' => $scanned,
            'unchanged' => $unchanged,
            'duplicates' => $duplicates,
            'new_from_cashcow' => $newFromCashcow,
            'inventory_changes' => $inventoryChanges,
        ];
    }

    private function fetchPage(int $page, ?int $pageSize = null): array
    {
        $size = $this->resolvePositiveInt($pageSize, $this->pageSize);
        $url = "{$this->baseUrl}/Api/Products/GetQty";

        $response = Http::timeout(30)
            ->retry(3, 1000)
            ->get($url, [
                'token' => $this->token,
                'store_id' => $this->storeId,
                'page' => $page,
                'page_size' => $size,
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

    private function resolvePositiveInt($value, ?int $fallback): ?int
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        if (!is_numeric($value)) {
            return $fallback;
        }

        $intValue = (int) $value;
        if ($intValue <= 0) {
            return $fallback;
        }

        return $intValue;
    }

    private function normalizeQuantity($value): int
    {
        if (!is_numeric($value)) {
            return 0;
        }

        return (int) round((float) $value);
    }

    private function report(?callable $progress, array $event): void
    {
        if ($progress) {
            $progress($event);
        }
    }

    /**
     * Build the attributes payload for a variation (displayName => optionText).
     */
    private function buildVariationAttributes(?string $displayName, ?string $optionText): array
    {
        $displayName = $displayName !== null ? trim((string) $displayName) : null;
        $optionText = $optionText !== null ? trim((string) $optionText) : null;

        if ($displayName && $optionText) {
            return [$displayName => $optionText];
        }

        return [];
    }

    /**
     * Build the serialized variations array for the product model.
     *
     * @param Product $product
     * @param ProductVariation[] $updatedVariations
     * @return array
     */
    private function buildVariationsArray(Product $product, array $updatedVariations): array
    {
        // Ensure we have the latest variations; merge newly updated ones to avoid extra queries.
        $variations = collect($updatedVariations);

        // If nothing in this page, try existing relation to avoid emptying.
        if ($variations->isEmpty()) {
            $product->loadMissing('productVariations:id,product_id,sku,price,inventory,attributes,image');
            $variations = $product->productVariations;
        }

        if ($variations->isEmpty()) {
            return [];
        }

        return $variations
            ->map(function (ProductVariation $variation) use ($product) {
                $attributes = $variation->attributes;
                return [
                    'id' => $variation->id,
                    'sku' => $variation->sku,
                    'image' => $variation->image,
                    'price' => $variation->price ?? $product->price,
                    'inventory' => (int) $variation->inventory,
                    'attributes' => is_array($attributes) ? $attributes : [],
                ];
            })
            ->values()
            ->all();
    }
}
