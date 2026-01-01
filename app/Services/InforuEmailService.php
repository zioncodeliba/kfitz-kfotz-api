<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class InforuEmailService
{
    public function sendEmail(array $recipients, string $subject, string $body, array $options = []): array
    {
        $baseUrl = rtrim((string) config('services.inforu.base_url', 'https://capi.inforu.co.il'), '/');
        $basicAuth = trim((string) config('services.inforu.basic_auth', ''));

        if ($basicAuth === '') {
            throw new RuntimeException('Inforu basic auth is not configured.');
        }

        $normalizedRecipients = $this->normalizeRecipients($recipients);
        if (empty($normalizedRecipients)) {
            throw new RuntimeException('Inforu email send aborted: no recipients.');
        }

        $fromAddress = $options['from_address']
            ?? config('services.inforu.from_address')
            ?? config('mail.from.address');
        if (!$fromAddress) {
            throw new RuntimeException('Inforu from address is not configured.');
        }

        $fromName = $options['from_name']
            ?? config('services.inforu.from_name')
            ?? config('mail.from.name')
            ?? config('app.name');
        $replyAddress = $options['reply_address'] ?? config('services.inforu.reply_address') ?? '';
        $campaignName = $options['campaign_name'] ?? $this->buildCampaignName($subject, $options);
        $campaignRefId = $options['campaign_ref_id'] ?? (string) Str::uuid();
        $preHeader = $options['pre_header'] ?? null;

        $data = [
            'CampaignName' => $this->sanitizeCampaignName($campaignName),
            'CampaignRefId' => (string) $campaignRefId,
            'FromAddress' => (string) $fromAddress,
            'ReplyAddress' => (string) $replyAddress,
            'FromName' => (string) $fromName,
            'Subject' => $subject,
            'Body' => $body,
            'IncludeContacts' => $normalizedRecipients,
        ];

        if (is_string($preHeader) && $preHeader !== '') {
            $data['PreHeader'] = $preHeader;
        }

        $timeout = (int) config('services.inforu.timeout', 20);

        $response = Http::baseUrl($baseUrl)
            ->asJson()
            ->withHeaders([
                'Authorization' => 'Basic ' . $basicAuth,
                'Content-Type' => 'application/json; charset=utf-8',
            ])
            ->timeout($timeout)
            ->post('/api/v2/Umail/Message/Send', ['Data' => $data]);

        $responseData = $response->json();
        if (!$response->successful()) {
            $message = sprintf('Inforu send failed (HTTP %d).', $response->status());
            if (!empty($responseData)) {
                $message .= ' Response: ' . json_encode($responseData);
            } elseif ($response->body() !== '') {
                $message .= ' Response: ' . $response->body();
            }
            throw new RuntimeException($message);
        }

        return [
            'campaign' => [
                'name' => $data['CampaignName'],
                'ref_id' => $data['CampaignRefId'],
            ],
            'response' => $responseData ?? $response->body(),
        ];
    }

    public function buildBody(?string $html, ?string $text): string
    {
        if (is_string($html) && trim($html) !== '') {
            return $html;
        }

        $text = $text ?? '';
        if (trim($text) === '') {
            return '';
        }

        return nl2br(e($text));
    }

    private function buildCampaignName(string $subject, array $options): string
    {
        $prefix = trim((string) config('services.inforu.campaign_prefix', 'kfitz'));
        $eventKey = $options['event_key'] ?? null;

        if (is_string($eventKey) && trim($eventKey) !== '') {
            $label = trim($eventKey);
        } else {
            $label = Str::slug(Str::limit($subject, 50, ''), '_');
        }

        if ($label === '') {
            $label = 'message';
        }

        return $prefix !== '' ? $prefix . '_' . $label : $label;
    }

    private function sanitizeCampaignName(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/\s+/', '_', $name);

        return $name === '' ? 'message' : $name;
    }

    private function normalizeRecipients(array $recipients): array
    {
        $normalized = [];
        $seen = [];

        foreach ($recipients as $recipient) {
            if (!is_array($recipient)) {
                continue;
            }

            $email = trim((string) ($recipient['email'] ?? ''));
            if ($email === '') {
                continue;
            }

            $key = strtolower($email);
            if (isset($seen[$key])) {
                continue;
            }

            $contact = ['Email' => $email];
            $name = $recipient['name'] ?? null;
            if (is_string($name) && trim($name) !== '') {
                $contact['FirstName'] = trim($name);
            }

            $normalized[] = $contact;
            $seen[$key] = true;
        }

        return $normalized;
    }
}
