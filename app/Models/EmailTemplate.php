<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_key',
        'name',
        'subject',
        'body_html',
        'body_text',
        'is_active',
        'default_recipients',
        'email_list_id',
        'metadata',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'default_recipients' => 'array',
        'metadata' => 'array',
    ];

    public function logs(): HasMany
    {
        return $this->hasMany(EmailLog::class);
    }

    public function emailList(): BelongsTo
    {
        return $this->belongsTo(EmailList::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
