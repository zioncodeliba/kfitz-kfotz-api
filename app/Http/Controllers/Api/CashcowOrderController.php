<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CashcowOrderSyncService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Throwable;

class CashcowOrderController extends Controller
{
    use ApiResponse;

    public function sync(Request $request, CashcowOrderSyncService $syncService)
    {
        $data = $request->validate([
            'page' => 'required|integer|min:1',
            'page_size' => 'nullable|integer|min:1|max:200',
        ]);

        $user = $request->user();

        if (!$user || (!$user->hasRole('admin') && !$user->hasRole('merchant'))) {
            return $this->errorResponse('Insufficient permissions', 403);
        }

        try {
            $result = $syncService->sync((int) $data['page'], $data['page_size'] ?? null, $user);
        } catch (Throwable $e) {
            report($e);
            return $this->errorResponse('Cashcow order sync failed: ' . $e->getMessage(), 500);
        }

        return $this->successResponse($result, 'Cashcow orders synced successfully');
    }
}
