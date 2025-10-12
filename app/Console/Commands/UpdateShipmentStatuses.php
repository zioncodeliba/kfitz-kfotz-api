<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Shipment;
use App\Services\ShipmentStatusSimulator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UpdateShipmentStatuses extends Command
{
    protected $signature = 'shipments:update-statuses';

    protected $description = 'Simulate updates from shipping providers and advance shipment statuses';

    public function __construct(protected ShipmentStatusSimulator $simulator)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $shipments = Shipment::with('order')->active()->get();

        if ($shipments->isEmpty()) {
            $this->info('No shipments pending updates.');
            return self::SUCCESS;
        }

        foreach ($shipments as $shipment) {
            $order = $shipment->order;
            if (!$order) {
                continue;
            }

            if (!in_array($order->status, [
                Order::STATUS_SHIPPED,
                Order::STATUS_DELIVERED,
            ], true)) {
                $this->warn(sprintf(
                    'Skipping shipment %s because order %s is in status %s',
                    $shipment->tracking_number,
                    $order->order_number,
                    $order->status
                ));
                continue;
            }

            $result = $this->simulator->determineNextStatus($shipment);
            if (!$result) {
                continue;
            }

            DB::transaction(function () use ($shipment, $order, $result) {
                $shipment->fill([
                    'status' => $result['status'],
                ] + ($result['timestamps'] ?? []));
                $shipment->save();

                if (!empty($result['event'])) {
                    $shipment->addTrackingEvent(
                        $result['event']['title'] ?? 'עדכון סטטוס משלוח',
                        $result['event']['description'] ?? null
                    );
                }

                if (!empty($result['order_status'])) {
                    $updates = [
                        'status' => $result['order_status'],
                    ];

                    if ($result['order_status'] === Order::STATUS_DELIVERED) {
                        $updates['delivered_at'] = now();
                    }

                    if ($result['order_status'] === Order::STATUS_SHIPPED && !$order->shipped_at) {
                        $updates['shipped_at'] = now();
                    }

                    $order->update($updates);
                }
            });

            $this->info(sprintf(
                'Shipment %s advanced to %s',
                $shipment->tracking_number,
                $result['status']
            ));
        }

        return self::SUCCESS;
    }
}
