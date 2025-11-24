<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Traits\ApiResponse;
use App\Traits\HandlesPluginPricing;
use App\Traits\ResolvesPluginSite;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PluginProductController extends Controller
{
    use ApiResponse;
    use HandlesPluginPricing;
    use ResolvesPluginSite;

    /**
     * Return a paginated list of products tailored for a specific plugin site.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $site = $this->resolveMerchantSiteOrFail($request, $user->id);
        $siteId = $site->id;
        $category = $this->resolveCategoryOrFail($request);

        $perPage = (int) $request->query('per_page', 50);
        $perPage = max(1, min($perPage, 200));
        $search = trim((string) $request->query('search', ''));

        $activeOnly = $request->has('active_only')
            ? $request->boolean('active_only')
            : true;
        $inStockOnly = $request->has('in_stock_only')
            ? $request->boolean('in_stock_only')
            : false;

        $query = Product::query()
            ->with([
                'category:id,name',
                'productVariations:id,product_id,sku,inventory,price,attributes,image',
            ])
            ->where('category_id', $category->id)
            ->orderByDesc('updated_at');

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        if ($inStockOnly) {
            $query->where('stock_quantity', '>', 0);
        }

        if ($search !== '') {
            $query->where(function ($inner) use ($search) {
                $inner->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $products = $query->paginate($perPage);

        $collection = $products->getCollection()
            ->map(function (Product $product) use ($siteId) {
                return $this->formatProductPayload($product, $siteId);
            })
            ->filter(function (array $payload) {
                return $payload['available_for_site'] ?? true;
            })
            ->values();

        $products->setCollection($collection);

        return $this->successResponse([
            'site' => $this->formatSitePayload($site),
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
            ],
            'filters' => [
                'search' => $search !== '' ? $search : null,
                'active_only' => $activeOnly,
                'in_stock_only' => $inStockOnly,
                'category_id' => $category->id,
            ],
            'pagination' => [
                'current_page' => $products->currentPage(),
                'per_page' => $products->perPage(),
                'last_page' => $products->lastPage(),
                'total' => $products->total(),
            ],
            'items' => $products->items(),
        ]);
    }

    /**
     * Return a single product with plugin-ready pricing.
     */
    public function show(Request $request, Product $product)
    {
        $user = $request->user();
        $site = $this->resolveMerchantSiteOrFail($request, $user->id);
        $category = $this->resolveCategoryOrFail($request);

        $product->loadMissing([
            'category:id,name',
            'productVariations:id,product_id,sku,inventory,price,attributes,image',
        ]);

        if ((int) $product->category_id !== $category->id) {
            return $this->errorResponse('Product does not belong to the provided category.', 404);
        }

        $payload = $this->formatProductPayload($product, $site->id);

        if (!$payload['available_for_site']) {
            return $this->errorResponse('Product is not available for this plugin site.', 404);
        }

        return $this->successResponse([
            'site' => $this->formatSitePayload($site),
            'product' => $payload,
        ]);
    }

    /**
     * Snapshot containing SKU and inventory for every product.
     */
    public function inventory(Request $request)
    {
        $user = $request->user();
        $site = $this->resolveMerchantSiteOrFail($request, $user->id);
        $category = $this->resolveCategoryOrFail($request);

        $activeOnly = $request->has('active_only')
            ? $request->boolean('active_only')
            : true;

        $products = Product::query()
            ->select(['id', 'name', 'sku', 'images', 'stock_quantity', 'is_active', 'updated_at'])
            ->with(['productVariations:id,product_id,sku,inventory,image'])
            ->where('category_id', $category->id)
            ->when($activeOnly, function ($query) {
                $query->where('is_active', true);
            })
            ->orderBy('id')
            ->get()
            ->map(function (Product $product) use ($site) {
                return $this->formatInventoryPayload($product, $site->id);
            })
            ->filter(function (array $payload) {
                return $payload['available_for_site'] ?? true;
            })
            ->values();

        return $this->successResponse([
            'site' => $this->formatSitePayload($site),
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
            ],
            'filters' => [
                'active_only' => $activeOnly,
                'category_id' => $category->id,
            ],
            'generated_at' => now()->toIso8601String(),
            'items' => $products,
        ]);
    }

    /**
     * Snapshot with SKU and inventory for a single product.
     */
    public function productInventory(Request $request, Product $product)
    {
        $user = $request->user();
        $site = $this->resolveMerchantSiteOrFail($request, $user->id);
        $category = $this->resolveCategoryOrFail($request);

        $product->loadMissing(['productVariations:id,product_id,sku,inventory,image']);

        if ((int) $product->category_id !== $category->id) {
            return $this->errorResponse('Product does not belong to the provided category.', 404);
        }

        return $this->successResponse([
            'site' => $this->formatSitePayload($site),
            'product' => $this->formatInventoryPayload($product, $site->id),
        ]);
    }

    protected function resolveCategoryOrFail(Request $request): Category
    {
        $categoryId = (int) $request->query('category_id', 0);

        if ($categoryId <= 0) {
            throw ValidationException::withMessages([
                'category_id' => ['You must provide a valid category_id.'],
            ]);
        }

        $category = Category::whereKey($categoryId)
            ->where('is_active', true)
            ->first();

        if (!$category) {
            throw ValidationException::withMessages([
                'category_id' => ['Category not found or inactive.'],
            ]);
        }

        return $category;
    }

    protected function formatProductPayload(Product $product, int $siteId): array
    {
        $product->loadMissing(['productVariations']);

        $available = $this->isProductAvailableForPluginSite($product, $siteId);

        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'description' => $product->description,
            'images' => $product->images ?? [],
            'is_active' => (bool) $product->is_active,
            'stock_quantity' => (int) $product->stock_quantity,
            'category' => $product->category ? [
                'id' => $product->category->id,
                'name' => $product->category->name,
            ] : null,
            'available_for_site' => $available,
            'variations' => $product->productVariations->map(function (ProductVariation $variation) {
                return [
                    'id' => $variation->id,
                    'sku' => $variation->sku,
                    'inventory' => (int) $variation->inventory,
                    'attributes' => $variation->attributes ?? [],
                    'image' => $variation->image,
                ];
            })->values()->toArray(),
            'updated_at' => optional($product->updated_at)->toIso8601String(),
        ];
    }

    protected function formatInventoryPayload(Product $product, int $siteId): array
    {
        $product->loadMissing(['productVariations']);

        return [
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'images' => $product->images ?? [],
            'stock_quantity' => (int) $product->stock_quantity,
            'is_active' => (bool) $product->is_active,
            'available_for_site' => $this->isProductAvailableForPluginSite($product, $siteId),
            'variations' => $product->productVariations->map(function (ProductVariation $variation) {
                return [
                    'id' => $variation->id,
                    'sku' => $variation->sku,
                    'inventory' => (int) $variation->inventory,
                    'image' => $variation->image,
                ];
            })->values()->toArray(),
            'updated_at' => optional($product->updated_at)->toIso8601String(),
        ];
    }
}
