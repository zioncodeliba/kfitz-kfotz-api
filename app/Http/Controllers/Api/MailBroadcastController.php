<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MerchantCustomer;
use App\Models\User;
use App\Services\MailBroadcastService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class MailBroadcastController extends Controller
{
    use ApiResponse;

    public function sendEmail(Request $request, MailBroadcastService $broadcastService)
    {
        $data = $request->validate([
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
            'merchant_ids' => 'nullable|array',
            'merchant_ids.*' => 'integer|exists:users,id',
            'customer_ids' => 'nullable|array',
            'customer_ids.*' => 'integer|exists:merchant_customers,id',
        ]);

        $merchantIds = collect($data['merchant_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        $customerIds = collect($data['customer_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($merchantIds->isEmpty() && $customerIds->isEmpty()) {
            return $this->validationErrorResponse([
                'recipients' => ['יש לבחור לפחות נמען אחד לשליחה'],
            ]);
        }

        $recipients = [];

        if ($merchantIds->isNotEmpty()) {
            $merchants = User::query()
                ->whereIn('id', $merchantIds)
                ->where('role', 'merchant')
                ->get(['id', 'name', 'email', 'phone']);

            foreach ($merchants as $merchant) {
                if (!$merchant->email) {
                    continue;
                }

                $recipients[] = [
                    'email' => $merchant->email,
                    'name' => $merchant->name,
                    'type' => 'merchant',
                    'context' => [
                        'merchant' => [
                            'id' => $merchant->id,
                            'name' => $merchant->name,
                            'email' => $merchant->email,
                            'phone' => $merchant->phone,
                        ],
                    ],
                ];
            }
        }

        if ($customerIds->isNotEmpty()) {
            $customers = MerchantCustomer::query()
                ->whereIn('id', $customerIds)
                ->with(['merchantUser:id,name,email', 'merchant:user_id,business_name'])
                ->get();

            foreach ($customers as $customer) {
                if (!$customer->email) {
                    continue;
                }

                $recipients[] = [
                    'email' => $customer->email,
                    'name' => $customer->name,
                    'type' => 'customer',
                    'context' => [
                        'customer' => [
                            'id' => $customer->id,
                            'name' => $customer->name,
                            'email' => $customer->email,
                            'phone' => $customer->phone,
                        ],
                        'merchant' => [
                            'id' => $customer->merchant_user_id,
                            'name' => optional($customer->merchantUser)->name,
                            'email' => optional($customer->merchantUser)->email,
                            'business_name' => optional($customer->merchant)->business_name,
                        ],
                    ],
                ];
            }
        }

        if (empty($recipients)) {
            return $this->validationErrorResponse([
                'recipients' => ['לא נמצאו נמענים עם כתובת אימייל תקינה'],
            ]);
        }

        $summary = $broadcastService->sendEmail(
            $recipients,
            $data['subject'],
            $data['body']
        );

        return $this->successResponse($summary, 'ההודעה נשלחה בהצלחה');
    }
}
