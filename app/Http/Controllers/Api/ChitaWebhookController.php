<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ChitaWebhookController extends Controller
{
    /**
     * Receive webhook updates from Chita and log raw payload for later processing.
     */
    public function handle(Request $request)
    {
        Log::channel('chita_webhook')->info('Chita shipment webhook', [
            'query' => $request->query(),
            'headers' => $request->headers->all(),
            'payload' => $request->all(),
            'raw' => $request->getContent(),
        ]);

        return response()->json([
            'message' => 'received',
        ]);
    }
}
