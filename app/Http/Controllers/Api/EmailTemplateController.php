<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use App\Services\EmailTemplateService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmailTemplateController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $search = $request->input('search');

        $templates = EmailTemplate::query()
            ->with('emailList:id,name')
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
        return $this->successResponse($template->load('emailList'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'event_key' => [
                'required',
                'string',
                'max:255',
                Rule::unique('email_templates', 'event_key'),
            ],
            'name' => 'required|string|max:255',
            'subject' => 'required|string|max:255',
            'body_html' => 'nullable|string',
            'body_text' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'default_recipients' => 'nullable|array',
            'email_list_id' => 'nullable|exists:email_lists,id',
            'metadata' => 'nullable|array',
        ]);

        $this->assertRecipientSource(
            $validated['default_recipients'] ?? null,
            $validated['email_list_id'] ?? null
        );

        $payload = array_merge(
            [
                'is_active' => true,
            ],
            $validated,
            [
                'updated_by' => $request->user()->id,
            ],
        );

        $template = EmailTemplate::create($payload)->load('emailList');

        return $this->createdResponse($template, 'Template created successfully');
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
            'email_list_id' => 'nullable|exists:email_lists,id',
            'metadata' => 'nullable|array',
        ]);

        $finalEmailListId = array_key_exists('email_list_id', $validated)
            ? $validated['email_list_id']
            : $template->email_list_id;

        $finalRecipients = $validated['default_recipients'] ?? $template->default_recipients;

        $this->assertRecipientSource($finalRecipients, $finalEmailListId);

        $template->fill($validated);
        $template->updated_by = $request->user()->id;
        $template->save();

        return $this->successResponse($template->fresh('emailList'), 'Template updated successfully');
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

        $log = $emailService->send($template->event_key, $payload, $recipients, includeMailingList: false);

        return $this->successResponse($log, 'Test email triggered');
    }

    public function trigger(Request $request, EmailTemplateService $emailService)
    {
        $validated = $request->validate([
            'event_key' => 'required|string|max:255',
            'payload' => 'nullable|array',
            'recipients' => 'nullable|array',
            'recipients.to' => 'nullable|array',
            'recipients.cc' => 'nullable|array',
            'recipients.bcc' => 'nullable|array',
        ]);

        $eventKey = $validated['event_key'];
        $payload = $validated['payload'] ?? [];
        $recipients = $validated['recipients'] ?? [];

        $log = $emailService->send(
            $eventKey,
            $payload,
            $recipients,
            includeMailingList: true,
            ignoreOverrideRecipients: true
        );

        return $this->successResponse($log, 'Email event triggered');
    }

    public function destroy(EmailTemplate $template)
    {
        $template->delete();

        return $this->successResponse(null, 'Template deleted successfully');
    }

    protected function assertRecipientSource(?array $defaultRecipients, ?int $emailListId): void
    {
        if ($emailListId || $this->hasPrimaryRecipients($defaultRecipients)) {
            return;
        }

        throw ValidationException::withMessages([
            'default_recipients.to' => 'Please define at least one TO recipient or choose a mailing list.',
        ]);
    }

    protected function hasPrimaryRecipients($defaultRecipients): bool
    {
        if (!$defaultRecipients || !array_key_exists('to', $defaultRecipients)) {
            return false;
        }

        $to = $defaultRecipients['to'];

        if (is_string($to)) {
            return trim($to) !== '';
        }

        if (is_array($to)) {
            foreach ($to as $recipient) {
                if (is_string($recipient) && trim($recipient) !== '') {
                    return true;
                }

                if (is_array($recipient) && !empty($recipient['email'])) {
                    return true;
                }
            }
        }

        return false;
    }
}
