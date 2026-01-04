<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\Product;
use App\Models\Category;
use App\Models\Merchant;
use App\Models\MerchantSite;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Services\EmailTemplateService;
use App\Services\CashcowProductPushService;

class ProductController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected EmailTemplateService $emailTemplateService,
        protected CashcowProductPushService $cashcowProductPushService
    ) {
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Product::with(['category', 'shippingType']);

        // Filter by category
        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        // Filter by active status
        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        // Filter by featured
        if ($request->has('featured')) {
            $query->where('is_featured', $request->boolean('featured'));
        }

        // Filter by stock status
        if ($request->has('in_stock')) {
            if ($request->boolean('in_stock')) {
                $query->inStock();
            } else {
                $query->where('stock_quantity', '<=', 0);
            }
        }

        // Filter by low stock
        if ($request->has('low_stock')) {
            $query->lowStock();
        }

        // Search by name or description
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        $products = $query->paginate($request->get('per_page', 15));

        return $this->successResponse($products);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'sku' => 'required|string|unique:products,sku',
            'price' => 'required|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0',
            'stock_quantity' => 'required|integer|min:0',
            'min_stock_alert' => 'integer|min:0',
            'category_id' => 'required|exists:categories,id',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'images' => 'nullable|array',
            'variations' => 'nullable|array',
            'variations.*.id' => 'nullable|integer|exists:product_variations,id',
            'variations.*.sku' => 'nullable|string|max:255',
            'variations.*.inventory' => 'nullable|integer|min:0',
            'variations.*.price' => 'nullable|numeric|min:0',
            'variations.*.attributes' => 'nullable|array',
            'variations.*.image' => 'nullable|string|max:1024',
            'weight' => 'nullable|string',
            'dimensions' => 'nullable|string',
            'merchant_prices' => 'nullable|array',
            'merchant_prices.*.merchant_id' => 'required|integer|exists:users,id',
            'merchant_prices.*.price' => 'required|numeric|min:0',
            'merchant_prices.*.merchant_name' => 'nullable|string|max:255',
            'plugin_site_prices' => 'nullable|array',
            'plugin_site_prices.*.site_id' => 'required|integer|exists:merchant_sites,id',
            'plugin_site_prices.*.price' => 'required|numeric|min:0',
            'plugin_site_prices.*.site_name' => 'nullable|string|max:255',
            'plugin_site_prices.*.is_enabled' => 'nullable|boolean',
            'shipping_type_id' => 'nullable|exists:shipping_types,id',
        ]);

        if ($request->has('merchant_prices')) {
            $validated['merchant_prices'] = $this->normalizeMerchantPrices(
                $request->input('merchant_prices', [])
            );
        }

        if ($request->has('plugin_site_prices')) {
            $validated['plugin_site_prices'] = $this->normalizePluginSitePrices(
                $request->input('plugin_site_prices', [])
            );
        }

        $variationPayload = [];
        if ($request->has('variations')) {
            $variationPayload = $this->normalizeProductVariations(
                $request->input('variations', [])
            );
            unset($validated['variations']);
        }

        $product = Product::create($validated);

        if ($request->has('variations')) {
            $this->syncProductVariations($product, $variationPayload);
        }

        $product->load(['category', 'productVariations', 'shippingType']);

        $this->pushProductToCashcow($product);

        return $this->createdResponse($product, 'Product created successfully');
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $product = Product::with(['category', 'productVariations', 'shippingType'])->findOrFail($id);

        return $this->successResponse($product);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $product = Product::findOrFail($id);
        $wasOutOfStock = $product->stock_quantity <= 0;

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'sku' => 'sometimes|required|string|unique:products,sku,' . $id,
            'price' => 'sometimes|required|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0',
            'stock_quantity' => 'sometimes|required|integer|min:0',
            'min_stock_alert' => 'integer|min:0',
            'category_id' => 'sometimes|required|exists:categories,id',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'images' => 'nullable|array',
            'variations' => 'nullable|array',
            'variations.*.id' => 'nullable|integer|exists:product_variations,id',
            'variations.*.sku' => 'nullable|string|max:255',
            'variations.*.inventory' => 'nullable|integer|min:0',
            'variations.*.price' => 'nullable|numeric|min:0',
            'variations.*.attributes' => 'nullable|array',
            'variations.*.image' => 'nullable|string|max:1024',
            'weight' => 'nullable|string',
            'dimensions' => 'nullable|string',
            'merchant_prices' => 'nullable|array',
            'merchant_prices.*.merchant_id' => 'required|integer|exists:users,id',
            'merchant_prices.*.price' => 'required|numeric|min:0',
            'merchant_prices.*.merchant_name' => 'nullable|string|max:255',
            'plugin_site_prices' => 'nullable|array',
            'plugin_site_prices.*.site_id' => 'required|integer|exists:merchant_sites,id',
            'plugin_site_prices.*.price' => 'required|numeric|min:0',
            'plugin_site_prices.*.site_name' => 'nullable|string|max:255',
            'plugin_site_prices.*.is_enabled' => 'nullable|boolean',
            'shipping_type_id' => 'nullable|exists:shipping_types,id',
        ]);

        if ($request->has('merchant_prices')) {
            $validated['merchant_prices'] = $this->normalizeMerchantPrices(
                $request->input('merchant_prices', [])
            );
        }

        if ($request->has('plugin_site_prices')) {
            $validated['plugin_site_prices'] = $this->normalizePluginSitePrices(
                $request->input('plugin_site_prices', [])
            );
        }

        $variationPayload = null;
        if ($request->has('variations')) {
            $variationPayload = $this->normalizeProductVariations(
                $request->input('variations', [])
            );
            unset($validated['variations']);
        }

        $product->update($validated);

        if ($wasOutOfStock && $product->stock_quantity > 0) {
            $product->forceFill([
                'restocked_at' => now(),
                'restocked_initial_stock' => $product->stock_quantity,
            ])->save();
        } elseif ($product->stock_quantity <= 0 && ($product->restocked_at || $product->restocked_initial_stock)) {
            $product->forceFill([
                'restocked_at' => null,
                'restocked_initial_stock' => null,
            ])->save();
        }

        if ($variationPayload !== null) {
            $this->syncProductVariations($product, $variationPayload);
        }

        $product->load(['category', 'productVariations', 'shippingType']);

        $cashcowFields = $this->resolveCashcowDeltaFields($request);
        if (!empty($cashcowFields)) {
            try {
                $this->pushProductDeltaToCashcow($product, $cashcowFields);
            } catch (\Throwable $exception) {
                Log::warning('Cashcow product delta push failed', [
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'fields' => $cashcowFields,
                    'error' => $exception->getMessage(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Cashcow update failed.',
                    'cashcow_error' => $exception->getMessage(),
                ], 502);
            }
        }

        if ($wasOutOfStock && $product->stock_quantity > 0) {
            $this->notifyProductBackInStock($product);
        }

        return $this->successResponse($product, 'Product updated successfully');
    }

    protected function notifyProductBackInStock(Product $product): void
    {
        try {
            $product->loadMissing('category');
            $payload = $this->buildProductBackInStockPayload($product);
            $this->emailTemplateService->send('product.back_in_stock', $payload);
        } catch (\Throwable $exception) {
            Log::warning('Failed to send product back in stock notification', [
                'product_id' => $product->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    protected function pushProductToCashcow(Product $product): void
    {
        try {
            $result = $this->cashcowProductPushService->createProduct($product);
            if (isset($result['success']) && $result['success'] === false) {
                Log::warning('Cashcow product push returned unsuccessful response', [
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'response' => $result,
                ]);
            }
        } catch (\Throwable $exception) {
            Log::warning('Cashcow product push failed', [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param array<int, string> $fields
     */
    protected function pushProductDeltaToCashcow(Product $product, array $fields): void
    {
        $result = $this->cashcowProductPushService->updateProductDelta($product, $fields);

        if (isset($result['status']) && $result['status'] === 'skipped') {
            $reason = $this->formatCashcowSkipReason($result['reason'] ?? null);
            throw new \RuntimeException($reason);
        }

        if (isset($result['success']) && $result['success'] === false) {
            $error = $result['error'] ?? 'Unknown error';
            if (!is_string($error)) {
                $error = json_encode($error);
            }
            throw new \RuntimeException('Cashcow API error: ' . $error);
        }
    }

    /**
     * @return array<int, string>
     */
    protected function resolveCashcowDeltaFields(Request $request): array
    {
        $fields = [];

        if ($request->has('name')) {
            $fields[] = 'title';
        }

        if ($request->has('description')) {
            $fields[] = 'short_description';
            $fields[] = 'long_description';
        }

        if ($request->has('category_id')) {
            $fields[] = 'main_category_name';
        }

        if ($request->has('stock_quantity')) {
            $fields[] = 'qty';
        }

        if ($request->has('is_active')) {
            $fields[] = 'is_visible';
        }

        if ($request->has('variations')) {
            $fields[] = 'attributes';
            $fields[] = 'attributes_matrix';
        }

        return array_values(array_unique($fields));
    }

    protected function formatCashcowSkipReason(?string $reason): string
    {
        return match ($reason) {
            'not_configured' => 'Cashcow configuration is missing (CASHCOW_TOKEN/CASHCOW_STORE_ID).',
            'missing_sku' => 'Product SKU is missing.',
            'no_fields' => 'No fields were provided for Cashcow update.',
            default => $reason
                ? 'Cashcow update skipped: ' . $reason . '.'
                : 'Cashcow update skipped for an unknown reason.',
        };
    }

    protected function buildProductBackInStockPayload(Product $product): array
    {
        return [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'stock_quantity' => $product->stock_quantity,
                'min_stock_alert' => $product->min_stock_alert,
                'price' => $product->price,
            ],
            'category' => [
                'name' => optional($product->category)->name,
            ],
        ];
    }

    /**
     * Normalize merchant price payload.
     */
    protected function normalizeMerchantPrices(array $rawPrices): array
    {
        if (empty($rawPrices)) {
            return [];
        }

        $rows = [];
        foreach ($rawPrices as $row) {
            if (is_object($row)) {
                $row = (array) $row;
            }
            if (!is_array($row)) {
                continue;
            }
            $rows[] = $row;
        }

        if (empty($rows)) {
            return [];
        }

        $merchantIds = [];
        foreach ($rows as $row) {
            if (!isset($row['merchant_id'])) {
                continue;
            }
            $merchantId = (int) $row['merchant_id'];
            if ($merchantId > 0) {
                $merchantIds[] = $merchantId;
            }
        }

        $merchantIds = array_values(array_unique($merchantIds));

        $namesByUserId = [];
        if (!empty($merchantIds)) {
            $namesByUserId = Merchant::whereIn('user_id', $merchantIds)
                ->get(['user_id', 'business_name'])
                ->mapWithKeys(function (Merchant $merchant) {
                    $name = $merchant->business_name ? trim($merchant->business_name) : null;
                    return [$merchant->user_id => $name];
                })
                ->toArray();
        }

        $normalized = [];

        foreach ($rows as $row) {
            if (!isset($row['merchant_id'], $row['price'])) {
                continue;
            }

            $merchantId = (int) $row['merchant_id'];
            if ($merchantId <= 0) {
                continue;
            }

            $price = is_numeric($row['price']) ? (float) $row['price'] : null;
            if ($price === null || $price < 0) {
                continue;
            }

            $merchantName = null;
            if (isset($row['merchant_name']) && is_string($row['merchant_name'])) {
                $merchantName = trim($row['merchant_name']);
            }

            if (!$merchantName && isset($namesByUserId[$merchantId])) {
                $merchantName = $namesByUserId[$merchantId];
            }

            $normalized[$merchantId] = [
                'merchant_id' => $merchantId,
                'price' => round($price, 2),
                'merchant_name' => $merchantName,
            ];
        }

        return array_values($normalized);
    }

    protected function normalizePluginSitePrices(array $rawPrices): array
    {
        if (empty($rawPrices)) {
            return [];
        }

        $rows = [];
        foreach ($rawPrices as $row) {
            if (is_object($row)) {
                $row = (array) $row;
            }
            if (!is_array($row)) {
                continue;
            }
            $rows[] = $row;
        }

        if (empty($rows)) {
            return [];
        }

        $siteIds = [];
        foreach ($rows as $row) {
            if (!isset($row['site_id'])) {
                continue;
            }
            $siteId = (int) $row['site_id'];
            if ($siteId > 0) {
                $siteIds[] = $siteId;
            }
        }

        $siteIds = array_values(array_unique($siteIds));

        $namesBySiteId = [];
        if (!empty($siteIds)) {
            $namesBySiteId = MerchantSite::whereIn('id', $siteIds)
                ->get(['id', 'name', 'site_url'])
                ->mapWithKeys(function (MerchantSite $site) {
                    $labelParts = [];
                    if ($site->name) {
                        $labelParts[] = trim($site->name);
                    }
                    if ($site->site_url) {
                        $labelParts[] = trim($site->site_url);
                    }
                    $label = implode(' · ', array_filter($labelParts));
                    return [$site->id => ($label !== '' ? $label : null)];
                })
                ->toArray();
        }

        $normalized = [];
        $seen = [];

        foreach ($rows as $row) {
            if (!isset($row['site_id'], $row['price'])) {
                continue;
            }

            $siteId = (int) $row['site_id'];
            if ($siteId <= 0 || isset($seen[$siteId])) {
                continue;
            }

            $priceValue = $row['price'];
            if (is_string($priceValue)) {
                $priceValue = (float) str_replace(',', '', $priceValue);
            }
            $price = is_numeric($priceValue) ? (float) $priceValue : null;
            if ($price === null || $price < 0) {
                continue;
            }

            $isEnabled = array_key_exists('is_enabled', $row)
                ? filter_var($row['is_enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                : true;

            $siteNameCandidate = isset($row['site_name']) ? trim((string) $row['site_name']) : '';
            $siteName = $siteNameCandidate !== ''
                ? $siteNameCandidate
                : ($namesBySiteId[$siteId] ?? null);

            $normalized[] = array_filter([
                'site_id' => $siteId,
                'price' => round($price, 2),
                'is_enabled' => $isEnabled !== false,
                'site_name' => $siteName,
            ], static function ($value) {
                return $value !== null;
            });

            $seen[$siteId] = true;
        }

        return $normalized;
    }

    protected function normalizeProductVariations(array $rawVariations): array
    {
        if (empty($rawVariations)) {
            return [];
        }

        $rows = [];
        foreach ($rawVariations as $row) {
            if (is_object($row)) {
                $row = (array) $row;
            }
            if (!is_array($row)) {
                continue;
            }
            $rows[] = $row;
        }

        if (empty($rows)) {
            return [];
        }

        $normalized = [];

        foreach ($rows as $row) {
            $id = null;
            if (isset($row['id'])) {
                $candidate = filter_var($row['id'], FILTER_VALIDATE_INT);
                if (is_int($candidate) && $candidate > 0) {
                    $id = $candidate;
                }
            }

            $sku = isset($row['sku']) ? trim((string) $row['sku']) : null;
            $sku = $sku !== '' ? $sku : null;

            $inventory = 0;
            if (isset($row['inventory'])) {
                if (is_numeric($row['inventory'])) {
                    $inventory = (int) max(0, (int) $row['inventory']);
                }
            }

            $price = null;
            if (isset($row['price']) && is_numeric($row['price'])) {
                $price = round((float) $row['price'], 2);
            }

            $attributes = [];
            if (isset($row['attributes']) && is_array($row['attributes'])) {
                foreach ($row['attributes'] as $key => $value) {
                    if (!is_string($key) || $key === '') {
                        continue;
                    }
                    if (is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
                        $attributes[$key] = trim((string) $value);
                    }
                }
            }

            $image = isset($row['image']) ? trim((string) $row['image']) : null;
            $image = $image !== '' ? $image : null;

            $normalized[] = [
                'id' => $id,
                'sku' => $sku,
                'inventory' => $inventory,
                'price' => $price,
                'attributes' => $attributes,
                'image' => $image,
            ];
        }

        return $normalized;
    }

    protected function syncProductVariations(Product $product, array $variations): void
    {
        $existing = $product->productVariations()->get()->keyBy('id');
        $retainedIds = [];

        foreach ($variations as $variation) {
            $payload = [
                'sku' => $variation['sku'] ?? null,
                'inventory' => $variation['inventory'] ?? 0,
                'price' => $variation['price'] ?? null,
                'attributes' => $variation['attributes'] ?? [],
                'image' => $variation['image'] ?? null,
            ];

            $variationId = $variation['id'] ?? null;
            if ($variationId && $existing->has($variationId)) {
                $record = $existing->get($variationId);
                if ($record->product_id !== $product->id) {
                    continue;
                }
                $record->fill($payload)->save();
                $retainedIds[] = $record->id;
                continue;
            }

            $created = $product->productVariations()->create($payload);
            $retainedIds[] = $created->id;
        }

        if (!empty($retainedIds)) {
            $product->productVariations()->whereNotIn('id', $retainedIds)->delete();
        } else {
            $product->productVariations()->delete();
        }

        $this->refreshProductVariationsSnapshot($product);
    }

    protected function refreshProductVariationsSnapshot(Product $product): void
    {
        $collection = $product->productVariations()->orderBy('id')->get();

        $snapshot = $collection->map(function ($variation) {
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

    /**
     * Upload a product image and store it on disk.
     */
    public function uploadImage(Request $request)
    {
        $request->validate([
            'image' => 'required|file|image|max:5120',
        ]);

        $file = $request->file('image');
        $filename = uniqid('product_', true) . '.' . $file->getClientOriginalExtension();
        $relativePath = 'images/' . $filename;

        Storage::disk('local')->putFileAs('images', $file, $filename);

        $url = url('/api/product-images/' . $relativePath);

        return $this->createdResponse([
            'path' => $relativePath,
            'url' => $url,
            'original_name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
        ], 'Image uploaded successfully');
    }

    /**
     * Serve a stored product image.
     */
    public function serveImage(string $path)
    {
        $decodedPath = urldecode($path);
        $normalized = ltrim($decodedPath, '/');

        if (str_contains($normalized, '..')) {
            abort(404);
        }

        if (!Str::startsWith($normalized, 'images/')) {
            $normalized = 'images/' . $normalized;
        }

        if (!Storage::disk('local')->exists($normalized)) {
            abort(404);
        }

        $absolutePath = Storage::disk('local')->path($normalized);
        $mimeType = mime_content_type($absolutePath) ?: 'application/octet-stream';

        return response()->file($absolutePath, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'max-age=604800, public',
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $product = Product::findOrFail($id);
        $product->delete();

        return $this->successResponse(null, 'Product deleted successfully');
    }

    /**
     * Get featured products
     */
    public function featured()
    {
        $products = Product::with('category')
            ->featured()
            ->active()
            ->inStock()
            ->get();

        return $this->successResponse($products);
    }

    /**
     * Get low stock products
     */
    public function lowStock()
    {
        $products = Product::with('category')
            ->lowStock()
            ->get();

        return $this->successResponse($products);
    }

    /**
     * Get products that recently came back in stock and have not yet sold 10% of the restocked quantity.
     */
    public function backInStock()
    {
        $products = Product::query()
            ->select([
                'id',
                'name',
                'sku',
                'stock_quantity',
                'min_stock_alert',
                'restocked_initial_stock',
                'restocked_at',
            ])
            ->whereNotNull('restocked_at')
            ->where('restocked_initial_stock', '>', 0)
            ->where('stock_quantity', '>', 0)
            ->whereRaw(
                '(restocked_initial_stock - stock_quantity) < (CASE WHEN restocked_initial_stock < 10 THEN 1 ELSE CEIL(restocked_initial_stock * 0.1) END)'
            )
            ->orderByDesc('restocked_at')
            ->get();

        return $this->successResponse($products);
    }
}
