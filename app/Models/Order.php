<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Models\MerchantPayment;
use App\Models\MerchantPaymentOrder;

class Order extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED = 'refunded';

    protected $fillable = [
        'order_number',
        'user_id',
        'merchant_id',
        'merchant_customer_id',
        'merchant_site_id',
        'agent_id',
        'source',
        'source_reference',
        'source_metadata',
        'status',
        'payment_status',
        'subtotal',
        'tax',
        'shipping_cost',
        'discount',
        'total',
        'notes',
        'invoice_provider',
        'invoice_url',
        'invoice_payload',
        'shipping_address',
        'billing_address',
        'tracking_number',
        'shipping_company',
        'carrier_id',
        'carrier_service_type',
        'shipping_type',
        'shipping_method',
        'shipped_at',
        'delivered_at',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'shipping_cost' => 'decimal:2',
        'discount' => 'decimal:2',
        'total' => 'decimal:2',
        'shipping_address' => 'array',
        'billing_address' => 'array',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'source_metadata' => 'array',
        'invoice_payload' => 'array',
    ];

    protected $hidden = [
        'invoice_payload',
    ];

    protected $appends = [
        'payment_methods',
        'payment_method',
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merchant_id');
    }

    public function merchantCustomer(): BelongsTo
    {
        return $this->belongsTo(MerchantCustomer::class);
    }

    public function merchantSite(): BelongsTo
    {
        return $this->belongsTo(MerchantSite::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function shipment(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(ShippingCarrier::class);
    }

    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(MerchantPaymentOrder::class);
    }

    public function payments(): BelongsToMany
    {
        return $this->belongsToMany(MerchantPayment::class, 'merchant_payment_orders', 'order_id', 'payment_id')
            ->withPivot('amount_applied')
            ->withTimestamps();
    }

    public function getPaidAmountAttribute(): float
    {
        return (float) $this->paymentAllocations()->sum('amount_applied');
    }

    public function getOutstandingAmountAttribute(): float
    {
        $outstanding = (float) $this->total - $this->paid_amount;
        return $outstanding > 0 ? $outstanding : 0.0;
    }

    public function getPaymentMethodsAttribute(): array
    {
        if (!$this->relationLoaded('payments')) {
            return [];
        }

        return $this->payments
            ->pluck('payment_method')
            ->filter(fn ($method) => $method !== null && trim((string) $method) !== '')
            ->map(fn ($method) => trim((string) $method))
            ->unique()
            ->values()
            ->all();
    }

    public function getPaymentMethodAttribute(): ?string
    {
        $methods = $this->payment_methods;
        return $methods[0] ?? null;
    }

    // Status methods
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }

    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    public function isShipped(): bool
    {
        return $this->status === 'shipped';
    }

    public function isDelivered(): bool
    {
        return $this->status === 'delivered';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isRefunded(): bool
    {
        return $this->status === 'refunded';
    }

    // Payment status methods
    public function isPaymentPending(): bool
    {
        return $this->payment_status === 'pending';
    }

    public function isPaid(): bool
    {
        return $this->payment_status === 'paid';
    }

    public function isPaymentFailed(): bool
    {
        return $this->payment_status === 'failed';
    }

    public function isPaymentRefunded(): bool
    {
        return $this->payment_status === 'refunded';
    }

    // Scopes
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByPaymentStatus($query, $paymentStatus)
    {
        return $query->where('payment_status', $paymentStatus);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeConfirmed($query)
    {
        return $query->where('status', 'confirmed');
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', 'processing');
    }

    public function scopeShipped($query)
    {
        return $query->where('status', 'shipped');
    }

    public function scopeDelivered($query)
    {
        return $query->where('status', 'delivered');
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    // Generate order number
    public static function boot()
    {
        parent::boot();
        
        static::creating(function ($order) {
            if (empty($order->order_number)) {
                $order->order_number = 'ORD-' . date('Ymd') . '-' . str_pad(static::whereDate('created_at', today())->count() + 1, 4, '0', STR_PAD_LEFT);
            }
        });
    }

    // Carrier methods
    public function assignCarrier(ShippingCarrier $carrier, string $serviceType = 'regular'): void
    {
        $this->update([
            'carrier_id' => $carrier->id,
            'carrier_service_type' => $serviceType,
            'shipping_company' => $carrier->name,
        ]);
    }

    public function calculateShippingCost(): float
    {
        if (!$this->carrier) {
            return 0;
        }

        // Calculate weight from order items
        $weight = $this->items->sum('weight') ?? 0;
        
        return $this->carrier->calculateShippingCost($weight, $this->carrier_service_type ?? 'regular');
    }

    public function getCarrierInfo(): ?array
    {
        if (!$this->carrier) {
            return null;
        }

        return [
            'id' => $this->carrier->id,
            'name' => $this->carrier->name,
            'code' => $this->carrier->code,
            'service_type' => $this->carrier_service_type,
            'cost' => $this->calculateShippingCost(),
        ];
    }
}
