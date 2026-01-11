<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class InforuSmsService
{
    public function sendSms(array $recipients, string $message, array $options = []): array
    {
        $baseUrl = rtrim((string) config('services.inforu.base_url', 'https://capi.inforu.co.il'), '/');
        $basicAuth = trim((string) config('services.inforu.basic_auth', ''));

        if ($basicAuth === '') {
            throw new RuntimeException('Inforu basic auth is not configured.');
        }

        $normalizedRecipients = $this->normalizeRecipients($recipients);
        if (empty($normalizedRecipients)) {
            throw new RuntimeException('Inforu SMS send aborted: no recipients.');
        }

        $message = trim($message);
        if ($message === '') {
            throw new RuntimeException('Inforu SMS send aborted: empty message.');
        }

        $data = [
            'Message' => $message,
            'Recipients' => $normalizedRecipients,
        ];

        $sender = $options['sender'] ?? null;
        if (is_string($sender) && trim($sender) !== '') {
            $data['Settings'] = [
                'Sender' => trim($sender),
            ];
        }

        $timeout = (int) config('services.inforu.timeout', 20);

        $response = Http::baseUrl($baseUrl)
            ->asJson()
            ->withHeaders([
                'Authorization' => 'Basic ' . $basicAuth,
                'Content-Type' => 'application/json; charset=utf-8',
            ])
            ->timeout($timeout)
            ->post('/api/v2/SMS/SendSms', ['Data' => $data]);

        $responseData = $response->json();
        if (!$response->successful()) {
            $message = sprintf('Inforu SMS send failed (HTTP %d).', $response->status());
            if (!empty($responseData)) {
                $message .= ' Response: ' . json_encode($responseData);
            } elseif ($response->body() !== '') {
                $message .= ' Response: ' . $response->body();
            }
            throw new RuntimeException($message);
        }

        return [
            'response' => $responseData ?? $response->body(),
        ];
    }

    private function normalizeRecipients(array $recipients): array
    {
        $normalized = [];
        $seen = [];

        foreach ($recipients as $recipient) {
            $phone = null;

            if (is_string($recipient)) {
                $phone = $recipient;
            } elseif (is_array($recipient)) {
                $phone = $recipient['phone'] ?? $recipient['Phone'] ?? null;
            }

            $phone = trim((string) $phone);
            if ($phone === '') {
                continue;
            }

            $phone = preg_replace('/\s+/', '', $phone);
            $key = strtolower($phone);
            if (isset($seen[$key])) {
                continue;
            }

            $normalized[] = ['Phone' => $phone];
            $seen[$key] = true;
        }

        return $normalized;
    }
}
