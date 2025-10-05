<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    protected $fillable = [
        'name',
        'description',
        'sku',
        'price',
        'sale_price',
        'stock_quantity',
        'min_stock_alert',
        'category_id',
        'is_active',
        'is_featured',
        'images',
        'variations',
        'weight',
        'dimensions',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'images' => 'array',
        'variations' => 'array',
    ];

    // Category relationship
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    // Check if product is on sale
    public function isOnSale(): bool
    {
        return !is_null($this->sale_price) && $this->sale_price > 0;
    }

    // Get current price (sale price if available, otherwise regular price)
    public function getCurrentPrice(): float
    {
        return $this->isOnSale() ? $this->sale_price : $this->price;
    }

    // Check if product is in stock
    public function isInStock(): bool
    {
        return $this->stock_quantity > 0;
    }

    // Check if stock is low
    public function isLowStock(): bool
    {
        return $this->stock_quantity <= $this->min_stock_alert;
    }

    // Scope for active products
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // Scope for featured products
    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    // Scope for in-stock products
    public function scopeInStock($query)
    {
        return $query->where('stock_quantity', '>', 0);
    }

    // Scope for low stock products
    public function scopeLowStock($query)
    {
        return $query->whereRaw('stock_quantity <= min_stock_alert');
    }
}
