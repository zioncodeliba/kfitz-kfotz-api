<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class MerchantSite extends Model
{
    protected $fillable = [
        'user_id',
        'site_url',
        'name',
        'contact_name',
        'contact_phone',
        'platform',
        'plugin_installed_at',
        'metadata',
        'status',
        'balance',
        'credit_limit',
    ];

    protected $casts = [
        'plugin_installed_at' => 'datetime',
        'metadata' => 'array',
        'balance' => 'decimal:2',
        'credit_limit' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function merchant(): HasOneThrough
    {
        return $this->hasOneThrough(
            Merchant::class,
            User::class,
            'id',        // Foreign key on users table...
            'user_id',   // Foreign key on merchants table...
            'user_id',   // Local key on merchant_sites table...
            'id'         // Local key on users table...
        );
    }
}
