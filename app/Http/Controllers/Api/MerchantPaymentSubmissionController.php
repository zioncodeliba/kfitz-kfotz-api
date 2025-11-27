<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\MerchantPaymentSubmission;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Carbon\Carbon;

class MerchantPaymentSubmissionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user || $user->role !== 'merchant') {
            return response()->json(['message' => 'אין הרשאה לבצע פעולה זו.'], 403);
        }

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['nullable', 'string', 'size:3'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $submission = MerchantPaymentSubmission::create([
            'merchant_id' => $user->id,
            'amount' => $validated['amount'],
            'currency' => strtoupper($validated['currency'] ?? 'ILS'),
            'payment_month' => Carbon::now()->format('Y-m'),
            'status' => 'pending',
            'reference' => $validated['reference'] ?? null,
            'note' => $validated['note'] ?? null,
            'submitted_at' => now(),
        ]);

        return response()->json([
            'message' => 'הבקשה נרשמה וממתינה לאישור אדמין.',
            'data' => $submission,
        ], 201);
    }

    public function pendingForMerchant(int $merchantId): JsonResponse
    {
        $admin = request()->user();
        if (!$admin || $admin->role !== 'admin') {
            return response()->json(['message' => 'אין הרשאה.'], 403);
        }

        [$merchantUser] = $this->resolveMerchantContext($merchantId);
        if (!$merchantUser) {
            return response()->json(['message' => 'סוחר לא נמצא.'], 404);
        }

        $submissions = MerchantPaymentSubmission::where('merchant_id', $merchantUser->id)
            ->where('status', 'pending')
            ->orderByDesc('submitted_at')
            ->get([
                'id',
                'amount',
                'currency',
                'payment_month',
                'reference',
                'note',
                'status',
                'submitted_at'
            ]);

        $total = $submissions->sum('amount');

        return response()->json([
            'message' => 'בקשות תשלום ממתינות נטענו.',
            'data' => [
                'count' => $submissions->count(),
                'total_amount' => (float) $total,
                'submissions' => $submissions,
            ],
        ]);
    }

    /**
     * Resolve merchant by accepting merchant model id or merchant user id.
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
