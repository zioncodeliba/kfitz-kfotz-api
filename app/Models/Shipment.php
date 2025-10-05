<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Shipment extends Model
{
    protected $fillable = [
        'order_id',
        'tracking_number',
        'status',
        'carrier',
        'carrier_id',
        'carrier_service_type',
        'service_type',
        'package_type',
        'weight',
        'length',
        'width',
        'height',
        'origin_address',
        'destination_address',
        'shipping_cost',
        'cod_payment',
        'cod_amount',
        'notes',
        'tracking_events',
        'picked_up_at',
        'in_transit_at',
        'out_for_delivery_at',
        'delivered_at',
        'failed_at',
        'returned_at',
    ];

    protected $casts = [
        'origin_address' => 'array',
        'destination_address' => 'array',
        'tracking_events' => 'array',
        'weight' => 'decimal:2',
        'length' => 'decimal:2',
        'width' => 'decimal:2',
        'height' => 'decimal:2',
        'shipping_cost' => 'decimal:2',
        'cod_amount' => 'decimal:2',
        'cod_payment' => 'boolean',
        'picked_up_at' => 'datetime',
        'in_transit_at' => 'datetime',
        'out_for_delivery_at' => 'datetime',
        'delivered_at' => 'datetime',
        'failed_at' => 'datetime',
        'returned_at' => 'datetime',
    ];

    // Relationships
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
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

    public function isPickedUp(): bool
    {
        return $this->status === 'picked_up';
    }

    public function isInTransit(): bool
    {
        return $this->status === 'in_transit';
    }

    public function isOutForDelivery(): bool
    {
        return $this->status === 'out_for_delivery';
    }

    public function isDelivered(): bool
    {
        return $this->status === 'delivered';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isReturned(): bool
    {
        return $this->status === 'returned';
    }

    // Scopes
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByCarrier($query, $carrier)
    {
        return $query->where('carrier', $carrier);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeInTransit($query)
    {
        return $query->where('status', 'in_transit');
    }

    public function scopeDelivered($query)
    {
        return $query->where('status', 'delivered');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    // Generate tracking number
    public static function boot()
    {
        parent::boot();
        
        static::creating(function ($shipment) {
            if (empty($shipment->tracking_number)) {
                $shipment->tracking_number = 'TRK-' . date('Ymd') . '-' . str_pad(static::whereDate('created_at', today())->count() + 1, 4, '0', STR_PAD_LEFT);
            }
        });
    }

    // Update status with timestamp
    public function updateStatus($status)
    {
        $this->update(['status' => $status]);
        
        switch ($status) {
            case 'picked_up':
                $this->update(['picked_up_at' => now()]);
                break;
            case 'in_transit':
                $this->update(['in_transit_at' => now()]);
                break;
            case 'out_for_delivery':
                $this->update(['out_for_delivery_at' => now()]);
                break;
            case 'delivered':
                $this->update(['delivered_at' => now()]);
                break;
            case 'failed':
                $this->update(['failed_at' => now()]);
                break;
            case 'returned':
                $this->update(['returned_at' => now()]);
                break;
        }
    }

    // Add tracking event
    public function addTrackingEvent($event, $description = null, $location = null)
    {
        $events = $this->tracking_events ?? [];
        $events[] = [
            'timestamp' => now()->toISOString(),
            'event' => $event,
            'description' => $description,
            'location' => $location,
        ];
        
        $this->update(['tracking_events' => $events]);
    }
}
