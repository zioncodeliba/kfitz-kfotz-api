<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MerchantSite;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Traits\ApiResponse;
use App\Traits\HandlesPluginPricing;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PluginProductController extends Controller
{
    use ApiResponse;
    use HandlesPluginPricing;

    /**
     * Return a paginated list of products tailored for a specific plugin site.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $site = $this->resolveMerchantSiteOrFail($request, $user->id);
        $siteId = $site->id;

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
            'filters' => [
                'search' => $search !== '' ? $search : null,
                'active_only' => $activeOnly,
                'in_stock_only' => $inStockOnly,
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

        $product->loadMissing([
            'category:id,name',
            'productVariations:id,product_id,sku,inventory,price,attributes,image',
        ]);

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

        $activeOnly = $request->has('active_only')
            ? $request->boolean('active_only')
            : true;

        $products = Product::query()
            ->select(['id', 'name', 'sku', 'stock_quantity', 'is_active', 'updated_at'])
            ->with(['productVariations:id,product_id,sku,inventory'])
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
            'filters' => [
                'active_only' => $activeOnly,
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

        $product->loadMissing(['productVariations:id,product_id,sku,inventory']);

        return $this->successResponse([
            'site' => $this->formatSitePayload($site),
            'product' => $this->formatInventoryPayload($product, $site->id),
        ]);
    }

    protected function resolveMerchantSiteOrFail(Request $request, int $merchantUserId): MerchantSite
    {
        $siteId = (int) $request->query('site_id', 0);
        $siteUrl = trim((string) $request->query('site_url', ''));

        if ($siteId <= 0 && $siteUrl === '') {
            throw ValidationException::withMessages([
                'site' => ['You must provide either site_id or site_url to target a plugin site.'],
            ]);
        }

        $query = MerchantSite::where('user_id', $merchantUserId);

        if ($siteId > 0) {
            $query->where('id', $siteId);
        }

        if ($siteUrl !== '') {
            $query->where('site_url', $siteUrl);
        }

        $site = $query->first();

        if (!$site) {
            throw ValidationException::withMessages([
                'site' => ['Plugin site not found for the authenticated merchant.'],
            ]);
        }

        return $site;
    }

    protected function formatSitePayload(MerchantSite $site): array
    {
        return [
            'id' => $site->id,
            'site_url' => $site->site_url,
            'name' => $site->name,
            'status' => $site->status,
        ];
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
            'stock_quantity' => (int) $product->stock_quantity,
            'is_active' => (bool) $product->is_active,
            'available_for_site' => $this->isProductAvailableForPluginSite($product, $siteId),
            'variations' => $product->productVariations->map(function (ProductVariation $variation) {
                return [
                    'id' => $variation->id,
                    'sku' => $variation->sku,
                    'inventory' => (int) $variation->inventory,
                ];
            })->values()->toArray(),
            'updated_at' => optional($product->updated_at)->toIso8601String(),
        ];
    }
}
