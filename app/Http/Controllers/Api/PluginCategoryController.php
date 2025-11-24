<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Traits\ApiResponse;
use App\Traits\HandlesPluginPricing;
use App\Traits\ResolvesPluginSite;
use Illuminate\Http\Request;

class PluginCategoryController extends Controller
{
    use ApiResponse;
    use HandlesPluginPricing;
    use ResolvesPluginSite;

    public function index(Request $request)
    {
        $user = $request->user();
        $site = $this->resolveMerchantSiteOrFail($request, $user->id);

        $activeOnly = $request->has('active_only')
            ? $request->boolean('active_only')
            : true;
        $withProductsOnly = $request->has('with_products_only')
            ? $request->boolean('with_products_only')
            : true;
        $inStockOnly = $request->has('in_stock_only')
            ? $request->boolean('in_stock_only')
            : false;

        $categories = Category::query()
            ->select(['id', 'name', 'parent_id', 'is_active', 'sort_order'])
            ->with([
                'parent:id,name',
                'children:id,name,parent_id,is_active,sort_order',
                'products' => function ($query) use ($activeOnly, $inStockOnly) {
                    $query->select([
                        'id',
                        'name',
                        'category_id',
                        'is_active',
                        'stock_quantity',
                        'plugin_site_prices',
                        'merchant_prices',
                        'price',
                        'sale_price',
                        'min_stock_alert',
                        'sku',
                    ]);

                    if ($activeOnly) {
                        $query->where('is_active', true);
                    }

                    if ($inStockOnly) {
                        $query->where('stock_quantity', '>', 0);
                    }
                },
            ])
            ->when($activeOnly, function ($query) {
                $query->where('is_active', true);
            })
            ->orderBy('sort_order')
            ->get()
            ->map(function (Category $category) use ($site, $withProductsOnly) {
                $availableProducts = $category->products
                    ->filter(fn ($product) => $this->isProductAvailableForPluginSite($product, $site->id));

                if ($withProductsOnly && $availableProducts->isEmpty()) {
                    return null;
                }

                return $this->formatCategoryPayload($category, $availableProducts->count());
            })
            ->filter()
            ->values();

        return $this->successResponse([
            'site' => $this->formatSitePayload($site),
            'filters' => [
                'active_only' => $activeOnly,
                'with_products_only' => $withProductsOnly,
                'in_stock_only' => $inStockOnly,
            ],
            'items' => $categories,
        ]);
    }

    public function show(Request $request, Category $category)
    {
        $user = $request->user();
        $site = $this->resolveMerchantSiteOrFail($request, $user->id);

        if (!$category->is_active) {
            return $this->errorResponse('Category is not active.', 404);
        }

        $inStockOnly = $request->has('in_stock_only')
            ? $request->boolean('in_stock_only')
            : false;

        $category->loadMissing([
            'parent:id,name',
            'children:id,name,parent_id,is_active,sort_order',
            'products' => function ($query) use ($inStockOnly) {
                $query->select([
                    'id',
                    'name',
                    'category_id',
                    'is_active',
                    'stock_quantity',
                    'plugin_site_prices',
                    'merchant_prices',
                    'price',
                    'sale_price',
                    'min_stock_alert',
                    'sku',
                ])->where('is_active', true);

                if ($inStockOnly) {
                    $query->where('stock_quantity', '>', 0);
                }
            },
        ]);

        $availableProducts = $category->products
            ->filter(fn ($product) => $this->isProductAvailableForPluginSite($product, $site->id));

        return $this->successResponse([
            'site' => $this->formatSitePayload($site),
            'category' => $this->formatCategoryPayload($category, $availableProducts->count()),
        ]);
    }

    protected function formatCategoryPayload(Category $category, int $productCount): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'parent_id' => $category->parent_id,
            'is_active' => (bool) $category->is_active,
            'sort_order' => $category->sort_order !== null ? (int) $category->sort_order : null,
            'product_count' => $productCount,
            'parent' => $category->parent ? [
                'id' => $category->parent->id,
                'name' => $category->parent->name,
            ] : null,
            'children' => $category->children->map(function (Category $child) {
                return [
                    'id' => $child->id,
                    'name' => $child->name,
                    'parent_id' => $child->parent_id,
                    'is_active' => (bool) $child->is_active,
                    'sort_order' => $child->sort_order !== null ? (int) $child->sort_order : null,
                ];
            })->values()->toArray(),
        ];
    }
}
