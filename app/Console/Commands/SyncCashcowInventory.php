<?php

namespace App\Console\Commands;

use App\Services\CashcowInventorySyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SyncCashcowInventory extends Command
{
    protected $signature = 'cashcow:sync-inventory';

    protected $description = 'Sync product inventory and variations from Cashcow API';

    public function handle(CashcowInventorySyncService $syncService): int
    {
        $logLines = [];
        Log::info('Cashcow inventory sync started');

        try {
            $result = $syncService->sync(function (array $event) use (&$logLines) {
                switch ($event['type'] ?? '') {
                    case 'page':
                        $line = sprintf(
                            'Page %d fetched (%d items)',
                            $event['page'] ?? 0,
                            $event['items'] ?? 0
                        );
                        $this->info($line);
                        $logLines[] = $line;
                        Log::info('[Cashcow] ' . $line);
                        break;
                    case 'page_summary':
                        $line = sprintf(
                            'Page %d summary: products %d, variations %d, missing %d',
                            $event['page'] ?? 0,
                            $event['products_updated'] ?? 0,
                            $event['variations_updated'] ?? 0,
                            $event['missing_count'] ?? 0
                        );
                        $this->line($line);
                        $logLines[] = $line;
                        $logMethod = ($event['missing_count'] ?? 0) > 0 ? 'warning' : 'info';
                        Log::{$logMethod}('[Cashcow] ' . $line, [
                            'missing_skus' => $event['missing_skus'] ?? [],
                        ]);
                        break;
                }
            });
        } catch (Throwable $e) {
            $this->error('Sync failed: ' . $e->getMessage());
            report($e);
            Log::error('Cashcow inventory sync failed', ['exception' => $e]);
            $this->sendReport($logLines, [
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);
            return self::FAILURE;
        }

        $summary = sprintf(
            'Sync completed. Pages: %d, Products: %d, Variations: %d, Missing: %d',
            $result['pages'],
            $result['products_updated'],
            $result['variations_updated'],
            count($result['missing_products'])
        );

        $this->info($summary);
        Log::info('[Cashcow] ' . $summary);

        if (!empty($result['missing_products'])) {
            $this->line('Missing SKUs: ' . implode(', ', $result['missing_products']));
            Log::warning('[Cashcow] Missing SKUs', ['missing' => $result['missing_products']]);
        }

        $this->sendReport($logLines, [
            'status' => 'success',
            'summary' => $summary,
            'missing' => $result['missing_products'] ?? [],
        ]);

        return self::SUCCESS;
    }

    private function sendReport(array $logLines, array $meta = []): void
    {
        $email = config('cashcow.notify_email');
        if (empty($email)) {
            Log::warning('[Cashcow] notify_email not configured; skipping report', ['meta' => $meta]);
            return;
        }

        $lines = $logLines;
        if (!empty($meta['summary'])) {
            array_unshift($lines, $meta['summary']);
        }
        if (!empty($meta['missing'])) {
            $lines[] = 'Missing SKUs: ' . implode(', ', $meta['missing']);
        }
        if (!empty($meta['error'])) {
            $lines[] = 'Error: ' . $meta['error'];
        }

        $body = implode("\n", $lines);

        Mail::raw($body, function ($message) use ($email, $meta) {
            $status = $meta['status'] ?? 'result';
            $message->to($email)
                ->subject(sprintf('[Cashcow Sync] %s (%s)', ucfirst($status), now()->toDateTimeString()));
        });

        Log::info('[Cashcow] report email dispatched', [
            'to' => $email,
            'status' => $meta['status'] ?? 'unknown',
        ]);
    }
}
