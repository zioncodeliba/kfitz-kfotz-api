<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantBanner extends Model
{
    protected $fillable = [
        'merchant_id',
        'text',
        'link',
        'image_url',
        'start_date',
        'end_date',
        'is_enabled',
        'display_order',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
