<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailLog;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class EmailLogController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $logs = EmailLog::query()
            ->with('template:id,event_key,name')
            ->when($request->filled('event_key'), fn ($query) => $query->where('event_key', $request->input('event_key')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->input('status')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->input('search');
                $query->where(function ($inner) use ($search) {
                    $inner->where('recipient_email', 'like', "%{$search}%")
                        ->orWhere('recipient_name', 'like', "%{$search}%")
                        ->orWhere('subject', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return $this->successResponse($logs);
    }

    public function show(EmailLog $log)
    {
        $log->load('template');

        return $this->successResponse($log);
    }
}
