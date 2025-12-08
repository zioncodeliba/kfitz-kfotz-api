<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'name',
        'description',
        'sku',
        'price',
        'sale_price',
        'cost_price',
        'shipping_price',
        'shipping_type_id',
        'stock_quantity',
        'min_stock_alert',
        'category_id',
        'is_active',
        'is_featured',
        'images',
        'variations',
        'weight',
        'dimensions',
        'merchant_prices',
        'plugin_site_prices',
        'restocked_initial_stock',
        'restocked_at',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'cost_price' => 'decimal:2',
        'shipping_price' => 'decimal:2',
        'shipping_type_id' => 'integer',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'images' => 'array',
        'variations' => 'array',
        'merchant_prices' => 'array',
        'plugin_site_prices' => 'array',
        'restocked_at' => 'datetime',
    ];

    // Category relationship
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function shippingType(): BelongsTo
    {
        return $this->belongsTo(ShippingType::class);
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

    public function productVariations(): HasMany
    {
        return $this->hasMany(ProductVariation::class);
    }
}
