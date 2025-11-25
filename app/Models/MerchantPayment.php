<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MerchantPayment extends Model
{
    protected $fillable = [
        'merchant_id',
        'amount',
        'currency',
        'paid_at',
        'reference',
        'note',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merchant_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(MerchantPaymentOrder::class, 'payment_id');
    }

    public function orders(): BelongsToMany
    {
        return $this->belongsToMany(Order::class, 'merchant_payment_orders', 'payment_id', 'order_id')
            ->withPivot('amount_applied')
            ->withTimestamps();
    }

    public function getAllocatedAmountAttribute(): float
    {
        return (float) $this->allocations()->sum('amount_applied');
    }
}
