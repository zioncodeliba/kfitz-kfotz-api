<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CashcowInventorySyncService;
use App\Traits\ApiResponse;
use Throwable;

class InventorySyncController extends Controller
{
    use ApiResponse;

    public function sync(CashcowInventorySyncService $syncService)
    {
        try {
            $result = $syncService->sync();
        } catch (Throwable $e) {
            report($e);
            return $this->errorResponse('Inventory sync failed: ' . $e->getMessage(), 500);
        }

        return $this->successResponse($result, 'Inventory sync completed');
    }
}
