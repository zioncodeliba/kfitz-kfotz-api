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
    public function send(
        string $eventKey,
        array $payload,
        array $recipients = [],
        bool $includeMailingList = true,
        bool $ignoreOverrideRecipients = false
    ): EmailLog
    {
        $templateQuery = EmailTemplate::query()
            ->where('event_key', $eventKey)
            ->where('is_active', true);

        $template = $templateQuery->first();

        if (!$template) {
            Log::warning('Email skipped: template not configured', [
                'event_key' => $eventKey,
                'include_mailing_list' => $includeMailingList,
                'ignore_override_recipients' => $ignoreOverrideRecipients,
                'payload_recipient' => Arr::get($payload, 'recipient.email'),
                'override_recipients' => $this->recipientCounts($recipients, $payload),
            ]);

            return $this->createLog(null, $eventKey, $payload, 'skipped', 'Email template is not configured.');
        }

        if ($includeMailingList && $template->email_list_id) {
            $template->loadMissing(['emailList.contacts' => function ($query) {
                $query->orderBy('name')->orderBy('email');
            }]);
        }

        $resolvedRecipients = $this->resolveRecipients(
            $template,
            $recipients,
            $payload,
            $includeMailingList,
            $ignoreOverrideRecipients
        );

        $hasAnyRecipient = !empty($resolvedRecipients['to']) || !empty($resolvedRecipients['cc']) || !empty($resolvedRecipients['bcc']);
        if (!$hasAnyRecipient) {
            Log::warning('Email skipped: no recipients resolved', [
                'event_key' => $eventKey,
                'template_id' => $template->id,
                'email_list_id' => $template->email_list_id,
                'include_mailing_list' => $includeMailingList,
                'ignore_override_recipients' => $ignoreOverrideRecipients,
                'payload_recipient' => Arr::get($payload, 'recipient.email'),
                'override_recipients' => $this->recipientCounts($recipients, $payload),
                'resolved_recipients' => [
                    'to' => count($resolvedRecipients['to']),
                    'cc' => count($resolvedRecipients['cc']),
                    'bcc' => count($resolvedRecipients['bcc']),
                ],
            ]);

            return $this->createLog($template, $eventKey, $payload, 'skipped', 'No recipients defined for template.');
        }

        $renderedSubject = $this->renderString($template->subject, $payload);
        $renderedHtml = $template->body_html ? $this->renderString($template->body_html, $payload) : null;
        $renderedText = $template->body_text ? $this->renderString($template->body_text, $payload) : null;

        $log = EmailLog::create([
            'email_template_id' => $template->id,
            'email_list_id' => $template->email_list_id,
            'event_key' => $eventKey,
            'recipient_email' => $resolvedRecipients['to'][0]['email'],
            'recipient_name' => $resolvedRecipients['to'][0]['name'] ?? null,
            'subject' => $renderedSubject,
            'status' => 'queued',
            'payload' => $payload,
            'meta' => [
                'recipients' => $resolvedRecipients,
                'body' => [
                    'html' => $renderedHtml,
                    'text' => $renderedText,
                ],
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
            'email_list_id' => $template?->email_list_id,
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

    protected function resolveRecipients(
        EmailTemplate $template,
        array $override,
        array $payload,
        bool $includeMailingList,
        bool $ignoreOverrideRecipients
    ): array
    {
        $templateRecipients = $this->normalizeRecipientGroups($template->default_recipients, $payload);
        $overrideRecipients = $this->normalizeRecipientGroups($override, $payload);

        $resolved = ($ignoreOverrideRecipients || !$this->hasAnyRecipient($overrideRecipients))
            ? $templateRecipients
            : $overrideRecipients;

        if ($includeMailingList && $template->emailList && $template->emailList->relationLoaded('contacts')) {
            $listRecipients = $this->buildRecipientsFromList($template->emailList->contacts);
            if (!empty($listRecipients)) {
                $resolved['to'] = $this->mergeRecipients($resolved['to'], $listRecipients);
            }
        }

        return $resolved;
    }

    protected function normalizeRecipientGroups($value, array $payload): array
    {
        $source = is_array($value) ? $value : [];

        return [
            'to' => $this->normalizeRecipients($source['to'] ?? [], $payload),
            'cc' => $this->normalizeRecipients($source['cc'] ?? [], $payload),
            'bcc' => $this->normalizeRecipients($source['bcc'] ?? [], $payload),
        ];
    }

    protected function normalizeRecipients($value, array $payload = []): array
    {
        if (is_null($value)) {
            return [];
        }

        if (is_string($value)) {
            $email = $this->renderRecipientValue($value, $payload);

            return $email ? [['email' => $email]] : [];
        }

        $normalized = [];

        foreach ((array) $value as $item) {
            if (is_string($item)) {
                $email = $this->renderRecipientValue($item, $payload);
                if ($email) {
                    $normalized[] = ['email' => $email];
                }
                continue;
            }

            if (is_array($item) && isset($item['email'])) {
                $email = $this->renderRecipientValue((string) $item['email'], $payload);
                if (!$email) {
                    continue;
                }

                $name = null;
                if (isset($item['name']) && is_string($item['name'])) {
                    $name = $this->renderRecipientValue($item['name'], $payload);
                }

                $normalized[] = [
                    'email' => $email,
                    'name' => $name,
                ];
            }
        }

        return $normalized;
    }

    protected function buildRecipientsFromList($contacts): array
    {
        if (!$contacts) {
            return [];
        }

        $normalized = [];

        foreach ($contacts as $contact) {
            $email = trim((string) ($contact->email ?? ''));
            if ($email === '') {
                continue;
            }

            $normalized[] = [
                'email' => $email,
                'name' => $contact->name ?: null,
            ];
        }

        return $normalized;
    }

    protected function mergeRecipients(array $base, array $additional): array
    {
        $seen = [];
        foreach ($base as $recipient) {
            $email = strtolower($recipient['email'] ?? '');
            if ($email !== '') {
                $seen[$email] = true;
            }
        }

        foreach ($additional as $recipient) {
            $email = strtolower($recipient['email'] ?? '');
            if ($email === '' || isset($seen[$email])) {
                continue;
            }

            $base[] = $recipient;
            $seen[$email] = true;
        }

        return $base;
    }

    protected function hasAnyRecipient(array $recipients): bool
    {
        return !empty($recipients['to']) || !empty($recipients['cc']) || !empty($recipients['bcc']);
    }

    protected function recipientCounts(array $recipients, array $payload = []): array
    {
        $count = function ($value) use ($payload): int {
            $normalized = $this->normalizeRecipients($value, $payload);
            $filtered = array_filter($normalized, fn ($recipient) => !empty($recipient['email']));

            return count($filtered);
        };

        return [
            'to' => $count($recipients['to'] ?? []),
            'cc' => $count($recipients['cc'] ?? []),
            'bcc' => $count($recipients['bcc'] ?? []),
        ];
    }

    protected function renderRecipientValue(?string $value, array $payload): ?string
    {
        if ($value === null) {
            return null;
        }

        $rendered = $this->renderString($value, $payload);
        $trimmed = trim($rendered);

        return $trimmed === '' ? null : $trimmed;
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
