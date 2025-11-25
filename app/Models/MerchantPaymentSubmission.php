<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantPaymentSubmission extends Model
{
    protected $fillable = [
        'merchant_id',
        'amount',
        'currency',
        'payment_month',
        'status',
        'reference',
        'note',
        'submitted_at',
        'approved_at',
        'approved_by',
        'admin_note',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merchant_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
