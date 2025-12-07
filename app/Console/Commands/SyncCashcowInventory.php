<?php

namespace App\Console\Commands;

use App\Services\CashcowInventorySyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SyncCashcowInventory extends Command
{
    protected $signature = 'cashcow:sync-inventory';

    protected $description = 'Sync product inventory and variations from Cashcow API';

    public function handle(CashcowInventorySyncService $syncService): int
    {
        $logLines = [];

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
                        break;
                    case 'product':
                        $line = sprintf(
                            '  Product %s stock -> %d',
                            $event['sku'] ?? '?',
                            $event['qty'] ?? 0
                        );
                        $this->line($line);
                        $logLines[] = $line;
                        break;
                    case 'variation':
                        $line = sprintf(
                            '    Variation %s (parent %s) stock -> %d%s%s',
                            $event['variation_sku'] ?? '?',
                            $event['sku'] ?? '?',
                            $event['inventory'] ?? 0,
                            isset($event['display_name']) ? ' | ' . $event['display_name'] : '',
                            isset($event['option_text']) ? ' = ' . $event['option_text'] : ''
                        );
                        $this->line($line);
                        $logLines[] = $line;
                        break;
                }
            });
        } catch (Throwable $e) {
            $this->error('Sync failed: ' . $e->getMessage());
            report($e);
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

        if (!empty($result['missing_products'])) {
            $this->line('Missing SKUs: ' . implode(', ', $result['missing_products']));
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
    }
}
