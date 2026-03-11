<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\YpayTestPdfService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class YpayTestPdfController extends Controller
{
    use ApiResponse;

    public function generate(Request $request, YpayTestPdfService $ypayTestPdfService)
    {
        $data = $request->validate([
            'docType' => 'required',
            'mail' => 'required',
            'details' => 'required|string',
            'lang' => 'required|string',
            'contact' => 'required|array',
            'items' => 'required|array|min:1',
            'methods' => 'required|array|min:1',
        ]);

        return response()->json($ypayTestPdfService->generate($data));
    }
}
