<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use App\Services\EmailTemplateService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class EmailTemplateController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $search = $request->input('search');

        $templates = EmailTemplate::query()
            ->when($search, function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('event_key', 'like', "%{$search}%")
                        ->orWhere('subject', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15));

        return $this->successResponse($templates);
    }

    public function show(EmailTemplate $template)
    {
        return $this->successResponse($template);
    }

    public function update(Request $request, EmailTemplate $template)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'subject' => 'sometimes|required|string|max:255',
            'body_html' => 'nullable|string',
            'body_text' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'default_recipients' => 'nullable|array',
            'metadata' => 'nullable|array',
        ]);

        $template->fill($validated);
        $template->updated_by = $request->user()->id;
        $template->save();

        return $this->successResponse($template->fresh(), 'Template updated successfully');
    }

    public function sendTest(Request $request, EmailTemplate $template, EmailTemplateService $emailService)
    {
        $validated = $request->validate([
            'recipients' => 'required|array',
            'recipients.to' => 'required|array|min:1',
            'recipients.cc' => 'nullable|array',
            'recipients.bcc' => 'nullable|array',
            'payload' => 'nullable|array',
        ]);

        $recipients = $validated['recipients'];
        $payload = $validated['payload'] ?? [];

        $log = $emailService->send($template->event_key, $payload, $recipients);

        return $this->successResponse($log, 'Test email triggered');
    }
}
