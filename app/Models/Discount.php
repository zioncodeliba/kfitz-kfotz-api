<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class Discount extends Model
{
    protected $fillable = [
        'created_by',
        'name',
        'type',
        'status',
        'start_date',
        'end_date',
        'buy_quantity',
        'get_quantity',
        'product_id',
        'discount_percentage',
        'apply_scope',
        'category_id',
        'target_merchant_id',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'discount_percentage' => 'decimal:2',
        'buy_quantity' => 'integer',
        'get_quantity' => 'integer',
    ];

    protected $appends = [
        'computed_status',
    ];

    public const TYPE_QUANTITY = 'quantity';
    public const TYPE_STOREWIDE = 'storewide';
    public const TYPE_MERCHANT = 'merchant';

    public const SCOPE_STORE = 'store';
    public const SCOPE_CATEGORY = 'category';
    public const SCOPE_PRODUCT = 'product';

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function targetMerchant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_merchant_id');
    }

    public function targetMerchantProfile(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'target_merchant_id', 'user_id');
    }

    public function scopeOwnedBy($query, int $userId)
    {
        return $query->where('created_by', $userId);
    }

    public function computeStatus(Carbon $reference = null): string
    {
        $reference ??= now();

        if ($this->start_date && $reference->lt($this->start_date->startOfDay())) {
            return self::STATUS_SCHEDULED;
        }

        if ($this->end_date && $reference->gt($this->end_date->endOfDay())) {
            return self::STATUS_EXPIRED;
        }

        return self::STATUS_ACTIVE;
    }

    public function refreshStatus(bool $persist = false): void
    {
        $status = $this->computeStatus();

        if ($status === $this->status) {
            return;
        }

        $this->status = $status;

        if ($persist) {
            $this->save();
        }
    }

    protected function computedStatus(): Attribute
    {
        return Attribute::get(fn () => $this->computeStatus());
    }
}
