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
    public function history(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user || $user->role !== 'merchant') {
            return response()->json(['message' => 'אין הרשאה.'], 403);
        }

        $payments = MerchantPayment::where('merchant_id', $user->id)
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->get([
                'id',
                'amount',
                'currency',
                'payment_month',
                'paid_at',
                'payment_method',
                'receipt_url',
                'created_at',
            ]);

        return response()->json([
            'message' => 'היסטוריית התשלומים נטענה.',
            'data' => $payments,
        ]);
    }

    public function monthlySummary(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user || $user->role !== 'merchant') {
            return response()->json(['message' => 'אין הרשאה.'], 403);
        }

        $summaries = MerchantPayment::where('merchant_id', $user->id)
            ->selectRaw('payment_month, SUM(amount) as total_amount')
            ->groupBy('payment_month')
            ->orderByDesc('payment_month')
            ->get()
            ->map(function ($row) {
                return [
                    'payment_month' => $row->payment_month ?? null,
                    'total_amount' => (float) ($row->total_amount ?? 0),
                ];
            });

        return response()->json([
            'message' => 'סיכומי תשלומים חודשיים נטענו.',
            'data' => $summaries,
        ]);
    }

    public function store(Request $request, int $merchant): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'paid_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
            'reference' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        [$merchantUser, $merchantProfile] = $this->resolveMerchantContext($merchant);

        if (!$merchantUser) {
            throw ValidationException::withMessages([
                'merchant' => 'סוחר לא נמצא או לא תקין.',
            ]);
        }

        $result = DB::transaction(function () use ($validated, $merchantUser, $merchantProfile, $request) {
            $currency = strtoupper($validated['currency'] ?? 'ILS');
            $paidAt = isset($validated['paid_at']) ? Carbon::parse($validated['paid_at']) : now();
            $paymentMonth = $paidAt->format('Y-m');
            $payment = MerchantPayment::create([
                'merchant_id' => $merchantUser->id,
                'amount' => $validated['amount'],
                'applied_amount' => 0,
                'remaining_credit' => 0,
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

            $payment->applied_amount = round($applied, 2);
            $payment->remaining_credit = round($remaining, 2);
            $payment->save();

            $profile = $merchantProfile ?? Merchant::where('user_id', $merchantUser->id)->first();
            if ($profile) {
                $profile->last_payment_at = $payment->paid_at;
                $profile->save();
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
            'order_ids' => ['nullable', 'array'],
            'order_ids.*' => ['integer'],
            'submission_ids' => ['nullable', 'array'],
            'submission_ids.*' => ['integer'],
            'credit_only' => ['nullable', 'boolean'],
        ]);

        $admin = $request->user();
        if (!$admin || $admin->role !== 'admin') {
            return response()->json(['message' => 'אין הרשאה.'], 403);
        }

        [$merchantUser, $merchantProfile] = $this->resolveMerchantContext($merchant);

        if (!$merchantUser) {
            throw ValidationException::withMessages([
                'merchant' => 'סוחר לא נמצא או לא תקין.',
            ]);
        }

        $result = DB::transaction(function () use ($validated, $merchantUser, $merchantProfile, $admin) {
            $submissionQuery = MerchantPaymentSubmission::where('merchant_id', $merchantUser->id)
                ->where('status', 'pending');

            if (!empty($validated['submission_ids'])) {
                $submissionQuery->whereIn('id', $validated['submission_ids']);
            }

            $submissions = $submissionQuery->lockForUpdate()->get();
            $orderIdsInput = $validated['order_ids'] ?? [];
            $creditOnly = (bool) ($validated['credit_only'] ?? false);

            $orders = !empty($orderIdsInput)
                ? Order::whereIn('id', $orderIdsInput)
                    ->where('merchant_id', $merchantUser->id)
                    ->orderBy('created_at')
                    ->lockForUpdate()
                    ->get()
                : collect();

            // Allow using existing credit without submissions
            if ($submissions->isEmpty() && $creditOnly) {
                if ($orders->isEmpty()) {
                    throw ValidationException::withMessages([
                        'order_ids' => 'לא נמצאו הזמנות תואמות לסוחר.',
                    ]);
                }

                $creditBalances = MerchantPayment::where('merchant_id', $merchantUser->id)
                    ->where('remaining_credit', '>', 0)
                    ->orderBy('paid_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($creditBalances->isEmpty()) {
                    throw ValidationException::withMessages([
                        'credit_only' => 'אין יתרת אשראי זמינה להקצאה.',
                    ]);
                }

                $allocations = [];
                $paymentMonth = Carbon::now()->format('Y-m');

                foreach ($orders as $order) {
                    $alreadyPaid = (float) ($order->paymentAllocations()->sum('amount_applied') ?? 0);
                    $outstanding = max(0, (float) $order->total - $alreadyPaid);
                    if ($outstanding <= 0) {
                        continue;
                    }

                    foreach ($creditBalances as $creditPayment) {
                        if ($outstanding <= 0) {
                            break;
                        }
                        $available = (float) $creditPayment->remaining_credit;
                        if ($available <= 0) {
                            continue;
                        }

                        $use = min($available, $outstanding);

                        MerchantPaymentOrder::create([
                            'payment_id' => $creditPayment->id,
                            'order_id' => $order->id,
                            'amount_applied' => $use,
                            'payment_month' => $paymentMonth,
                        ]);

                        $creditPayment->remaining_credit = round($creditPayment->remaining_credit - $use, 2);
                        $creditPayment->applied_amount = round(($creditPayment->applied_amount ?? 0) + $use, 2);
                        $creditPayment->save();

                        $outstanding -= $use;
                        $allocations[] = [
                            'order_id' => $order->id,
                            'order_number' => $order->order_number,
                            'applied_amount' => round($use, 2),
                            'payment_id' => $creditPayment->id,
                        ];
                    }

                    if ($outstanding <= 0) {
                        $order->payment_status = 'paid';
                        $order->save();
                    }
                }

                $profile = $merchantProfile ?? Merchant::where('user_id', $merchantUser->id)->first();
                if ($profile) {
                    $profile->last_payment_at = Carbon::now();
                    $profile->save();
                }

                return [
                    'payment' => null,
                    'allocations' => $allocations,
                    'approved_submissions' => [],
                    'remaining_credit' => (float) MerchantPayment::where('merchant_id', $merchantUser->id)->sum('remaining_credit'),
                ];
            }

            if ($submissions->isEmpty()) {
                throw ValidationException::withMessages([
                    'submission_ids' => 'לא נמצאו תשלומים ממתינים לאישור.',
                ]);
            }

            $existingCredits = MerchantPayment::where('merchant_id', $merchantUser->id)
                ->where('remaining_credit', '>', 0)
                ->orderBy('paid_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'amount', 'applied_amount', 'remaining_credit', 'paid_at']);

            $totalReceived = $submissions->sum('amount');
            $paymentMonth = $submissions->first()->payment_month ?? Carbon::now()->format('Y-m');
            $paidAt = $submissions->first()->submitted_at ?? now();

            if ($orders->isEmpty() && !$creditOnly) {
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
                'applied_amount' => 0,
                'remaining_credit' => 0,
                'payment_method' => $submissions->first()->payment_method ?? null,
            ]);

            if ($creditOnly) {
                MerchantPaymentSubmission::whereIn('id', $submissions->pluck('id'))
                    ->update([
                        'status' => 'approved',
                        'approved_at' => now(),
                        'approved_by' => $admin->id,
                    ]);

                $payment->remaining_credit = round($totalReceived, 2);
                $payment->save();

                $profile = $merchantProfile ?? Merchant::where('user_id', $merchantUser->id)->first();
                if ($profile) {
                    $profile->last_payment_at = $payment->paid_at;
                    $profile->save();
                }

                return [
                    'payment' => $payment,
                    'allocations' => [],
                    'approved_submissions' => $submissions->pluck('id'),
                    'remaining_credit' => round($totalReceived, 2),
                ];
            }

            $existingAllocations = MerchantPaymentOrder::whereIn('order_id', $orders->pluck('id'))
                ->selectRaw('order_id, SUM(amount_applied) as paid')
                ->groupBy('order_id')
                ->pluck('paid', 'order_id');

            $remainingNew = (float) $totalReceived;
            $creditBalances = $existingCredits->mapWithKeys(fn ($p) => [$p->id => (float) $p->remaining_credit]);
            $creditUsage = $existingCredits->mapWithKeys(fn ($p) => [$p->id => 0.0]);
            $applied = 0.0;
            $allocations = [];

            foreach ($orders as $order) {
                if ($remainingNew <= 0 && $creditBalances->sum() <= 0) {
                    break;
                }

                $alreadyPaid = (float) ($existingAllocations[$order->id] ?? 0);
                $outstanding = max(0, (float) $order->total - $alreadyPaid);
                if ($outstanding <= 0) {
                    continue;
                }

                $appliedPortion = 0.0;

                $applyFromNew = min($remainingNew, $outstanding);
                if ($applyFromNew > 0) {
                    MerchantPaymentOrder::create([
                        'payment_id' => $payment->id,
                        'order_id' => $order->id,
                        'amount_applied' => $applyFromNew,
                        'payment_month' => $paymentMonth,
                    ]);
                    $remainingNew -= $applyFromNew;
                    $outstanding -= $applyFromNew;
                    $appliedPortion += $applyFromNew;
                    $applied += $applyFromNew;
                }

                if ($outstanding > 0 && $creditBalances->isNotEmpty()) {
                    foreach ($creditBalances as $paymentId => $creditRemaining) {
                        if ($outstanding <= 0) {
                            break;
                        }
                        $useCredit = min($creditRemaining, $outstanding);
                        if ($useCredit <= 0) {
                            continue;
                        }
                        MerchantPaymentOrder::create([
                            'payment_id' => $paymentId,
                            'order_id' => $order->id,
                            'amount_applied' => $useCredit,
                            'payment_month' => $paymentMonth,
                        ]);
                        $creditBalances[$paymentId] = max(0, $creditRemaining - $useCredit);
                        $creditUsage[$paymentId] += $useCredit;
                        $outstanding -= $useCredit;
                        $appliedPortion += $useCredit;
                    }
                }

                $newPaidTotal = $alreadyPaid + $appliedPortion;

                if ($newPaidTotal >= (float) $order->total) {
                    $order->payment_status = 'paid';
                    $order->save();
                }

                $allocations[] = [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'applied_amount' => round($appliedPortion, 2),
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

            $leftoverCredit = $creditBalances->sum();

            $payment->applied_amount = round($applied, 2);
            $payment->remaining_credit = round(max(0, $remainingNew + $leftoverCredit), 2);
            $payment->save();

            foreach ($existingCredits as $creditPayment) {
                $usedFromCredit = $creditUsage[$creditPayment->id] ?? 0.0;
                $creditPayment->applied_amount = round(
                    (float) ($creditPayment->applied_amount ?? 0) + $usedFromCredit,
                    2
                );
                // מאחדים יתרות קיימות לתשלום החדש
                $creditPayment->remaining_credit = 0;
                $creditPayment->save();
            }

            $profile = $merchantProfile ?? Merchant::where('user_id', $merchantUser->id)->first();
            if ($profile) {
                $profile->last_payment_at = $payment->paid_at;
                $profile->save();
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

    /**
     * Resolve merchant context by accepting either merchant model id or merchant user id.
     */
    private function resolveMerchantContext(int $merchant): array
    {
        $merchantProfile = Merchant::with('user')
            ->where(function ($query) use ($merchant) {
                $query->where('id', $merchant)
                    ->orWhere('user_id', $merchant);
            })
            ->first();

        if ($merchantProfile && $merchantProfile->user && $merchantProfile->user->role === 'merchant') {
            return [$merchantProfile->user, $merchantProfile];
        }

        $merchantUser = User::where('id', $merchant)
            ->where('role', 'merchant')
            ->first();

        if ($merchantUser) {
            return [$merchantUser, Merchant::where('user_id', $merchantUser->id)->first()];
        }

        return [null, null];
    }
}
