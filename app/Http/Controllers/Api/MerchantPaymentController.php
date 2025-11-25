<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\MerchantPayment;
use App\Models\MerchantPaymentOrder;
use App\Models\MerchantPaymentSubmission;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

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
            $paidAt = isset($validated['paid_at']) ? Carbon::parse($validated['paid_at']) : now();
            $paymentMonth = $paidAt->format('Y-m');
            $payment = MerchantPayment::create([
                'merchant_id' => $merchantUser->id,
                'amount' => $validated['amount'],
                'currency' => $currency,
                'payment_month' => $paymentMonth,
                'paid_at' => $paidAt,
                'note' => $validated['note'] ?? null,
                'reference' => $validated['reference'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            $orders = Order::where('merchant_id', $merchantUser->id)
                ->where('payment_status', '!=', 'paid')
                ->whereBetween('created_at', [$paidAt->clone()->startOfMonth(), $paidAt->clone()->endOfMonth()])
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
                    'payment_month' => $paymentMonth,
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

    public function approveFromSubmissions(Request $request, int $merchant): JsonResponse
    {
        $validated = $request->validate([
            'order_ids' => ['required', 'array', 'min:1'],
            'order_ids.*' => ['integer'],
            'submission_ids' => ['nullable', 'array'],
            'submission_ids.*' => ['integer'],
        ]);

        $admin = $request->user();
        if (!$admin || $admin->role !== 'admin') {
            return response()->json(['message' => 'אין הרשאה.'], 403);
        }

        $merchantUser = User::where('id', $merchant)
            ->where('role', 'merchant')
            ->first();

        if (!$merchantUser) {
            throw ValidationException::withMessages([
                'merchant' => 'סוחר לא נמצא או לא תקין.',
            ]);
        }

        $result = DB::transaction(function () use ($validated, $merchantUser, $admin) {
            $submissionQuery = MerchantPaymentSubmission::where('merchant_id', $merchantUser->id)
                ->where('status', 'pending');

            if (!empty($validated['submission_ids'])) {
                $submissionQuery->whereIn('id', $validated['submission_ids']);
            }

            $submissions = $submissionQuery->lockForUpdate()->get();
            if ($submissions->isEmpty()) {
                throw ValidationException::withMessages([
                    'submission_ids' => 'לא נמצאו תשלומים ממתינים לאישור.',
                ]);
            }

            $totalReceived = $submissions->sum('amount');
            $paymentMonth = $submissions->first()->payment_month ?? Carbon::now()->format('Y-m');
            $paidAt = $submissions->first()->submitted_at ?? now();

            $orders = Order::whereIn('id', $validated['order_ids'])
                ->where('merchant_id', $merchantUser->id)
                ->orderBy('created_at')
                ->lockForUpdate()
                ->get();

            if ($orders->isEmpty()) {
                throw ValidationException::withMessages([
                    'order_ids' => 'לא נמצאו הזמנות תואמות לסוחר.',
                ]);
            }

            $payment = MerchantPayment::create([
                'merchant_id' => $merchantUser->id,
                'amount' => $totalReceived,
                'currency' => $submissions->first()->currency ?? 'ILS',
                'payment_month' => $paymentMonth,
                'paid_at' => $paidAt,
                'created_by' => $admin->id,
                'reference' => 'אישור תשלום ממתין',
            ]);

            $existingAllocations = MerchantPaymentOrder::whereIn('order_id', $orders->pluck('id'))
                ->selectRaw('order_id, SUM(amount_applied) as paid')
                ->groupBy('order_id')
                ->pluck('paid', 'order_id');

            $remaining = (float) $totalReceived;
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
                    'payment_month' => $paymentMonth,
                ]);

                $remaining -= $apply;
                $newPaidTotal = $alreadyPaid + $apply;

                if ($newPaidTotal >= (float) $order->total) {
                    $order->payment_status = 'paid';
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

            MerchantPaymentSubmission::whereIn('id', $submissions->pluck('id'))
                ->update([
                    'status' => 'approved',
                    'approved_at' => now(),
                    'approved_by' => $admin->id,
                ]);

            $merchantProfile = Merchant::where('user_id', $merchantUser->id)->first();
            if ($merchantProfile) {
                $merchantProfile->last_payment_at = $payment->paid_at;
                $merchantProfile->save();
            }

            return [
                'payment' => $payment,
                'allocations' => $allocations,
                'approved_submissions' => $submissions->pluck('id'),
                'remaining_credit' => round(max(0, $remaining), 2),
            ];
        });

        return response()->json([
            'message' => 'התשלום אושר והוקצה להזמנות.',
            'data' => $result,
        ], 201);
    }
}
