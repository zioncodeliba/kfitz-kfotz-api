<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'email_template_id',
        'event_key',
        'recipient_email',
        'recipient_name',
        'subject',
        'status',
        'sent_at',
        'error_message',
        'payload',
        'meta',
    ];

    protected $casts = [
        'payload' => 'array',
        'meta' => 'array',
        'sent_at' => 'datetime',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'email_template_id');
    }
}
