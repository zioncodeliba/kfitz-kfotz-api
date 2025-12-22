<?php

namespace App\Services;

use App\Models\Shipment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SimpleXMLElement;

class ChitaTrackingService
{
    public function fetchShipmentData(string $shipNumber): ?array
    {
        $token = (string) config('chita.token');
        $baseUrl = rtrim((string) config('chita.base_url'), '/');
        $appName = (string) config('chita.app_name', 'run');
        $program = (string) config('chita.program', 'ship_status_xml');
        $argumentPrefix = (string) config('chita.argument_prefix', '-N');

        if ($token === '' || $baseUrl === '') {
            Log::warning('Chita token or base URL missing');
            return null;
        }

        $response = Http::timeout(20)
            ->withToken($token)
            ->get($baseUrl, [
                'APPNAME' => $appName,
                'PRGNAME' => $program,
                'ARGUMENTS' => $argumentPrefix . $shipNumber,
            ]);

        if ($response->failed()) {
            Log::warning('Chita status request failed', [
                'ship_number' => $shipNumber,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return null;
        }

        $xml = $response->body();
        if ($xml === '' || str_starts_with(trim($xml), '{')) {
            Log::warning('Chita status response not XML', ['ship_number' => $shipNumber]);
            return null;
        }

        $parsed = $this->parseXml($xml);
        if ($parsed === null) {
            Log::warning('Chita status XML parse failed', ['ship_number' => $shipNumber]);
        }

        return $parsed;
    }

    public function syncShipment(Shipment $shipment): ?string
    {
        $shipNumber = trim((string) ($shipment->tracking_number ?? ''));
        if ($shipNumber === '') {
            return null;
        }

        $data = $this->fetchShipmentData($shipNumber);
        if (!$data) {
            return null;
        }

        $newStatus = $this->mapStatus($data);

        $events = $this->mergeTrackingEvents(
            is_array($shipment->tracking_events) ? $shipment->tracking_events : [],
            $data['statuses'] ?? []
        );

        if ($shipment->status !== $newStatus) {
            $shipment->updateStatus($newStatus);
        }

        $shipment->tracking_events = $events;
        $shipment->save();

        return $newStatus;
    }

    private function parseXml(string $xml): ?array
    {
        try {
            $root = new SimpleXMLElement($xml);
        } catch (\Throwable $e) {
            Log::warning('Chita XML parse error', ['error' => $e->getMessage()]);
            return null;
        }

        $data = $root->mydata ?? null;
        if ($data === null) {
            return null;
        }

        $statuses = [];
        if (isset($data->status)) {
            foreach ($data->status as $status) {
                $statuses[] = [
                    'code' => isset($status->status_code) ? (string) $status->status_code : null,
                    'desc' => isset($status->status_desc) ? (string) $status->status_desc : null,
                    'date' => isset($status->status_date) ? (string) $status->status_date : null,
                    'time' => isset($status->status_time) ? (string) $status->status_time : null,
                    'closed' => isset($status->status_closed_yn) ? (string) $status->status_closed_yn === 'y' : false,
                    'raw' => json_decode(json_encode($status), true),
                ];
            }
        }

        return [
            'ship_number' => (string) ($data->ship_no ?? ''),
            'current_stage_code' => isset($data->current_stage_code) ? (string) $data->current_stage_code : null,
            'current_stage_desc' => isset($data->current_stage_desc) ? (string) $data->current_stage_desc : null,
            'ship_delivered' => isset($data->ship_delivered_yn) ? (string) $data->ship_delivered_yn === 'y' : false,
            'ship_canceled' => isset($data->ship_canceled_yn) ? (string) $data->ship_canceled_yn === 'y' : false,
            'statuses' => $statuses,
        ];
    }

    private function mapStatus(array $data): string
    {
        if (!empty($data['ship_canceled'])) {
            return Shipment::STATUS_FAILED;
        }

        if (!empty($data['ship_delivered'])) {
            return Shipment::STATUS_DELIVERED;
        }

        $code = isset($data['current_stage_code']) ? (string) $data['current_stage_code'] : '';

        return match ($code) {
            '99' => Shipment::STATUS_DELIVERED,
            '27' => Shipment::STATUS_OUT_FOR_DELIVERY,
            '21', '22', '23' => Shipment::STATUS_IN_TRANSIT,
            '15' => Shipment::STATUS_PICKED_UP,
            default => Shipment::STATUS_PENDING,
        };
    }

    private function mergeTrackingEvents(array $existing, array $chitaStatuses): array
    {
        $events = $existing;

        foreach ($chitaStatuses as $status) {
            $date = $status['date'] ?? '';
            $time = $status['time'] ?? '';
            $timestamp = null;

            if ($date !== '' && $time !== '') {
                $timestamp = Carbon::createFromFormat('d/m/Y H:i:s', "{$date} {$time}", 'Asia/Jerusalem');
            }

            $events[] = [
                'timestamp' => $timestamp ? $timestamp->toIso8601String() : null,
                'event' => $status['desc'] ?? 'עדכון סטטוס',
                'code' => $status['code'] ?? null,
                'provider' => 'chita',
                'raw' => $status['raw'] ?? null,
            ];
        }

        // Remove null timestamps duplicates (basic dedupe by code+event+timestamp)
        $events = collect($events)
            ->unique(function ($event) {
                return ($event['code'] ?? '') . '|' . ($event['event'] ?? '') . '|' . ($event['timestamp'] ?? '');
            })
            ->values()
            ->all();

        return $events;
    }
}
