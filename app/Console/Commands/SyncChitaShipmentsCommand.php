<?php

namespace App\Console\Commands;

use App\Models\Shipment;
use App\Models\ShippingCarrier;
use App\Services\ChitaTrackingService;
use Illuminate\Console\Command;

class SyncChitaShipmentsCommand extends Command
{
    protected $signature = 'chita:sync-statuses {--limit=50 : Number of shipments to sync per run}';

    protected $description = 'Sync Chita shipment statuses for active shipments';

    public function handle(ChitaTrackingService $service): int
    {
        $limit = (int) $this->option('limit');
        $limit = $limit > 0 ? $limit : 50;

        $carrierIds = ShippingCarrier::where('code', 'chita')->pluck('id')->all();

        $query = Shipment::whereIn('status', Shipment::ACTIVE_STATUSES)
            ->where(function ($q) use ($carrierIds) {
                $q->where('carrier', 'chita');

                if (!empty($carrierIds)) {
                    $q->orWhereIn('carrier_id', $carrierIds);
                }
            })
            ->orderBy('id');

        $count = 0;

        $query->chunkById($limit, function ($shipments) use ($service, &$count) {
            foreach ($shipments as $shipment) {
                $newStatus = $service->syncShipment($shipment);
                $count++;
                $this->info(sprintf(
                    'Shipment #%d (%s) synced -> %s',
                    $shipment->id,
                    $shipment->tracking_number ?? 'N/A',
                    $newStatus ?? 'no-change'
                ));
            }
        });

        $this->info("Total synced: {$count}");

        return static::SUCCESS;
    }
}
