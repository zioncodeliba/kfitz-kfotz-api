<?php

namespace App\Services;

use App\Models\MerchantPayment;

class MerchantPaymentEmailPayloadService
{
    public function build(MerchantPayment $payment, array $context = []): array
    {
        $payment->loadMissing(['merchant.merchant', 'creator']);

        $merchantUser = $payment->merchant;
        $merchantProfile = $merchantUser?->merchant;
        $creator = $payment->creator;

        return [
            'payment' => [
                'id' => $payment->id,
                'amount' => (float) ($payment->amount ?? 0),
                'currency' => $payment->currency ?? 'ILS',
                'payment_month' => $payment->payment_month,
                'paid_at' => optional($payment->paid_at)->toIso8601String(),
                'created_at' => optional($payment->created_at)->toIso8601String(),
                'method' => $payment->payment_method,
                'reference' => $payment->reference,
                'note' => $payment->note,
                'applied_amount' => (float) ($payment->applied_amount ?? 0),
                'remaining_credit' => (float) ($payment->remaining_credit ?? 0),
            ],
            'merchant' => [
                'id' => $merchantUser?->id,
                'name' => $merchantUser?->name ?? $merchantProfile?->contact_name,
                'email' => $merchantProfile?->email_for_orders ?? $merchantUser?->email,
                'phone' => $merchantProfile?->phone ?? $merchantUser?->phone,
                'business_name' => $merchantProfile?->business_name,
                'business_id' => $merchantProfile?->business_id,
            ],
            'initiator' => [
                'id' => $creator?->id,
                'name' => $creator?->name,
                'role' => $creator?->role,
            ],
            'context' => $context,
        ];
    }
}
