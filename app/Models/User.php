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
        'order_limit',
        'order_balance',
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
            'order_limit' => 'decimal:2',
            'order_balance' => 'decimal:2',
        ];
    }

    public function currentMonthOrderTotal(): float
    {
        return (float) $this->orders()
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->whereNotIn('status', [
                Order::STATUS_CANCELLED,
                Order::STATUS_REFUNDED,
            ])
            ->sum('total');
    }

    public function refreshOrderFinancials(?float $usage = null): void
    {
        $usage ??= $this->currentMonthOrderTotal();
        $balance = round((float) $this->order_limit - $usage, 2);

        $this->forceFill([
            'order_balance' => $balance,
        ])->save();

        $this->merchant()
            ->whereNotNull('id')
            ->update(['balance' => $balance]);
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

    public function merchantCustomers(): HasMany
    {
        return $this->hasMany(MerchantCustomer::class, 'merchant_user_id');
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
