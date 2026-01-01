<?php

namespace App\Services;

use App\Models\EmailLog;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class MailBroadcastService
{
    public function __construct(private InforuEmailService $inforuEmailService)
    {
    }

    public function sendEmail(array $recipients, string $subjectTemplate, string $bodyTemplate): array
    {
        $results = [
            'total' => count($recipients),
            'sent' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($recipients as $recipient) {
            $payload = [
                'subject' => $subjectTemplate,
                'body' => $bodyTemplate,
                'recipient' => [
                    'email' => $recipient['email'],
                    'name' => $recipient['name'] ?? null,
                    'type' => $recipient['type'] ?? null,
                ],
            ] + ($recipient['context'] ?? []);

            $renderedSubject = $this->renderString($subjectTemplate, $payload);
            $renderedHtml = $this->renderString($bodyTemplate, $payload);
            $renderedText = strip_tags($renderedHtml);
            $body = $this->inforuEmailService->buildBody($renderedHtml, $renderedText);

            $log = EmailLog::create([
                'email_template_id' => null,
                'event_key' => 'broadcast.manual',
                'recipient_email' => $recipient['email'],
                'recipient_name' => $recipient['name'] ?? null,
                'subject' => $renderedSubject,
                'status' => 'queued',
                'payload' => $payload,
                'meta' => [
                    'mode' => 'email',
                    'broadcast_type' => $recipient['type'] ?? null,
                    'body' => [
                        'html' => $renderedHtml,
                        'text' => $renderedText,
                    ],
                ],
            ]);

            try {
                $result = $this->inforuEmailService->sendEmail([
                    [
                        'email' => $recipient['email'],
                        'name' => $recipient['name'] ?? null,
                    ],
                ], $renderedSubject, $body, [
                    'event_key' => 'broadcast.manual',
                    'campaign_ref_id' => (string) $log->id,
                ]);

                $meta = $log->meta ?? [];
                $meta['provider'] = 'inforu';
                $meta['campaign'] = $result['campaign'] ?? null;
                $meta['provider_response'] = $result['response'] ?? null;
                $log->update([
                    'status' => 'sent',
                    'sent_at' => now(),
                    'meta' => $meta,
                ]);

                $results['sent']++;
            } catch (\Throwable $exception) {
                Log::error('Broadcast email failed', [
                    'email' => $recipient['email'],
                    'error' => $exception->getMessage(),
                ]);

                $meta = $log->meta ?? [];
                $meta['provider'] = 'inforu';
                $log->update([
                    'status' => 'failed',
                    'error_message' => $exception->getMessage(),
                    'meta' => $meta,
                ]);

                $results['failed']++;
                $results['errors'][] = [
                    'email' => $recipient['email'],
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return $results;
    }

    protected function renderString(string $template, array $payload): string
    {
        return preg_replace_callback('/{{\s*(.*?)\s*}}/', function ($matches) use ($payload) {
            $key = $matches[1];
            $value = Arr::get($payload, $key);

            return is_scalar($value) ? (string) $value : '';
        }, $template);
    }
}
