<?php

namespace App\Services;

use App\Exceptions\ChitaShipmentException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SimpleXMLElement;

class ChitaShipmentService
{
    private const CARGO_TYPE_MAP = [
        'regular' => '199',
        'box' => '199',
        'oversized' => '155',
        'pallet' => '170',
    ];

    public function createShipment(array $context): array
    {
        $token = trim((string) config('chita.token'));
        $baseUrl = rtrim((string) config('chita.base_url'), '/');
        $appName = (string) config('chita.app_name', 'run');
        $program = (string) config('chita.create_program', 'ship_create_anonymous');

        if ($token === '' || $baseUrl === '') {
            throw new ChitaShipmentException('Chita token or base URL missing.');
        }

        $customerNumber = trim((string) config('chita.customer_number'));
        if ($customerNumber === '') {
            throw new ChitaShipmentException('CHITA_CUSTOMER_NUMBER is not configured.');
        }

        $shipmentType = trim((string) config('chita.shipment_type'));
        if ($shipmentType === '') {
            throw new ChitaShipmentException('CHITA_SHIPMENT_TYPE is not configured.');
        }

        $shipmentStage = trim((string) config('chita.shipment_stage'));
        $companyName = $this->sanitizeString($context['company_name'] ?? config('chita.company_name', ''));
        $deliveryType = $this->resolveDeliveryType($context['shipping_type'] ?? null);

        $destination = is_array($context['destination'] ?? null) ? $context['destination'] : [];
        $consigneeName = $this->sanitizeString($destination['name'] ?? '');
        $city = $this->sanitizeString($destination['city'] ?? '');
        $street = $this->sanitizeString($destination['street'] ?? '');
        $phone = $this->sanitizeString($destination['phone'] ?? '');
        $phone2 = $this->sanitizeString($destination['secondary_phone'] ?? '');
        // $phone = '0504071205';
        // $phone2 = '0504071205';
        $email = $this->sanitizeString($destination['email'] ?? '');

        $reference = $this->sanitizeString($context['reference'] ?? '');
        $referenceSecondary = $this->sanitizeString($context['reference_secondary'] ?? '');

        $shippingUnits = is_array($context['shipping_units'] ?? null) ? $context['shipping_units'] : [];
        $cargoType = $this->buildCargoType($shippingUnits);
        $packageCount = $this->sumPackageCount($shippingUnits);

        $notes = $this->sanitizeString($context['order_skus'] ?? '');
        $codPayment = (bool) ($context['cod_payment'] ?? false);
        $codAmount = $codPayment ? $this->formatNumber($context['cod_amount'] ?? null) : '';
        $codTypeCode = $this->resolveCodTypeCode($codPayment, $context['cod_method'] ?? null);
        $codDueDate = $codPayment ? $this->formatChitaDate($context['cod_due_date'] ?? null) : '';
        $codNotes = $codPayment ? $this->sanitizeString($context['cod_notes'] ?? '') : '';

        $responseType = $this->sanitizeString(config('chita.response_type', 'XML'));
        $pickupPointAssign = $this->sanitizeString(config('chita.pickup_point_assign', 'N'));

        $arguments = [
            ['type' => 'N', 'value' => $customerNumber], // P1
            ['type' => 'A', 'value' => $deliveryType], // P2
            ['type' => 'N', 'value' => $shipmentType], // P3
            ['type' => 'N', 'value' => $shipmentStage], // P4
            ['type' => 'A', 'value' => $companyName], // P5
            ['type' => 'A', 'value' => ''], // P6
            ['type' => 'N', 'value' => $cargoType], // P7
            ['type' => 'N', 'value' => ''], // P8
            ['type' => 'N', 'value' => ''], // P9
            ['type' => 'N', 'value' => ''], // P10
            ['type' => 'A', 'value' => $consigneeName], // P11
            ['type' => 'A', 'value' => ''], // P12
            ['type' => 'A', 'value' => $city], // P13
            ['type' => 'A', 'value' => ''], // P14
            ['type' => 'A', 'value' => $street], // P15
            ['type' => 'A', 'value' => ''], // P16
            ['type' => 'A', 'value' => ''], // P17
            ['type' => 'A', 'value' => ''], // P18
            ['type' => 'A', 'value' => ''], // P19
            ['type' => 'A', 'value' => $phone], // P20
            ['type' => 'A', 'value' => $phone2], // P21
            ['type' => 'A', 'value' => $reference], // P22
            ['type' => 'A', 'value' => (string) $packageCount], // P23
            ['type' => 'A', 'value' => ''], // P24
            ['type' => 'A', 'value' => $notes], // P25
            ['type' => 'A', 'value' => $referenceSecondary], // P26
            ['type' => 'A', 'value' => ''], // P27
            ['type' => 'A', 'value' => ''], // P28
            ['type' => 'N', 'value' => ''], // P29
            ['type' => 'N', 'value' => $codTypeCode], // P30
            ['type' => 'N', 'value' => $codAmount], // P31
            ['type' => 'A', 'value' => $codDueDate], // P32
            ['type' => 'A', 'value' => $codNotes], // P33
            ['type' => 'N', 'value' => ''], // P34
            ['type' => 'N', 'value' => ''], // P35
            ['type' => 'A', 'value' => $responseType], // P36
            ['type' => 'A', 'value' => $pickupPointAssign], // P37
            ['type' => 'A', 'value' => ''], // P38
            ['type' => 'N', 'value' => ''], // P39
            ['type' => 'A', 'value' => $email], // P40
            ['type' => 'A', 'value' => ''], // P41
            ['type' => 'A', 'value' => ''], // P42
        ];

        $queryParams = [
            'APPNAME' => $appName,
            'PRGNAME' => $program,
            'ARGUMENTS' => $this->buildArgumentString($arguments),
        ];

        Log::channel('chita_sync')->info('Chita create shipment start', [
            'order_id' => $context['order_id'] ?? null,
            'params' => $queryParams,
        ]);

        $response = Http::timeout(20)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $token,
            ])
            ->get($baseUrl, $queryParams);

        Log::channel('chita_sync')->info('Chita create shipment response', [
            'order_id' => $context['order_id'] ?? null,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        if ($response->failed()) {
            throw new ChitaShipmentException('Chita create shipment request failed.');
        }

        $parsed = $this->parseCreateResponse($response->body());

        if ($parsed['error']) {
            $message = trim($parsed['message'] ?? '');
            if ($message === '') {
                $message = 'Chita create shipment failed.';
            }
            throw new ChitaShipmentException($message);
        }

        $trackingNumber = trim((string) ($parsed['tracking_number'] ?? ''));
        if ($trackingNumber === '') {
            throw new ChitaShipmentException('Chita returned empty tracking number.');
        }

        return [
            'tracking_number' => $trackingNumber,
            'raw' => $parsed,
        ];
    }

    private function resolveDeliveryType(?string $shippingType): string
    {
        $normalized = strtolower(trim((string) $shippingType));

        return $normalized === 'pickup' ? 'איסוף' : 'מסירה';
    }

    private function resolveCodTypeCode(bool $codPayment, ?string $codMethod): string
    {
        if (!$codPayment) {
            return '';
        }

        $normalized = strtolower(trim((string) $codMethod));

        return match ($normalized) {
            'check' => '1',
            'cash' => '2',
            default => $this->sanitizeString(config('chita.cod_type_code', '')),
        };
    }

    private function formatChitaDate(?string $value): string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return '';
        }

        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $raw)) {
            return $raw;
        }

        $date = null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw)) {
            $date = \DateTime::createFromFormat('Y-m-d', substr($raw, 0, 10));
        } else {
            try {
                $date = new \DateTime($raw);
            } catch (\Throwable) {
                return '';
            }
        }

        return $date ? $date->format('d/m/Y') : '';
    }

    private function buildCargoType(array $shippingUnits): string
    {
        $codes = [];

        foreach ($shippingUnits as $unit) {
            if (!is_array($unit)) {
                continue;
            }

            $size = $unit['shipping_size'] ?? $unit['package_type'] ?? null;
            $code = $this->mapCargoCode($size);
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        if (empty($codes)) {
            $codes[] = self::CARGO_TYPE_MAP['regular'];
        }

        $uniqueCodes = array_values(array_unique($codes));
        if (count($shippingUnits) > 1 && count($uniqueCodes) > 1) {
            return implode(':', $uniqueCodes);
        }

        return $uniqueCodes[0];
    }

    private function mapCargoCode(?string $size): string
    {
        $normalized = strtolower(trim((string) $size));

        if ($normalized === '') {
            return self::CARGO_TYPE_MAP['regular'];
        }

        return self::CARGO_TYPE_MAP[$normalized] ?? self::CARGO_TYPE_MAP['regular'];
    }

    private function sumPackageCount(array $shippingUnits): int
    {
        $count = 0;

        foreach ($shippingUnits as $unit) {
            if (!is_array($unit)) {
                continue;
            }

            $quantity = isset($unit['quantity']) ? (int) $unit['quantity'] : 1;
            $count += max(1, $quantity);
        }

        return max(1, $count);
    }

    private function buildArgumentString(array $arguments): string
    {
        $parts = [];

        foreach ($arguments as $argument) {
            $type = strtoupper(trim((string) ($argument['type'] ?? 'A')));
            $value = $this->sanitizeString($argument['value'] ?? '');
            $parts[] = '-' . $type . $value;
        }

        return implode(',', $parts);
    }

    private function sanitizeString($value): string
    {
        $text = trim((string) $value);
        $text = str_replace([',', '&'], ' ', $text);
        return $text;
    }

    private function formatNumber($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (!is_numeric($value)) {
            return $this->sanitizeString($value);
        }

        $number = number_format((float) $value, 2, '.', '');
        return rtrim(rtrim($number, '0'), '.');
    }

    private function parseCreateResponse(string $xml): array
    {
        if ($xml === '' || str_starts_with(trim($xml), '{')) {
            throw new ChitaShipmentException('Chita response is not XML.');
        }

        try {
            $root = new SimpleXMLElement($xml);
        } catch (\Throwable $exception) {
            throw new ChitaShipmentException('Failed to parse Chita XML response.');
        }

        $data = $root->mydata ?? null;
        if ($data === null) {
            throw new ChitaShipmentException('Chita response missing data.');
        }

        $errorFlag = isset($data->shgiya_yn) ? (string) $data->shgiya_yn : '';
        $message = isset($data->message) ? (string) $data->message : '';
        $answer = $data->answer ?? null;

        $trackingNumber = $answer && isset($answer->ship_create_num)
            ? (string) $answer->ship_create_num
            : '';
        $errorMessage = $answer && isset($answer->ship_create_error)
            ? (string) $answer->ship_create_error
            : '';
        $errorCode = $answer && isset($answer->ship_create_error_code)
            ? (string) $answer->ship_create_error_code
            : '';

        $hasError = strtolower(trim($errorFlag)) === 'y' || trim($errorMessage) !== '';
        $finalMessage = trim($errorMessage) !== '' ? $errorMessage : $message;
        if ($errorCode !== '') {
            $finalMessage = trim($finalMessage . ' (code: ' . $errorCode . ')');
        }

        return [
            'error' => $hasError,
            'message' => $finalMessage,
            'tracking_number' => trim($trackingNumber),
        ];
    }
}
