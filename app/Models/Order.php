<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'order_number',
        'user_id',
        'merchant_id',
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
        'shipping_address',
        'billing_address',
        'tracking_number',
        'shipping_company',
        'carrier_id',
        'carrier_service_type',
        'shipping_type',
        'shipping_method',
        'cod_payment',
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
        'cod_payment' => 'boolean',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'source_metadata' => 'array',
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
