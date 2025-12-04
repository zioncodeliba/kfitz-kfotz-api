<?php

namespace App\Console\Commands;

use App\Services\CashcowInventorySyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncCashcowInventory extends Command
{
    protected $signature = 'cashcow:sync-inventory';

    protected $description = 'Sync product inventory and variations from Cashcow API';

    public function handle(CashcowInventorySyncService $syncService): int
    {
        try {
            $result = $syncService->sync();
        } catch (Throwable $e) {
            $this->error('Sync failed: ' . $e->getMessage());
            report($e);
            return self::FAILURE;
        }

        $this->info(sprintf(
            'Sync completed. Pages: %d, Products: %d, Variations: %d, Missing: %d',
            $result['pages'],
            $result['products_updated'],
            $result['variations_updated'],
            count($result['missing_products'])
        ));

        if (!empty($result['missing_products'])) {
            $this->line('Missing SKUs: ' . implode(', ', $result['missing_products']));
        }

        return self::SUCCESS;
    }
}
