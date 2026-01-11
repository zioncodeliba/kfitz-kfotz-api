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
        $query = EmailLog::query()
            ->with([
                'template:id,event_key,name,email_list_id',
                'template.emailList:id,name',
                'emailList:id,name',
            ])
            ->when($request->filled('event_key'), fn ($query) => $query->where('event_key', $request->input('event_key')))
            ->when($request->filled('email_template_id'), fn ($query) => $query->where('email_template_id', $request->integer('email_template_id')))
            ->when($request->filled('email_list_id'), fn ($query) => $query->where('email_list_id', $request->integer('email_list_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->input('status')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->input('search');
                $query->where(function ($inner) use ($search) {
                    $inner->where('recipient_email', 'like', "%{$search}%")
                        ->orWhere('recipient_name', 'like', "%{$search}%")
                        ->orWhere('subject', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('created_at');

        if ($request->boolean('all')) {
            return $this->successResponse($query->get());
        }

        $logs = $query->paginate($request->integer('per_page', 20));

        return $this->successResponse($logs);
    }

    public function show(EmailLog $log)
    {
        $log->load(['template', 'emailList']);

        return $this->successResponse($log);
    }
}
