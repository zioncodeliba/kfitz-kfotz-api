<?php

namespace App\Services;

use App\Models\EmailLog;
use App\Models\EmailTemplate;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class EmailTemplateService
{
    public function send(string $eventKey, array $payload, array $recipients = []): EmailLog
    {
        $template = EmailTemplate::query()
            ->where('event_key', $eventKey)
            ->where('is_active', true)
            ->first();

        if (!$template) {
            return $this->createLog(null, $eventKey, $payload, 'skipped', 'Email template is not configured.');
        }

        $resolvedRecipients = $this->resolveRecipients($template, $recipients, $payload);

        if (empty($resolvedRecipients['to'])) {
            return $this->createLog($template, $eventKey, $payload, 'skipped', 'No recipients defined for template.');
        }

        $renderedSubject = $this->renderString($template->subject, $payload);
        $renderedHtml = $template->body_html ? $this->renderString($template->body_html, $payload) : null;
        $renderedText = $template->body_text ? $this->renderString($template->body_text, $payload) : null;

        $log = EmailLog::create([
            'email_template_id' => $template->id,
            'event_key' => $eventKey,
            'recipient_email' => $resolvedRecipients['to'][0]['email'],
            'recipient_name' => $resolvedRecipients['to'][0]['name'] ?? null,
            'subject' => $renderedSubject,
            'status' => 'queued',
            'payload' => $payload,
            'meta' => [
                'recipients' => $resolvedRecipients,
            ],
        ]);

        try {
            Mail::send([], [], function ($message) use ($resolvedRecipients, $renderedSubject, $renderedHtml, $renderedText) {
                foreach ($resolvedRecipients['to'] as $recipient) {
                    $message->to($recipient['email'], $recipient['name'] ?? null);
                }

                foreach ($resolvedRecipients['cc'] as $recipient) {
                    $message->cc($recipient['email'], $recipient['name'] ?? null);
                }

                foreach ($resolvedRecipients['bcc'] as $recipient) {
                    $message->bcc($recipient['email'], $recipient['name'] ?? null);
                }

                $message->subject($renderedSubject);

                if ($renderedHtml) {
                    $message->html($renderedHtml);
                    if ($renderedText) {
                        $message->text($renderedText);
                    }
                } elseif ($renderedText) {
                    $message->text($renderedText);
                }
            });

            $log->update([
                'status' => 'sent',
                'sent_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Log::error('Email send failed', [
                'event_key' => $eventKey,
                'error' => $exception->getMessage(),
            ]);

            $log->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
            ]);
        }

        return $log->fresh();
    }

    protected function createLog(?EmailTemplate $template, string $eventKey, array $payload, string $status, ?string $error = null): EmailLog
    {
        return EmailLog::create([
            'email_template_id' => $template?->id,
            'event_key' => $eventKey,
            'recipient_email' => Arr::get($payload, 'recipient.email', ''),
            'recipient_name' => Arr::get($payload, 'recipient.name'),
            'subject' => $template?->subject ?? Arr::get($payload, 'subject', ''),
            'status' => $status,
            'error_message' => $error,
            'payload' => $payload,
            'meta' => null,
        ]);
    }

    protected function resolveRecipients(EmailTemplate $template, array $override, array $payload): array
    {
        $defaults = $template->default_recipients ?? [];
        $resolved = [
            'to' => $this->normalizeRecipients($override['to'] ?? $defaults['to'] ?? []),
            'cc' => $this->normalizeRecipients($override['cc'] ?? $defaults['cc'] ?? []),
            'bcc' => $this->normalizeRecipients($override['bcc'] ?? $defaults['bcc'] ?? []),
        ];

        foreach ($resolved as $type => $list) {
            $resolved[$type] = array_map(function (array $recipient) use ($payload) {
                return [
                    'email' => isset($recipient['email']) ? $this->renderString($recipient['email'], $payload) : null,
                    'name' => isset($recipient['name']) ? $this->renderString($recipient['name'], $payload) : null,
                ];
            }, $list);

            $resolved[$type] = array_values(array_filter($resolved[$type], fn ($recipient) => !empty($recipient['email'])));
        }

        return $resolved;
    }

    protected function normalizeRecipients($value): array
    {
        if (is_null($value)) {
            return [];
        }

        if (is_string($value)) {
            return [['email' => $value]];
        }

        $normalized = [];

        foreach ((array) $value as $item) {
            if (is_string($item)) {
                $normalized[] = ['email' => $item];
                continue;
            }

            if (is_array($item) && isset($item['email'])) {
                $normalized[] = [
                    'email' => $item['email'],
                    'name' => $item['name'] ?? null,
                ];
            }
        }

        return $normalized;
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
