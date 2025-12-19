<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Services\YpayInvoiceService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Throwable;

class OrderInvoiceController extends Controller
{
    use ApiResponse;

    public function generate(Request $request, Order $order, YpayInvoiceService $ypayInvoiceService)
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->unauthorizedResponse('Unauthorized');
        }

        if (!$this->userCanAccessOrder($user, $order)) {
            return $this->forbiddenResponse('Forbidden');
        }

        if (is_string($order->invoice_url) && trim($order->invoice_url) !== '') {
            return $this->successResponse([
                'order_id' => $order->id,
                'invoice_url' => $order->invoice_url,
                'invoice_provider' => $order->invoice_provider,
            ], 'Invoice already exists');
        }

        if (strtolower((string) $order->source) === 'cashcow') {
            $metadata = is_array($order->source_metadata) ? $order->source_metadata : [];
            $candidate = null;

            if (isset($metadata['invoice_url']) && is_string($metadata['invoice_url'])) {
                $candidate = trim($metadata['invoice_url']);
            }

            if ((!$candidate || $candidate === '') && isset($metadata['copy_invoice_url']) && is_string($metadata['copy_invoice_url'])) {
                $candidate = trim($metadata['copy_invoice_url']);
            }

            if ($candidate) {
                $order->forceFill([
                    'invoice_provider' => 'cashcow',
                    'invoice_url' => $candidate,
                ])->save();

                return $this->successResponse([
                    'order_id' => $order->id,
                    'invoice_url' => $order->invoice_url,
                    'invoice_provider' => $order->invoice_provider,
                ], 'Invoice retrieved from Cashcow');
            }

            return $this->errorResponse('Cashcow orders do not require invoice generation.', 422);
        }

        try {
            $result = $ypayInvoiceService->createInvoiceForOrder($order);
        } catch (Throwable $e) {
            report($e);
            return $this->errorResponse('YPAY invoice generation failed: ' . $e->getMessage(), 500);
        }

        $order->forceFill([
            'invoice_provider' => 'ypay',
            'invoice_url' => $result['invoice_url'],
            'invoice_payload' => $result['payload'],
        ])->save();

        return $this->successResponse([
            'order_id' => $order->id,
            'invoice_url' => $order->invoice_url,
            'invoice_provider' => $order->invoice_provider,
        ], 'Invoice generated successfully');
    }

    private function userCanAccessOrder(User $user, Order $order): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        if ($user->hasRole('merchant')) {
            return (int) $order->merchant_id === (int) $user->id;
        }

        if ($user->hasRole('agent')) {
            if ((int) $order->agent_id === (int) $user->id) {
                return true;
            }

            $managedMerchantIds = $user->agentMerchants()
                ->pluck('user_id')
                ->filter()
                ->unique()
                ->values()
                ->all();

            return !empty($managedMerchantIds) && in_array((int) $order->merchant_id, $managedMerchantIds, true);
        }

        return false;
    }
}
