<?php

namespace App\Services;

use App\Models\EmailLog;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class MailBroadcastService
{
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
                ],
            ]);

            try {
                Mail::send([], [], function ($message) use ($recipient, $renderedSubject, $renderedHtml, $renderedText) {
                    $message->to($recipient['email'], $recipient['name'] ?? null)
                        ->subject($renderedSubject)
                        ->html($renderedHtml);

                    if (!empty($renderedText)) {
                        $message->text($renderedText);
                    }
                });

                $log->update([
                    'status' => 'sent',
                    'sent_at' => now(),
                ]);

                $results['sent']++;
            } catch (\Throwable $exception) {
                Log::error('Broadcast email failed', [
                    'email' => $recipient['email'],
                    'error' => $exception->getMessage(),
                ]);

                $log->update([
                    'status' => 'failed',
                    'error_message' => $exception->getMessage(),
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
