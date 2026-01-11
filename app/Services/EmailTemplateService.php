<?php

namespace App\Services;

use App\Models\EmailLog;
use App\Models\EmailTemplate;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

class EmailTemplateService
{
    public function __construct(
        private InforuEmailService $inforuEmailService,
        private InforuSmsService $inforuSmsService
    )
    {
    }

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
        $rawHtml = $template->body_html ? $this->renderString($template->body_html, $payload) : null;
        $renderedText = $template->body_text ? $this->renderString($template->body_text, $payload) : null;
        $finalHtml = null;

        if (is_string($rawHtml) && trim($rawHtml) !== '') {
            $finalHtml = $this->wrapRtlHtml($rawHtml);
        } elseif (is_string($renderedText) && trim($renderedText) !== '') {
            $finalHtml = $this->wrapRtlHtml(nl2br(e($renderedText)));
        }

        $body = $this->inforuEmailService->buildBody($finalHtml, $renderedText);
        $sendRecipients = $this->flattenRecipients($resolvedRecipients);
        $smsConfig = $this->resolveSmsConfig($template, $payload);

        $meta = [
            'recipients' => $resolvedRecipients,
            'body' => [
                'html' => $finalHtml,
                'text' => $renderedText,
            ],
        ];
        if ($smsConfig['configured']) {
            $meta['sms'] = [
                'enabled' => $smsConfig['enabled'],
                'message' => $smsConfig['message'],
                'recipients' => $smsConfig['recipients'],
                'sender' => $smsConfig['sender'] !== '' ? $smsConfig['sender'] : null,
                'provider' => $smsConfig['provider'],
            ];
        }

        $log = EmailLog::create([
            'email_template_id' => $template->id,
            'email_list_id' => $template->email_list_id,
            'event_key' => $eventKey,
            'recipient_email' => $resolvedRecipients['to'][0]['email'],
            'recipient_name' => $resolvedRecipients['to'][0]['name'] ?? null,
            'subject' => $renderedSubject,
            'status' => 'queued',
            'payload' => $payload,
            'meta' => $meta,
        ]);

        try {
            $result = $this->inforuEmailService->sendEmail($sendRecipients, $renderedSubject, $body, [
                'event_key' => $eventKey,
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
        } catch (Throwable $exception) {
            Log::error('Email send failed', [
                'event_key' => $eventKey,
                'error' => $exception->getMessage(),
            ]);

            $meta = $log->meta ?? [];
            $meta['provider'] = 'inforu';
            $log->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'meta' => $meta,
            ]);
        }

        $this->sendSmsIfEnabled($log, $smsConfig, $eventKey);

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

    protected function wrapRtlHtml(string $html): string
    {
        return <<<HTML
<div dir="rtl" style="direction:rtl;text-align:right;">
  <div style="direction:rtl;text-align:right;unicode-bidi:plaintext;">
    {$html}
  </div>
</div>
HTML;
    }

    protected function resolveSmsConfig(EmailTemplate $template, array $payload): array
    {
        $metadata = $template->metadata;
        if (!is_array($metadata)) {
            return [
                'configured' => false,
                'enabled' => false,
                'message' => '',
                'recipients' => [],
                'sender' => '',
                'provider' => 'inforu',
            ];
        }

        $sms = $metadata['sms'] ?? $metadata['sms_config'] ?? null;
        if (!is_array($sms)) {
            return [
                'configured' => false,
                'enabled' => false,
                'message' => '',
                'recipients' => [],
                'sender' => '',
                'provider' => 'inforu',
            ];
        }

        $message = is_string($sms['message'] ?? null) ? trim($sms['message']) : '';
        if ($message !== '') {
            $message = $this->renderString($message, $payload);
        }

        $sender = is_string($sms['sender'] ?? null) ? trim($sms['sender']) : '';
        $provider = is_string($sms['provider'] ?? null) ? trim($sms['provider']) : 'inforu';

        return [
            'configured' => true,
            'enabled' => filter_var($sms['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'message' => $message,
            'recipients' => $this->normalizeSmsRecipients($sms['recipients'] ?? []),
            'sender' => $sender,
            'provider' => $provider !== '' ? $provider : 'inforu',
        ];
    }

    protected function normalizeSmsRecipients(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $items = is_string($value) ? explode(',', $value) : (array) $value;
        $normalized = [];
        $seen = [];

        foreach ($items as $item) {
            $phone = null;

            if (is_string($item)) {
                $phone = $item;
            } elseif (is_array($item)) {
                $phone = $item['phone'] ?? $item['Phone'] ?? null;
            }

            $phone = preg_replace('/\s+/', '', trim((string) $phone));
            if ($phone === '') {
                continue;
            }

            $key = strtolower($phone);
            if (isset($seen[$key])) {
                continue;
            }

            $normalized[] = $phone;
            $seen[$key] = true;
        }

        return $normalized;
    }

    protected function sendSmsIfEnabled(EmailLog $log, array $smsConfig, string $eventKey): void
    {
        if (!$smsConfig['configured'] || !$smsConfig['enabled']) {
            return;
        }

        if ($smsConfig['message'] === '') {
            $this->applySmsMeta($log, $smsConfig, 'skipped', 'SMS message is empty.');
            Log::warning('SMS skipped: empty message', [
                'event_key' => $eventKey,
                'template_id' => $log->email_template_id,
            ]);
            return;
        }

        if (empty($smsConfig['recipients'])) {
            $this->applySmsMeta($log, $smsConfig, 'skipped', 'SMS recipients are empty.');
            Log::warning('SMS skipped: no recipients', [
                'event_key' => $eventKey,
                'template_id' => $log->email_template_id,
            ]);
            return;
        }

        try {
            $result = $this->inforuSmsService->sendSms($smsConfig['recipients'], $smsConfig['message'], [
                'sender' => $smsConfig['sender'],
            ]);

            $this->applySmsMeta($log, $smsConfig, 'sent', null, $result['response'] ?? $result);
        } catch (Throwable $exception) {
            Log::error('SMS send failed', [
                'event_key' => $eventKey,
                'template_id' => $log->email_template_id,
                'error' => $exception->getMessage(),
            ]);
            $this->applySmsMeta($log, $smsConfig, 'failed', $exception->getMessage());
        }
    }

    protected function applySmsMeta(
        EmailLog $log,
        array $smsConfig,
        string $status,
        ?string $errorMessage = null,
        mixed $providerResponse = null
    ): void
    {
        $meta = $log->meta ?? [];
        $smsMeta = is_array($meta['sms'] ?? null) ? $meta['sms'] : [];

        $meta['sms'] = array_merge($smsMeta, [
            'enabled' => $smsConfig['enabled'],
            'message' => $smsConfig['message'],
            'recipients' => $smsConfig['recipients'],
            'sender' => $smsConfig['sender'] !== '' ? $smsConfig['sender'] : null,
            'provider' => $smsConfig['provider'],
            'status' => $status,
            'error_message' => $errorMessage,
            'provider_response' => $providerResponse,
            'sent_at' => $status === 'sent' ? now()->toDateTimeString() : null,
        ]);

        $log->update(['meta' => $meta]);
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

    protected function flattenRecipients(array $groups): array
    {
        $merged = array_merge($groups['to'] ?? [], $groups['cc'] ?? [], $groups['bcc'] ?? []);
        $seen = [];
        $unique = [];

        foreach ($merged as $recipient) {
            $email = strtolower($recipient['email'] ?? '');
            if ($email === '' || isset($seen[$email])) {
                continue;
            }

            $unique[] = $recipient;
            $seen[$email] = true;
        }

        return $unique;
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
