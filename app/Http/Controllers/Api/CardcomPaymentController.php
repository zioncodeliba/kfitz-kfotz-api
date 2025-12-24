<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\MerchantPayment;
use App\Services\YpayInvoiceService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CardcomPaymentController extends Controller
{
    public function notify(Request $request, YpayInvoiceService $ypayInvoiceService)
    {
        $logger = Log::channel('cardcom_notify');
        $payload = $request->all();

        $logger->info('Cardcom notify received', ['payload' => $payload]);

        $responseCode = (int) ($payload['responsecode'] ?? $payload['ResponseCode'] ?? 0);
        if ($responseCode !== 0) {
            $logger->warning('Cardcom notify non-success response', ['payload' => $payload]);
            return response()->json(['message' => 'Payment not successful', 'code' => $responseCode], 400);
        }

        $returnDataRaw = $payload['ReturnData'] ?? $payload['returnData'] ?? null;
        $parsed = $this->parseReturnData($returnDataRaw);

        $merchantId = isset($parsed['merchantId']) ? (int) $parsed['merchantId'] : null;
        $monthKey = isset($parsed['month']) && is_string($parsed['month']) ? $parsed['month'] : null;
        $expectedAmount = isset($parsed['amount']) ? (float) $parsed['amount'] : null;

        if (!$merchantId && isset($payload['merchantId'])) {
            $merchantId = (int) $payload['merchantId'];
        }
        if (!$monthKey && isset($payload['month'])) {
            $monthKey = (string) $payload['month'];
        }

        if (!$merchantId || !$monthKey) {
            $logger->warning('Cardcom notify missing merchant/month', [
                'returnData' => $returnDataRaw,
                'parsed' => $parsed,
                'payload_keys' => array_keys($payload),
            ]);
            return response()->json(['message' => 'Missing merchantId or month in ReturnData'], 400);
        }

        [$year, $month] = explode('-', $monthKey) + [null, null];
        if (!$year || !$month) {
            return response()->json(['message' => 'Invalid month format'], 400);
        }

        $start = Carbon::createFromDate((int) $year, (int) $month, 1)->startOfMonth();
        $end = (clone $start)->endOfMonth();

        $query = Order::where('merchant_id', $merchantId)
            ->whereBetween('created_at', [$start, $end])
            ->where('payment_status', '!=', 'paid')
            ->whereNotIn('status', ['cancelled', 'canceled'])
            ->where('source', '!=', 'cashcow');

        $outstanding = (float) $query->sum('total');

        // Optional check: ensure paid amount matches (with small tolerance)
        if ($expectedAmount !== null && $expectedAmount > 0) {
            $diff = abs($expectedAmount - $outstanding);
            if ($diff > 1.0) {
                $logger->warning('Cardcom notify amount mismatch', [
                    'merchant_id' => $merchantId,
                    'month' => $monthKey,
                    'expected' => $expectedAmount,
                    'outstanding' => $outstanding,
                ]);
            }
        }

        $updatedCount = 0;
        $payment = null;
        $paymentMethod = $payload['MutagName'] ?? 'cardcom';

        DB::transaction(function () use ($query, $payload, $merchantId, $monthKey, $expectedAmount, $paymentMethod, &$updatedCount, &$payment) {
            $amount = $expectedAmount;
            if ($amount === null || $amount <= 0) {
                $amount = isset($payload['suminfull']) ? (float) $payload['suminfull'] : null;
            }
            if ($amount === null && isset($payload['suminagorot'])) {
                $amount = ((float) $payload['suminagorot']) / 100;
            }
            if ($amount === null && isset($payload['sum'])) {
                $amount = (float) $payload['sum'];
            }

            $reference = $payload['internaldealnumber'] ?? $payload['InternalDealNumber'] ?? null;
            $paymentMonth = $monthKey ?? now()->format('Y-m');

            if ($amount !== null && $amount > 0) {
                $payment = MerchantPayment::firstOrCreate(
                    [
                        'merchant_id' => $merchantId,
                        'reference' => $reference,
                        'payment_month' => $paymentMonth,
                        'payment_method' => $paymentMethod,
                    ],
                    [
                        'amount' => $amount,
                        'applied_amount' => 0,
                        'remaining_credit' => 0,
                        'currency' => 'ILS',
                        'paid_at' => now(),
                        'receipt_url' => null,
                        'note' => 'Cardcom notify',
                    ]
                );
            }

            $updatedCount = (int) $query->update(['payment_status' => 'paid']);
        });

        if (
            $payment instanceof MerchantPayment
            && (!is_string($payment->receipt_url) || trim($payment->receipt_url) === '')
        ) {
            try {
                $receipt = $ypayInvoiceService->createReceiptForMerchantPayment($payment);
                $payment->forceFill([
                    'receipt_url' => $receipt['invoice_url'],
                ])->save();

                $logger->info('Cardcom receipt generated', [
                    'merchant_id' => $merchantId,
                    'payment_id' => $payment->id,
                    'receipt_url' => $payment->receipt_url,
                ]);
            } catch (Throwable $e) {
                $logger->warning('Cardcom receipt generation failed', [
                    'merchant_id' => $merchantId,
                    'payment_id' => $payment->id ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'message' => 'Orders marked as paid',
            'merchant_id' => $merchantId,
            'month' => $monthKey,
            'updated' => $updatedCount,
            'outstanding_before' => $outstanding,
        ]);
    }

    private function parseReturnData($raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        // Try plain JSON first
        $json = json_decode($raw, true);
        if (is_array($json)) {
            return $json;
        }

        // Try base64 JSON
        $decoded = base64_decode($raw, true);
        if ($decoded !== false) {
            $json = json_decode($decoded, true);
            if (is_array($json)) {
                return $json;
            }
        }

        // Fallback: not parseable
        Log::channel('cardcom_notify')->warning('Cardcom ReturnData not parseable', ['ReturnData' => $raw]);
        return [];
    }
}
