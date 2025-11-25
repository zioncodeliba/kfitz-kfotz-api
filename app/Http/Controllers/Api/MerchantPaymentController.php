<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\MerchantPayment;
use App\Models\MerchantPaymentOrder;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MerchantPaymentController extends Controller
{
    public function store(Request $request, int $merchant): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'paid_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
            'reference' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        $merchantUser = User::where('id', $merchant)
            ->where('role', 'merchant')
            ->first();

        if (!$merchantUser) {
            throw ValidationException::withMessages([
                'merchant' => 'סוחר לא נמצא או לא תקין.',
            ]);
        }

        $result = DB::transaction(function () use ($validated, $merchantUser, $request) {
            $currency = strtoupper($validated['currency'] ?? 'ILS');
            $payment = MerchantPayment::create([
                'merchant_id' => $merchantUser->id,
                'amount' => $validated['amount'],
                'currency' => $currency,
                'paid_at' => $validated['paid_at'] ?? now(),
                'note' => $validated['note'] ?? null,
                'reference' => $validated['reference'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            $orders = Order::where('merchant_id', $merchantUser->id)
                ->where('payment_status', '!=', 'paid')
                ->orderBy('created_at')
                ->lockForUpdate()
                ->get();

            $orderIds = $orders->pluck('id');
            $existingAllocations = $orderIds->isNotEmpty()
                ? MerchantPaymentOrder::whereIn('order_id', $orderIds)
                    ->selectRaw('order_id, SUM(amount_applied) as paid')
                    ->groupBy('order_id')
                    ->pluck('paid', 'order_id')
                : collect();

            $remaining = (float) $validated['amount'];
            $applied = 0.0;
            $allocations = [];

            foreach ($orders as $order) {
                if ($remaining <= 0) {
                    break;
                }

                $alreadyPaid = (float) ($existingAllocations[$order->id] ?? 0);
                $outstanding = max(0, (float) $order->total - $alreadyPaid);
                if ($outstanding <= 0) {
                    continue;
                }

                $apply = min($remaining, $outstanding);
                if ($apply <= 0) {
                    continue;
                }

                MerchantPaymentOrder::create([
                    'payment_id' => $payment->id,
                    'order_id' => $order->id,
                    'amount_applied' => $apply,
                ]);

                $remaining -= $apply;
                $applied += $apply;
                $newPaidTotal = $alreadyPaid + $apply;

                if ($newPaidTotal >= (float) $order->total) {
                    $order->payment_status = 'paid';
                    $order->save();
                } elseif ($order->payment_status === 'paid') {
                    $order->payment_status = 'pending';
                    $order->save();
                }

                $allocations[] = [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'applied_amount' => round($apply, 2),
                    'order_total' => (float) $order->total,
                    'order_paid_total' => round($newPaidTotal, 2),
                    'order_outstanding' => max(0, round((float) $order->total - $newPaidTotal, 2)),
                    'order_status' => $order->payment_status,
                ];
            }

            $merchantProfile = Merchant::where('user_id', $merchantUser->id)->first();
            if ($merchantProfile) {
                $merchantProfile->last_payment_at = $payment->paid_at;
                $merchantProfile->save();
            }

            return [
                'payment' => $payment,
                'applied_amount' => round($applied, 2),
                'remaining_credit' => round($remaining, 2),
                'allocations' => $allocations,
            ];
        });

        return response()->json([
            'message' => 'התשלום נשמר והוקצה להזמנות.',
            'data' => $result,
        ], 201);
    }
}
