<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class SystemAlert extends Model
{
    protected $fillable = [
        'title',
        'message',
        'severity',
        'category',
        'icon',
        'action_label',
        'action_url',
        'audience',
        'status',
        'is_sticky',
        'is_dismissible',
        'metadata',
        'published_at',
        'expires_at',
    ];

    protected $casts = [
        'is_sticky' => 'boolean',
        'is_dismissible' => 'boolean',
        'metadata' => 'array',
        'published_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    protected $attributes = [
        'severity' => 'info',
        'audience' => 'admin',
        'status' => 'active',
        'is_sticky' => false,
        'is_dismissible' => true,
    ];

    public function scopeForAudience(Builder $query, string $audience): Builder
    {
        return $query->where(function (Builder $q) use ($audience) {
            $q->where('audience', $audience)
              ->orWhere('audience', 'all');
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        $now = now();

        return $query
            ->where('status', 'active')
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('published_at')
                  ->orWhere('published_at', '<=', $now);
            })
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>=', $now);
            });
    }

    public function scopeMostRecent(Builder $query): Builder
    {
        return $query->orderByDesc('is_sticky')->orderByDesc('published_at')->orderByDesc('created_at');
    }
}
