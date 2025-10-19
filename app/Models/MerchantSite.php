<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantSite extends Model
{
    protected $fillable = [
        'merchant_id',
        'site_url',
        'platform',
        'plugin_installed_at',
        'metadata',
    ];

    protected $casts = [
        'plugin_installed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
