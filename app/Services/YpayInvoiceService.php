<?php

namespace App\Services;

use App\Models\Order;
use App\Models\MerchantPayment;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YpayInvoiceService
{
    /**
     * @return array{invoice_url:string, payload:array}
     */
    public function createInvoiceForOrder(Order $order): array
    {
        $order->loadMissing(['merchant.merchant', 'items']);

        $merchantUser = $order->merchant;
        if (!$merchantUser instanceof User) {
            throw new \RuntimeException('Order is missing merchant data.');
        }

        $total = (float) $order->total;
        if (!is_finite($total) || $total <= 0) {
            throw new \RuntimeException('Order total must be a positive number to generate an invoice.');
        }

        $details = sprintf('Order %s', $order->order_number);
        $payload = [
            'docType' => 106,
            'mail' => false,
            'details' => $details,
            'lang' => 'he',
            'currency' => 'ILS',
            'contact' => $this->buildMerchantContact($merchantUser, $details),
            'items' => [
                [
                    'price' => $total,
                    'quantity' => 1.0,
                    'vatIncluded' => true,
                    'name' => $details,
                    'description' => sprintf(
                        'Products: %.2f | VAT: %.2f | Shipping: %.2f',
                        (float) $order->subtotal,
                        (float) $order->tax,
                        (float) $order->shipping_cost
                    ),
                ],
            ],
            'methods' => [
                [
                    'type' => 1,
                    'total' => $total,
                    'date' => now()->toDateString(),
                ],
            ],
        ];
        // $payload = [
        //     'docType' => 108,
        //     'mail' => true,
        //     'details' => 'test test test test',
        //     'lang' => 'he',
        //     'contact' => [
        //         'email' => 'zioncodeliba@gmail.com',
        //         'businessID' => '040888888',
        //         'name' => 'test test',
        //         'phone' => '02-5866119',
        //         'mobile' => '0504071205',
        //         'zipcode' => '5260170',
        //         'website' => 'wwww.mywebsite.co.il',
        //         'address' => 'Hgavish 2, Rannana',
        //         'comments' => 'Just a comment',
        //     ],
        //     'items' => [
        //         [
        //             'price' => 1,
        //             'quantity' => 1.0,
        //             'vatIncluded' => true,
        //             'name' => 'test monthly payment',
        //             'description' => 'test test test test test test test',
        //         ],
        //     ],
        //     'methods' => [
        //         [
        //             'type' => 1,
        //             'total' => 1.0,
        //             'date' => now()->toDateString(),
        //         ],
        //     ],
        // ];

        return $this->sendDocument($payload);
    }

    /**
     * @return array{invoice_url:string, payload:array}
     */
    public function createReceiptForMerchantPayment(MerchantPayment $payment): array
    {
        $payment->loadMissing(['merchant.merchant']);

        $merchantUser = $payment->merchant;
        if (!$merchantUser instanceof User) {
            throw new \RuntimeException('Payment is missing merchant data.');
        }

        $amount = (float) $payment->amount;
        if (!is_finite($amount) || $amount <= 0) {
            throw new \RuntimeException('Payment amount must be a positive number to generate a receipt.');
        }

        $paymentMonth = is_string($payment->payment_month) && trim($payment->payment_month) !== ''
            ? trim($payment->payment_month)
            : now()->format('Y-m');
        $details = sprintf('Monthly payment %s', $paymentMonth);
        $currency = is_string($payment->currency) && trim($payment->currency) !== ''
            ? strtoupper(trim($payment->currency))
            : 'ILS';

        $payload = [
            'docType' => 108,
            'mail' => false,
            'details' => $details,
            'lang' => 'he',
            'currency' => $currency,
            'contact' => $this->buildMerchantContact($merchantUser, $details),
            'items' => [
                [
                    'price' => $amount,
                    'quantity' => 1.0,
                    'vatIncluded' => true,
                    'name' => $details,
                    'description' => $details,
                ],
            ],
            'methods' => [
                [
                    'type' => 1,
                    'total' => $amount,
                    'date' => ($payment->paid_at?->toDateString()) ?? now()->toDateString(),
                ],
            ],
        ];

        return $this->sendDocument($payload);
    }

    private function buildMerchantContact(User $merchantUser, ?string $comments = null): array
    {
        $merchantUser->loadMissing('merchant');

        $merchantProfile = $merchantUser->merchant;
        if (!$merchantProfile) {
            throw new \RuntimeException('Merchant profile is missing.');
        }

        $businessId = is_string($merchantProfile->business_id ?? null) ? trim((string) $merchantProfile->business_id) : '';
        if ($businessId === '') {
            throw new \RuntimeException('Merchant business ID is missing. Please update the merchant profile.');
        }

        $email = is_string($merchantProfile->email_for_orders ?? null) ? trim((string) $merchantProfile->email_for_orders) : '';
        if ($email === '') {
            $email = is_string($merchantUser->email ?? null) ? trim((string) $merchantUser->email) : '';
        }

        $contactName = is_string($merchantProfile->business_name ?? null) ? trim((string) $merchantProfile->business_name) : '';
        if ($contactName === '') {
            $contactName = is_string($merchantUser->name ?? null) ? trim((string) $merchantUser->name) : '';
        }

        $phone = is_string($merchantProfile->phone ?? null) ? trim((string) $merchantProfile->phone) : '';
        if ($phone === '') {
            $phone = is_string($merchantUser->phone ?? null) ? trim((string) $merchantUser->phone) : '';
        }

        $addressData = $merchantProfile->address;
        $address = $this->formatAddress($addressData);
        $zipcode = $this->extractZipcode($addressData);

        $website = is_string($merchantProfile->website ?? null) ? trim((string) $merchantProfile->website) : '';
        $commentValue = is_string($comments) && trim($comments) !== '' ? trim($comments) : null;

        return array_filter([
            'email' => $email !== '' ? $email : null,
            'businessID' => $businessId,
            'name' => $contactName !== '' ? $contactName : null,
            'phone' => $phone !== '' ? $phone : null,
            'mobile' => $phone !== '' ? $phone : null,
            'zipcode' => $zipcode !== '' ? $zipcode : null,
            'website' => $website !== '' ? $website : null,
            'address' => $address !== '' ? $address : null,
            'comments' => $commentValue,
        ], static fn ($value) => $value !== null);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{invoice_url:string, payload:array}
     */
    private function sendDocument(array $payload): array
    {
        $baseUrl = rtrim((string) config('ypay.base_url'), '/');
        $accessTokenPath = (string) config('ypay.access_token_path');
        $documentGeneratorPath = (string) config('ypay.document_generator_path');
        $clientId = (string) config('ypay.client_id');
        $clientSecret = (string) config('ypay.client_secret');
        $timeout = (int) config('ypay.timeout', 30);

        if ($baseUrl === '' || $clientId === '' || $clientSecret === '') {
            throw new \RuntimeException('YPAY is not configured. Please set YPAY_CLIENT_ID and YPAY_CLIENT_SECRET.');
        }

        $tokenUrl = $baseUrl . '/' . ltrim($accessTokenPath, '/');
        $tokenResponse = Http::timeout($timeout)
            ->retry(2, 250)
            ->acceptJson()
            ->asJson()
            ->post($tokenUrl, [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ]);

        $tokenJson = $tokenResponse->json();
        if (!is_array($tokenJson) || empty($tokenJson['access_token'])) {
            Log::warning('YPAY accessToken response invalid', [
                'status' => $tokenResponse->status(),
                'body' => $this->truncateBody($tokenResponse->body()),
            ]);

            $message = is_array($tokenJson) && isset($tokenJson['message']) ? (string) $tokenJson['message'] : null;
            throw new \RuntimeException($message ? 'YPAY token error: ' . $message : 'Failed to generate YPAY access token.');
        }

        $accessToken = (string) $tokenJson['access_token'];

        $documentUrl = $baseUrl . '/' . ltrim($documentGeneratorPath, '/');
        // $documentUrl = 'https://webhook.site/07a96283-acdf-4bd1-aabb-f8995b0bae25';
        // $documentUrl = 'http://localhost:8001/api/ypay/test-pdf';
        $documentResponse = Http::timeout($timeout)
            ->retry(2, 250)
            ->acceptJson()
            ->withToken($accessToken)
            ->asJson()
            ->post($documentUrl, $payload);

        $documentJson = $documentResponse->json();
        if (!is_array($documentJson)) {
            Log::warning('YPAY documentGenerator returned non-JSON payload', [
                'status' => $documentResponse->status(),
                'body' => $this->truncateBody($documentResponse->body()),
            ]);
            throw new \RuntimeException('YPAY document generator returned an invalid response.');
        }

        $invoiceUrl = $this->extractInvoiceUrl($documentJson, $baseUrl);
        if ($invoiceUrl === null) {
            Log::warning('YPAY documentGenerator response missing invoice url', [
                'status' => $documentResponse->status(),
                'payload_keys' => array_keys($documentJson),
            ]);
            throw new \RuntimeException('YPAY did not return an invoice download URL.');
        }

        return [
            'invoice_url' => $invoiceUrl,
            'payload' => $documentJson,
        ];
    }

    private function truncateBody(string $body, int $limit = 800): string
    {
        $normalized = trim($body);
        if (mb_strlen($normalized) <= $limit) {
            return $normalized;
        }

        return mb_substr($normalized, 0, $limit) . '...';
    }

    private function formatAddress($address): string
    {
        if (is_string($address)) {
            return trim($address);
        }

        if (!is_array($address)) {
            return '';
        }

        $parts = [];

        foreach (['street', 'address', 'line1', 'city', 'state', 'country'] as $key) {
            if (isset($address[$key]) && is_string($address[$key])) {
                $value = trim($address[$key]);
                if ($value !== '') {
                    $parts[] = $value;
                }
            }
        }

        return implode(', ', $parts);
    }

    private function extractZipcode($address): string
    {
        if (!is_array($address)) {
            return '';
        }

        foreach (['zipcode', 'zip', 'postal_code', 'postalCode'] as $key) {
            if (isset($address[$key]) && is_scalar($address[$key])) {
                $value = trim((string) $address[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    private function extractInvoiceUrl(array $payload, string $baseUrl): ?string
    {
        $knownKeys = [
            'invoice_url',
            'invoiceUrl',
            'download_url',
            'downloadUrl',
            'download_link',
            'downloadLink',
            'pdf_url',
            'pdfUrl',
            'document_url',
            'documentUrl',
            'doc_url',
            'docUrl',
            'url',
            'link',
            'href',
        ];

        foreach ($knownKeys as $key) {
            $value = $this->findValueByKey($payload, $key);
            if (is_string($value) && trim($value) !== '') {
                return $this->normalizeUrl(trim($value), $baseUrl);
            }
        }

        $url = $this->findFirstUrl($payload);
        if ($url === null) {
            return null;
        }

        return $this->normalizeUrl($url, $baseUrl);
    }

    private function normalizeUrl(string $url, string $baseUrl): string
    {
        if (str_starts_with($url, '/')) {
            return $baseUrl . $url;
        }

        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }

        return $url;
    }

    private function findValueByKey(array $payload, string $needle)
    {
        foreach ($payload as $key => $value) {
            if ($key === $needle) {
                return $value;
            }

            if (is_array($value)) {
                $found = $this->findValueByKey($value, $needle);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function findFirstUrl($payload): ?string
    {
        if (is_string($payload)) {
            $candidate = trim($payload);
            if (preg_match('#^https?://#i', $candidate) === 1) {
                return $candidate;
            }
            if (str_starts_with($candidate, '/')) {
                return $candidate;
            }
            return null;
        }

        if (!is_array($payload)) {
            return null;
        }

        $prioritized = [];
        $fallback = [];

        foreach ($payload as $value) {
            if (is_string($value)) {
                $candidate = trim($value);
                if ($candidate === '') {
                    continue;
                }
                if (preg_match('#^https?://#i', $candidate) === 1 || str_starts_with($candidate, '/')) {
                    if (stripos($candidate, '.pdf') !== false || stripos($candidate, 'pdf') !== false) {
                        $prioritized[] = $candidate;
                    } else {
                        $fallback[] = $candidate;
                    }
                }
                continue;
            }

            if (is_array($value)) {
                $found = $this->findFirstUrl($value);
                if ($found !== null) {
                    if (stripos($found, '.pdf') !== false || stripos($found, 'pdf') !== false) {
                        $prioritized[] = $found;
                    } else {
                        $fallback[] = $found;
                    }
                }
            }
        }

        return $prioritized[0] ?? $fallback[0] ?? null;
    }
}
