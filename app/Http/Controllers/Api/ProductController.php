<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\Product;
use App\Models\Category;
use App\Models\Merchant;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    use ApiResponse;

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Product::with(['category']);

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
            'weight' => 'nullable|string',
            'dimensions' => 'nullable|string',
            'merchant_prices' => 'nullable|array',
            'merchant_prices.*.merchant_id' => 'required|integer|exists:users,id',
            'merchant_prices.*.price' => 'required|numeric|min:0',
            'merchant_prices.*.merchant_name' => 'nullable|string|max:255',
        ]);

        if ($request->has('merchant_prices')) {
            $validated['merchant_prices'] = $this->normalizeMerchantPrices(
                $request->input('merchant_prices', [])
            );
        }

        $product = Product::create($validated);

        return $this->createdResponse($product->load('category'), 'Product created successfully');
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $product = Product::with(['category'])->findOrFail($id);

        return $this->successResponse($product);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $product = Product::findOrFail($id);

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
            'weight' => 'nullable|string',
            'dimensions' => 'nullable|string',
            'merchant_prices' => 'nullable|array',
            'merchant_prices.*.merchant_id' => 'required|integer|exists:users,id',
            'merchant_prices.*.price' => 'required|numeric|min:0',
            'merchant_prices.*.merchant_name' => 'nullable|string|max:255',
        ]);

        if ($request->has('merchant_prices')) {
            $validated['merchant_prices'] = $this->normalizeMerchantPrices(
                $request->input('merchant_prices', [])
            );
        }

        $product->update($validated);

        return $this->successResponse($product->load('category'), 'Product updated successfully');
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
}
