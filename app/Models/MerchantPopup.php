<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantPopup extends Model
{
    protected $fillable = [
        'merchant_id',
        'is_active',
        'display_once',
        'title',
        'content',
        'image_url',
        'uploaded_image',
        'button_text',
        'start_date',
        'end_date',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'display_once' => 'boolean',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
