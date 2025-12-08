<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShippingType extends Model
{
    protected $fillable = [
        'name',
        'price',
        'is_default',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'is_default' => 'boolean',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
