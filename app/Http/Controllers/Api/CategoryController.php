<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    use ApiResponse;

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Category::with(['children', 'parent']);

        // Filter by active status
        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        // Filter by parent (root categories only)
        if ($request->has('root_only')) {
            $query->whereNull('parent_id');
        }

        $categories = $query->orderBy('sort_order')->get();

        return $this->successResponse($categories);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'parent_id' => 'nullable|exists:categories,id',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ]);

        $category = Category::create([
            'name' => $request->name,
            'description' => $request->description,
            'parent_id' => $request->parent_id,
            'is_active' => $request->get('is_active', true),
            'sort_order' => $request->get('sort_order', 0),
        ]);

        return $this->createdResponse($category->load(['children', 'parent']), 'Category created successfully');
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $category = Category::with(['children', 'parent', 'products'])->findOrFail($id);

        return $this->successResponse($category);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $category = Category::findOrFail($id);

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'parent_id' => 'nullable|exists:categories,id',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ]);

        $category->update($request->only([
            'name', 'description', 'parent_id', 'is_active', 'sort_order'
        ]));

        return $this->successResponse($category->load(['children', 'parent']), 'Category updated successfully');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $category = Category::findOrFail($id);

        // Check if category has products
        if ($category->products()->count() > 0) {
            return $this->errorResponse('Cannot delete category with products', 400);
        }

        // Check if category has children
        if ($category->children()->count() > 0) {
            return $this->errorResponse('Cannot delete category with subcategories', 400);
        }

        $category->delete();

        return $this->successResponse(null, 'Category deleted successfully');
    }

    /**
     * Get category tree structure
     */
    public function tree()
    {
        $categories = Category::with('children')
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->get();

        return $this->successResponse($categories);
    }
}
