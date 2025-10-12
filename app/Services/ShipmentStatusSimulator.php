<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class ShipmentStatusSimulator
{
    /**
     * Map of statuses to their possible next statuses (for simulation).
     * The first option is considered the standard progression.
     */
    protected array $progressionMap = [
        Shipment::STATUS_PENDING => [Shipment::STATUS_PICKED_UP],
        Shipment::STATUS_PICKED_UP => [Shipment::STATUS_IN_TRANSIT],
        Shipment::STATUS_IN_TRANSIT => [Shipment::STATUS_OUT_FOR_DELIVERY],
        Shipment::STATUS_OUT_FOR_DELIVERY => [Shipment::STATUS_DELIVERED],
    ];

    /**
     * Mapping of shipment statuses to order statuses when delivery is complete.
     */
    protected array $finalStatusToOrderStatus = [
        Shipment::STATUS_DELIVERED => Order::STATUS_DELIVERED,
        Shipment::STATUS_RETURNED => Order::STATUS_DELIVERED,
        Shipment::STATUS_FAILED => Order::STATUS_DELIVERED,
    ];

    protected array $statusEventLabels = [
        Shipment::STATUS_PICKED_UP => 'נאסף על-ידי השליח',
        Shipment::STATUS_IN_TRANSIT => 'המשלוח בדרכו למחסן המרכזי',
        Shipment::STATUS_OUT_FOR_DELIVERY => 'המשלוח בדרכו ללקוח',
        Shipment::STATUS_DELIVERED => 'המשלוח נמסר ללקוח',
        Shipment::STATUS_RETURNED => 'המשלוח הוחזר לשולח',
        Shipment::STATUS_FAILED => 'מסירה נכשלה',
    ];

    public function determineNextStatus(Shipment $shipment): ?array
    {
        $currentStatus = $shipment->status;

        // Already in a final state
        if (in_array($currentStatus, Shipment::FINAL_STATUSES, true)) {
            return null;
        }

        $nextStatuses = $this->progressionMap[$currentStatus] ?? [];
        if (empty($nextStatuses)) {
            return null;
        }

        // For the simulation we take the first status in the progression list.
        $nextStatus = Arr::first($nextStatuses);

        $eventLabel = $this->statusEventLabels[$nextStatus] ?? 'עדכון סטטוס משלוח';

        $orderStatus = $this->finalStatusToOrderStatus[$nextStatus] ?? null;

        return [
            'status' => $nextStatus,
            'order_status' => $orderStatus,
            'event' => [
                'title' => $eventLabel,
                'description' => $this->buildEventDescription($nextStatus, $shipment),
            ],
            'timestamps' => $this->buildTimestampUpdates($nextStatus),
        ];
    }

    protected function buildEventDescription(string $status, Shipment $shipment): ?string
    {
        return match ($status) {
            Shipment::STATUS_PICKED_UP => 'המשלוח נמסר לשליח ויעשה את דרכו למחסן המרכזי.',
            Shipment::STATUS_IN_TRANSIT => 'המשלוח נמצא בשינוע למרכז הלוגיסטי.',
            Shipment::STATUS_OUT_FOR_DELIVERY => 'המשלוח נמצא עם השליח בדרכו ללקוח.',
            Shipment::STATUS_DELIVERED => 'המשלוח נמסר בהצלחה.',
            Shipment::STATUS_RETURNED => 'המשלוח הוחזר לשולח.',
            Shipment::STATUS_FAILED => 'ניסיון המסירה נכשל, השליח ייצור קשר לתיאום נוסף.',
            default => null,
        };
    }

    protected function buildTimestampUpdates(string $status): array
    {
        $now = Carbon::now();

        return match ($status) {
            Shipment::STATUS_PICKED_UP => ['picked_up_at' => $now],
            Shipment::STATUS_IN_TRANSIT => ['in_transit_at' => $now],
            Shipment::STATUS_OUT_FOR_DELIVERY => ['out_for_delivery_at' => $now],
            Shipment::STATUS_DELIVERED => ['delivered_at' => $now],
            Shipment::STATUS_FAILED => ['failed_at' => $now],
            Shipment::STATUS_RETURNED => ['returned_at' => $now],
            default => [],
        };
    }
}
