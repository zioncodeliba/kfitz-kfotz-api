<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\MerchantSite;
use App\Models\SystemSetting;
use App\Traits\HandlesPluginPricing;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class CashcowProductPushService
{
    use HandlesPluginPricing;

    private const MAX_SAMPLE_ITEMS = 50;

    protected string $baseUrl;
    protected string $token;
    protected string $storeId;
    protected int $chunkSize;
    protected string $priceField;
    protected string $imageField;
    protected bool $priceSiteResolved = false;
    protected ?int $priceSiteId = null;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('cashcow.base_url'), '/');
        $this->token = (string) config('cashcow.token');
        $this->storeId = (string) config('cashcow.store_id');
        $this->chunkSize = (int) config('cashcow.push_chunk_size', 100);
        $this->priceField = (string) config('cashcow.price_field', 'price');
        $this->imageField = (string) config('cashcow.image_field', 'image_url');
    }

    public function isConfigured(): bool
    {
        return $this->token !== '' && $this->storeId !== '';
    }

    /**
     * Push inventory updates from the local system to Cashcow.
     *
     * @return array{
     *     products_processed:int,
     *     variations_processed:int,
     *     products_updated:int,
     *     variations_updated:int,
     *     skipped:int,
     *     skipped_skus:array<int, string>,
     *     errors:int,
     *     error_samples:array<int, array{sku:string, scope:string, message:string}>
     * }
     */
    public function syncInventory(callable $progress = null): array
    {
        $this->ensureConfigured();

        $processedSkus = [];
        $productsProcessed = 0;
        $variationsProcessed = 0;
        $productsUpdated = 0;
        $variationsUpdated = 0;
        $skipped = 0;
        $skippedSkus = [];
        $errors = 0;
        $errorSamples = [];

        Product::query()
            ->select(['id', 'sku', 'stock_quantity', 'is_active'])
            ->with(['productVariations:id,product_id,sku,inventory'])
            ->orderBy('id')
            ->chunkById($this->chunkSize, function (Collection $products) use (
                &$processedSkus,
                &$productsProcessed,
                &$variationsProcessed,
                &$productsUpdated,
                &$variationsUpdated,
                &$skipped,
                &$skippedSkus,
                &$errors,
                &$errorSamples,
                $progress
            ) {
                foreach ($products as $product) {
                    $productsProcessed++;
                    $sku = $this->normalizeSku($product->sku);
                    if ($sku === '') {
                        $this->recordSkip($skipped, $skippedSkus, '(missing sku)', $progress, 'product');
                        continue;
                    }

                    if (isset($processedSkus[$sku])) {
                        $this->recordSkip($skipped, $skippedSkus, $sku, $progress, 'product');
                    } else {
                        $qty = $this->normalizeQuantity($product->stock_quantity);
                        try {
                            $this->sendCreateOrUpdate($this->buildInventoryPayload($sku, $qty, (bool) $product->is_active));
                            $productsUpdated++;
                            $processedSkus[$sku] = true;
                        } catch (\Throwable $exception) {
                            $this->recordError($errors, $errorSamples, $sku, 'product', $exception->getMessage(), $progress);
                        }
                    }

                    foreach ($product->productVariations as $variation) {
                        $variationsProcessed++;
                        $variationSku = $this->normalizeSku($variation->sku);
                        if ($variationSku === '') {
                            $this->recordSkip($skipped, $skippedSkus, '(missing variation sku)', $progress, 'variation');
                            continue;
                        }

                        if (isset($processedSkus[$variationSku])) {
                            $this->recordSkip($skipped, $skippedSkus, $variationSku, $progress, 'variation');
                            continue;
                        }

                        $inventory = $this->normalizeQuantity($variation->inventory, $this->normalizeQuantity($product->stock_quantity));
                        try {
                            $this->sendCreateOrUpdate($this->buildInventoryPayload($variationSku, $inventory, (bool) $product->is_active));
                            $variationsUpdated++;
                            $processedSkus[$variationSku] = true;
                        } catch (\Throwable $exception) {
                            $this->recordError($errors, $errorSamples, $variationSku, 'variation', $exception->getMessage(), $progress);
                        }
                    }
                }
            });

        return [
            'products_processed' => $productsProcessed,
            'variations_processed' => $variationsProcessed,
            'products_updated' => $productsUpdated,
            'variations_updated' => $variationsUpdated,
            'skipped' => $skipped,
            'skipped_skus' => $skippedSkus,
            'errors' => $errors,
            'error_samples' => $errorSamples,
        ];
    }

    /**
     * Create a new product in Cashcow based on the local product data.
     */
    public function createProduct(Product $product): array
    {
        if (!$this->isConfigured()) {
            Log::warning('Cashcow product push skipped: missing configuration', [
                'product_id' => $product->id,
            ]);
            return [
                'status' => 'skipped',
                'reason' => 'not_configured',
            ];
        }

        $sku = $this->normalizeSku($product->sku);
        if ($sku === '') {
            Log::warning('Cashcow product push skipped: missing SKU', [
                'product_id' => $product->id,
            ]);
            return [
                'status' => 'skipped',
                'reason' => 'missing_sku',
            ];
        }

        $product->loadMissing([
            'category:id,name',
            'productVariations:id,product_id,sku,inventory,attributes,price',
        ]);

        $payload = $this->buildProductPayload($product, false);

        return $this->sendCreateOrUpdate($payload);
    }

    /**
     * Update an existing product in Cashcow to match the local product data.
     */
    public function updateProduct(Product $product): array
    {
        if (!$this->isConfigured()) {
            Log::warning('Cashcow product push skipped: missing configuration', [
                'product_id' => $product->id,
            ]);
            return [
                'status' => 'skipped',
                'reason' => 'not_configured',
            ];
        }

        $sku = $this->normalizeSku($product->sku);
        if ($sku === '') {
            Log::warning('Cashcow product push skipped: missing SKU', [
                'product_id' => $product->id,
            ]);
            return [
                'status' => 'skipped',
                'reason' => 'missing_sku',
            ];
        }

        $product->loadMissing([
            'category:id,name',
            'productVariations:id,product_id,sku,inventory,attributes,price',
        ]);

        $payload = $this->buildProductPayload($product, true);

        return $this->sendCreateOrUpdate($payload);
    }

    /**
     * Update only specific fields for a product in Cashcow.
     *
     * @param array<int, string> $fields
     */
    public function updateProductDelta(Product $product, array $fields): array
    {
        if (empty($fields)) {
            return [
                'status' => 'skipped',
                'reason' => 'no_fields',
            ];
        }

        if (!$this->isConfigured()) {
            Log::warning('Cashcow product push skipped: missing configuration', [
                'product_id' => $product->id,
            ]);
            return [
                'status' => 'skipped',
                'reason' => 'not_configured',
            ];
        }

        $sku = $this->normalizeSku($product->sku);
        if ($sku === '') {
            Log::warning('Cashcow product push skipped: missing SKU', [
                'product_id' => $product->id,
            ]);
            return [
                'status' => 'skipped',
                'reason' => 'missing_sku',
            ];
        }

        $product->loadMissing([
            'category:id,name',
            'productVariations:id,product_id,sku,inventory,attributes,price',
        ]);

        $payload = $this->buildProductPayload($product, true, $fields);

        return $this->sendCreateOrUpdate($payload);
    }
    private function buildInventoryPayload(string $sku, int $qty, bool $visible): array
    {
        return [
            'token' => $this->token,
            'store_id' => $this->storeId,
            'sku' => $sku,
            'is_restore_deleted_items' => false,
            'is_override_existing_product' => true,
            'is_force_delete_existing_attributes' => false,
            'qty' => $qty,
            'is_visible' => $visible,
        ];
    }

    private function buildProductPayload(Product $product, bool $overrideExisting, ?array $include = null): array
    {
        $categoryName = $product->category?->name;
        $categoryName = is_string($categoryName) ? trim($categoryName) : '';
        $categoryName = $categoryName !== '' ? $categoryName : 'General';

        $description = is_string($product->description) ? trim($product->description) : '';
        if ($description === '') {
            $description = trim((string) $product->name);
        }

        $includeMap = $include !== null ? array_fill_keys($include, true) : null;

        $payload = [
            'token' => $this->token,
            'store_id' => $this->storeId,
            'sku' => $this->normalizeSku($product->sku),
            'is_restore_deleted_items' => false,
            'is_override_existing_product' => $overrideExisting,
        ];

        if ($includeMap === null) {
            $payload['is_force_delete_existing_attributes'] = false;
        }

        if ($includeMap === null || isset($includeMap['title'])) {
            $payload['title'] = trim((string) $product->name);
        }

        if ($includeMap === null || isset($includeMap['main_category_name'])) {
            $payload['main_category_name'] = $categoryName;
        }

        if (
            $includeMap === null
            || isset($includeMap['short_description'])
            || isset($includeMap['long_description'])
        ) {
            $payload['short_description'] = $description;
            $payload['long_description'] = $description;
        }

        if ($includeMap === null || isset($includeMap['qty'])) {
            $payload['qty'] = $this->normalizeQuantity($product->stock_quantity);
        }

        if ($includeMap === null || isset($includeMap['is_visible'])) {
            $payload['is_visible'] = (bool) $product->is_active;
        }

        if ($includeMap === null || isset($includeMap['price'])) {
            $priceField = trim($this->priceField);
            if ($priceField !== '') {
                $this->setPayloadValue($payload, $priceField, $this->resolveCashcowPriceWithVat($product));
            }
        }

        if ($includeMap === null || isset($includeMap['image'])) {
            $imageField = trim($this->imageField);
            $imageUrl = $this->resolvePrimaryImageUrl($product);
            if ($imageField !== '' && $imageUrl !== null) {
                $this->setPayloadValue($payload, $imageField, $imageUrl);
            }
        }

        $includeAttributes = $includeMap === null
            || isset($includeMap['attributes'])
            || isset($includeMap['attributes_matrix']);

        if ($includeAttributes) {
            $attributesPayload = $this->buildAttributesPayload($product);
            if (!empty($attributesPayload)) {
                if ($includeMap !== null) {
                    if (!isset($includeMap['attributes'])) {
                        unset($attributesPayload['attributes']);
                    }
                    if (!isset($includeMap['attributes_matrix'])) {
                        unset($attributesPayload['attributes_matrix']);
                    }
                }

                if (!empty($attributesPayload)) {
                    $payload = array_merge($payload, $attributesPayload);
                }
            }

            if ($includeMap !== null) {
                $payload['is_force_delete_existing_attributes'] = false;
            }
        }

        return $payload;
    }

    private function resolvePrimaryImageUrl(Product $product): ?string
    {
        $images = is_array($product->images) ? $product->images : [];
        $primary = null;

        foreach ($images as $image) {
            if (!is_string($image)) {
                continue;
            }
            $trimmed = trim($image);
            if ($trimmed !== '') {
                $primary = $trimmed;
                break;
            }
        }

        if ($primary === null) {
            return null;
        }

        if (Str::startsWith($primary, ['http://', 'https://'])) {
            return $primary;
        }

        $normalized = ltrim($primary, '/');
        if (Str::startsWith($normalized, 'api/product-images/')) {
            return url('/' . $normalized);
        }

        return url('/api/product-images/' . $normalized);
    }

    private function setPayloadValue(array &$payload, string $path, mixed $value): void
    {
        $path = trim($path);
        if ($path === '') {
            return;
        }

        if (!str_contains($path, '.')) {
            $payload[$path] = $value;
            return;
        }

        $segments = array_values(array_filter(explode('.', $path), fn ($segment) => $segment !== ''));
        if (empty($segments)) {
            return;
        }

        $cursor = &$payload;
        $lastIndex = count($segments) - 1;

        foreach ($segments as $index => $segment) {
            if ($index === $lastIndex) {
                $cursor[$segment] = $value;
                break;
            }

            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }

            $cursor = &$cursor[$segment];
        }
    }

    public function resolveCashcowPriceWithVat(Product $product): float
    {
        $price = $this->resolveCashcowBasePrice($product);
        $vatRate = $this->getVatRate();
        return round($price * (1 + $vatRate), 2);
    }

    private function resolveCashcowBasePrice(Product $product): float
    {
        $price = null;
        $siteId = $this->resolveCashcowPriceSiteId();

        if ($siteId !== null) {
            $price = $this->resolvePluginSiteUnitPrice($product, $siteId);
        }

        if ($price === null) {
            $price = is_numeric($product->price) ? (float) $product->price : 0.0;
        }

        if ($price < 0) {
            $price = 0.0;
        }

        return round($price, 2);
    }

    private function resolveCashcowPriceSiteId(): ?int
    {
        if ($this->priceSiteResolved) {
            return $this->priceSiteId;
        }

        $this->priceSiteResolved = true;

        $configuredId = config('cashcow.price_site_id');
        if (is_numeric($configuredId)) {
            $siteId = (int) $configuredId;
            if ($siteId > 0) {
                $this->priceSiteId = $siteId;
                return $this->priceSiteId;
            }
        }

        $siteUrl = (string) (config('cashcow.price_site_url') ?? '');
        if ($siteUrl === '') {
            $siteUrl = (string) (config('cashcow.orders_site_url') ?? '');
        }

        $normalized = rtrim(trim($siteUrl), '/');
        if ($normalized === '') {
            return null;
        }

        $withTrailingSlash = $normalized . '/';

        $site = MerchantSite::query()
            ->where('site_url', $normalized)
            ->orWhere('site_url', $withTrailingSlash)
            ->first();

        $this->priceSiteId = $site?->id;

        return $this->priceSiteId;
    }

    private function getVatRate(): float
    {
        $setting = SystemSetting::where('key', 'shipping_pricing')->first();
        $value = is_array($setting?->value) ? $setting->value : [];
        $vat = $value['vat_rate'] ?? null;

        if (is_numeric($vat)) {
            $rate = (float) $vat;
            if ($rate < 0) {
                return 0.0;
            }
            if ($rate > 1) {
                return 1.0;
            }
            return $rate;
        }

        return 0.17;
    }

    private function buildAttributesPayload(Product $product): array
    {
        $variations = $product->productVariations
            ->filter(function (ProductVariation $variation) {
                return $this->normalizeSku($variation->sku) !== ''
                    && is_array($variation->attributes)
                    && !empty($variation->attributes);
            })
            ->values();

        if ($variations->isEmpty()) {
            return [];
        }

        $attributeKeys = [];
        foreach ($variations as $variation) {
            foreach (array_keys($variation->attributes ?? []) as $key) {
                $key = trim((string) $key);
                if ($key === '') {
                    continue;
                }
                if (!in_array($key, $attributeKeys, true)) {
                    $attributeKeys[] = $key;
                }
            }
        }

        if (empty($attributeKeys)) {
            return [];
        }

        if (count($attributeKeys) > 2) {
            Log::warning('Cashcow product push supports up to 2 attributes; extra attributes ignored.', [
                'product_id' => $product->id,
                'attributes' => $attributeKeys,
            ]);
            $attributeKeys = array_slice($attributeKeys, 0, 2);
        }

        $attributes = [];
        $identifiers = [];

        foreach ($attributeKeys as $key) {
            $values = [];
            foreach ($variations as $variation) {
                $value = $variation->attributes[$key] ?? null;
                if ($value === null) {
                    continue;
                }
                $value = trim((string) $value);
                if ($value === '') {
                    continue;
                }
                $values[] = $value;
            }

            $values = array_values(array_unique($values));
            if (empty($values)) {
                continue;
            }

            $options = [];
            foreach ($values as $value) {
                $options[] = [
                    'name' => $value,
                    'sku' => $this->buildOptionSku((string) $product->sku, $key, $value),
                    'qty' => $this->sumVariationQtyForAttributeValue(
                        $variations,
                        $key,
                        $value,
                        $this->normalizeQuantity($product->stock_quantity)
                    ),
                ];
            }

            $identifier = $this->buildAttributeIdentifier($key, (string) $product->sku);
            $identifiers[$key] = $identifier;

            $attributes[] = [
                'name' => $this->normalizeAttributeName($key),
                'friendly_name' => $this->buildFriendlyName($key),
                'internal_identifier' => $identifier,
                'attribute_type' => 2,
                'is_required' => true,
                'options' => $options,
            ];
        }

        if (empty($attributes)) {
            return [];
        }

        $payload = ['attributes' => $attributes];

        if (count($attributes) >= 2 && count($attributeKeys) >= 2) {
            $keyA = $attributeKeys[0];
            $keyB = $attributeKeys[1];
            $matrixOptions = $this->buildMatrixOptions($variations, $keyA, $keyB, $product);

            if (!empty($matrixOptions)) {
                $payload['attributes_matrix'] = [
                    'attribute_a_internal_identifier' => $identifiers[$keyA],
                    'attribute_b_internal_identifier' => $identifiers[$keyB],
                    'matrix_options' => $matrixOptions,
                ];
            }
        }

        return $payload;
    }

    private function buildMatrixOptions(Collection $variations, string $keyA, string $keyB, Product $product): array
    {
        $matrix = [];
        $seen = [];

        foreach ($variations as $variation) {
            $attributes = is_array($variation->attributes) ? $variation->attributes : [];
            $valueA = $attributes[$keyA] ?? null;
            $valueB = $attributes[$keyB] ?? null;

            $valueA = $valueA !== null ? trim((string) $valueA) : '';
            $valueB = $valueB !== null ? trim((string) $valueB) : '';
            if ($valueA === '' || $valueB === '') {
                continue;
            }

            $comboKey = $valueA . '|' . $valueB;
            if (isset($seen[$comboKey])) {
                continue;
            }

            $sku = $this->normalizeSku($variation->sku);
            if ($sku === '') {
                continue;
            }

            $qty = $this->normalizeQuantity($variation->inventory, $this->normalizeQuantity($product->stock_quantity));

            $matrix[] = [
                'to_option_a_text' => $valueA,
                'to_option_b_text' => $valueB,
                'qty' => $qty,
                'sku_for_matrix' => $sku,
            ];

            $seen[$comboKey] = true;
        }

        return $matrix;
    }

    private function sumVariationQtyForAttributeValue(
        Collection $variations,
        string $key,
        string $value,
        int $fallbackQty
    ): int {
        $total = 0;

        foreach ($variations as $variation) {
            $attributes = is_array($variation->attributes) ? $variation->attributes : [];
            $attributeValue = $attributes[$key] ?? null;
            if ($attributeValue === null) {
                continue;
            }

            $attributeValue = trim((string) $attributeValue);
            if ($attributeValue !== $value) {
                continue;
            }

            $total += $this->normalizeQuantity($variation->inventory, $fallbackQty);
        }

        return $total;
    }

    private function sendCreateOrUpdate(array $payload): array
    {
        $url = "{$this->baseUrl}/Api/Stores/CreateOrUpdatePrtoduct";

        $response = Http::timeout(30)
            ->retry(3, 1000)
            ->asJson()
            ->post($url, $payload);

        if ($response->failed()) {
            Log::error('Cashcow product push failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'sku' => $payload['sku'] ?? null,
            ]);
            $response->throw();
        }

        $data = $response->json() ?? [];

        if (is_array($data) && array_key_exists('success', $data) && !$data['success']) {
            $error = $data['error'] ?? 'Unknown error';
            if (!is_string($error)) {
                $error = json_encode($error);
            }
            throw new RuntimeException('Cashcow API error: ' . $error);
        }

        return is_array($data) ? $data : ['response' => $data];
    }

    private function ensureConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('CASHCOW_TOKEN or CASHCOW_STORE_ID is not configured.');
        }
    }

    private function normalizeQuantity($value, int $fallback = 0): int
    {
        if ($value === null) {
            return $fallback;
        }

        if (!is_numeric($value)) {
            return $fallback;
        }

        return (int) round((float) $value);
    }

    private function normalizeSku(?string $sku): string
    {
        $sku = trim((string) $sku);
        return $sku !== '' ? $sku : '';
    }

    private function normalizeIdentifierPart(string $value, string $fallback): string
    {
        $value = trim($value);
        if ($value === '') {
            return $fallback;
        }

        $ascii = Str::ascii($value);
        $ascii = preg_replace('/[^A-Za-z0-9]+/', '_', $ascii);
        $ascii = trim((string) $ascii, '_');

        if ($ascii !== '') {
            return $ascii;
        }

        $hash = substr(md5($value), 0, 8);
        if ($fallback !== '') {
            return $fallback . '_' . $hash;
        }

        return $hash;
    }

    private function buildAttributeIdentifier(string $attributeKey, string $productSku): string
    {
        $keyPart = $this->normalizeIdentifierPart($attributeKey, 'Attribute');
        $skuPart = $this->normalizeIdentifierPart($productSku, 'Product');

        return $keyPart . '.' . $skuPart;
    }

    private function normalizeAttributeName(string $attributeKey): string
    {
        $attributeKey = trim($attributeKey);
        if ($attributeKey === '') {
            return 'attribute';
        }

        $slug = Str::slug($attributeKey, '_');
        return $slug !== '' ? $slug : $attributeKey;
    }

    private function buildFriendlyName(string $attributeKey): string
    {
        $attributeKey = trim($attributeKey);
        if ($attributeKey === '') {
            return 'choose your option';
        }

        return 'choose your ' . $attributeKey;
    }

    private function buildOptionSku(string $productSku, string $attributeKey, string $value): string
    {
        $parts = [
            $this->normalizeIdentifierPart($productSku, ''),
            $this->normalizeIdentifierPart($attributeKey, ''),
            $this->normalizeIdentifierPart($value, ''),
        ];

        $parts = array_filter($parts, fn ($part) => $part !== '');
        $sku = implode('-', $parts);
        $sku = strtoupper($sku);

        return $sku !== '' ? Str::limit($sku, 60, '') : strtoupper($productSku);
    }

    private function recordSkip(int &$skipped, array &$skippedSkus, string $sku, ?callable $progress, string $scope): void
    {
        $skipped++;

        if (count($skippedSkus) < self::MAX_SAMPLE_ITEMS) {
            $skippedSkus[] = $sku;
        }

        $this->report($progress, [
            'type' => 'skipped',
            'scope' => $scope,
            'sku' => $sku,
        ]);
    }

    private function recordError(
        int &$errors,
        array &$errorSamples,
        string $sku,
        string $scope,
        string $message,
        ?callable $progress
    ): void {
        $errors++;

        if (count($errorSamples) < self::MAX_SAMPLE_ITEMS) {
            $errorSamples[] = [
                'sku' => $sku,
                'scope' => $scope,
                'message' => $message,
            ];
        }

        $this->report($progress, [
            'type' => 'error',
            'scope' => $scope,
            'sku' => $sku,
            'message' => $message,
        ]);
    }

    private function report(?callable $progress, array $event): void
    {
        if ($progress) {
            $progress($event);
        }
    }
}
