<?php

namespace App\Services;

use App\Models\MerchantPaymentSubmission;
use App\Models\User;

class MerchantPaymentSubmissionEmailPayloadService
{
    public function build(
        MerchantPaymentSubmission $submission,
        array $context = [],
        ?User $initiator = null
    ): array {
        $submission->loadMissing(['merchant.merchant', 'approver']);

        $merchantUser = $submission->merchant;
        $merchantProfile = $merchantUser?->merchant;
        $approver = $submission->approver;

        return [
            'submission' => [
                'id' => $submission->id,
                'amount' => (float) ($submission->amount ?? 0),
                'currency' => $submission->currency ?? 'ILS',
                'payment_month' => $submission->payment_month,
                'status' => $submission->status,
                'reference' => $submission->reference,
                'note' => $submission->note,
                'submitted_at' => optional($submission->submitted_at)->toIso8601String(),
                'approved_at' => optional($submission->approved_at)->toIso8601String(),
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
                'id' => $initiator?->id,
                'name' => $initiator?->name,
                'role' => $initiator?->role,
            ],
            'approver' => [
                'id' => $approver?->id,
                'name' => $approver?->name,
                'role' => $approver?->role,
            ],
            'context' => $context,
        ];
    }
}
