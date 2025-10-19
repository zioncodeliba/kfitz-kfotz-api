<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\VerifyEmailNotification;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'role',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function hasRole(string $roleName): bool
    {
        return $this->role === $roleName;
    }

    public function merchant()
    {
        return $this->hasOne(Merchant::class);
    }

    public function isMerchant(): bool
    {
        return $this->hasRole('merchant');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function agentMerchants(): HasMany
    {
        return $this->hasMany(Merchant::class, 'agent_id');
    }

    /**
     * Send the email verification notification.
     *
     * @return void
     */
    public function sendEmailVerificationNotification()
    {
        $this->notify(new VerifyEmailNotification);
    }

}
