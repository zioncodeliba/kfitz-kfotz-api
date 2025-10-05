<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Category extends Model
{
    protected $fillable = [
        'name',
        'description',
        'parent_id',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // Self-referencing relationship for parent/child categories
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    // Products relationship
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    // Get all descendants (children, grandchildren, etc.)
    public function descendants()
    {
        return $this->children()->with('descendants');
    }

    // Get all ancestors (parent, grandparent, etc.)
    public function ancestors()
    {
        return $this->parent()->with('ancestors');
    }

    // Scope for active categories
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

}
