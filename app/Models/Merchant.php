<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Merchant extends Model
{
    protected $fillable = [
        'user_id',
        'agent_id',
        'business_name',
        'contact_name',
        'business_id',
        'phone',
        'email_for_orders',
        'website',
        'description',
        'address',
        'status',
        'verification_status',
        'commission_rate',
        'monthly_fee',
        'balance',
        'payment_methods',
        'shipping_settings',
        'banner_settings',
        'popup_settings',
        'verified_at',
        'last_payment_at',
    ];

    protected $casts = [
        'address' => 'array',
        'payment_methods' => 'array',
        'shipping_settings' => 'array',
        'banner_settings' => 'array',
        'popup_settings' => 'array',
        'commission_rate' => 'decimal:2',
        'monthly_fee' => 'decimal:2',
        'balance' => 'decimal:2',
        'verified_at' => 'datetime',
        'last_payment_at' => 'datetime',
    ];

    public function getCreditLimitAttribute($value)
    {
        if ($this->relationLoaded('user') && $this->user) {
            return $this->user->order_limit;
        }

        if ($value !== null) {
            return $value;
        }

        return $this->user?->order_limit;
    }

    public function getBalanceAttribute($value)
    {
        if ($this->relationLoaded('user') && $this->user) {
            return $this->user->order_balance;
        }

        if ($value !== null) {
            return $value;
        }

        return $this->user?->order_balance;
    }

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'merchant_id', 'id');
    }

    public function pluginSites(): HasMany
    {
        return $this->hasMany(MerchantSite::class, 'user_id', 'user_id');
    }

    public function customers(): HasMany
    {
        return $this->hasMany(MerchantCustomer::class, 'merchant_user_id', 'user_id');
    }

    // Status methods
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isVerified(): bool
    {
        return $this->verification_status === 'verified';
    }

    public function isRejected(): bool
    {
        return $this->verification_status === 'rejected';
    }

    // Financial methods
    public function getMonthlyRevenue(): float
    {
        return $this->orders()
            ->where('payment_status', 'paid')
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('total');
    }

    public function getMonthlyOrders(): int
    {
        return $this->orders()
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();
    }

    public function getPreviousMonthRevenue(): float
    {
        return $this->orders()
            ->where('payment_status', 'paid')
            ->whereMonth('created_at', now()->subMonth()->month)
            ->whereYear('created_at', now()->subMonth()->year)
            ->sum('total');
    }

    public function getPreviousMonthOrders(): int
    {
        return $this->orders()
            ->whereMonth('created_at', now()->subMonth()->month)
            ->whereYear('created_at', now()->subMonth()->year)
            ->count();
    }

    public function getOutstandingBalance(): float
    {
        return $this->orders()
            ->where('payment_status', 'pending')
            ->sum('total');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeVerified($query)
    {
        return $query->where('verification_status', 'verified');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeSuspended($query)
    {
        return $query->where('status', 'suspended');
    }
}
