<?php

namespace App\Console\Commands;

use App\Services\CashcowOrderSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SyncCashcowOrders extends Command
{
    protected $signature = 'cashcow:sync-orders {--max-pages=20 : Maximum pages to fetch in one run}';

    protected $description = 'Sync orders from Cashcow API into the system';

    public function handle(CashcowOrderSyncService $service): int
    {
        $maxPages = (int) $this->option('max-pages');
        $maxPages = $maxPages > 0 ? $maxPages : 1;

        $page = 1;
        $totalReceived = 0;
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $skippedOrders = [];
        $logLines = [];

        Log::info('[Cashcow] order sync started');

        try {
            while ($page <= $maxPages) {
                $summary = $service->sync($page);

                $ordersReceived = (int) ($summary['orders_received'] ?? 0);
                $totalReceived += $ordersReceived;
                $created += (int) ($summary['created'] ?? 0);
                $updated += (int) ($summary['updated'] ?? 0);
                $skipped += (int) ($summary['skipped'] ?? 0);

                if (!empty($summary['skipped_orders'])) {
                    foreach ($summary['skipped_orders'] as $item) {
                        $skippedOrders[] = $item;
                    }
                }

                $this->info(sprintf(
                    'Page %d: received %d, created %d, updated %d, skipped %d',
                    $page,
                    $ordersReceived,
                    $summary['created'] ?? 0,
                    $summary['updated'] ?? 0,
                    $summary['skipped'] ?? 0
                ));

                $pageSize = (int) ($summary['page_size'] ?? 0);
                $totalRecords = $summary['total_records'] ?? null;

                if ($ordersReceived === 0) {
                    break;
                }

                if ($totalRecords !== null && $pageSize > 0 && ($page * $pageSize) >= (int) $totalRecords) {
                    break;
                }

                $page++;
            }
        } catch (Throwable $e) {
            $this->error('Cashcow order sync failed: ' . $e->getMessage());
            Log::error('[Cashcow] order sync failed', ['exception' => $e]);
            $this->sendReport($logLines, [
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);
            return self::FAILURE;
        }

        $summaryLine = sprintf(
            'Cashcow orders sync done. Pages: %d, Received: %d, Created: %d, Updated: %d, Skipped: %d',
            $page,
            $totalReceived,
            $created,
            $updated,
            $skipped
        );

        $this->info($summaryLine);
        Log::info('[Cashcow] ' . $summaryLine, [
            'skipped_orders' => $skippedOrders,
        ]);
        $logLines[] = '[Cashcow] ' . $summaryLine;

        if (!empty($skippedOrders)) {
            $this->line('Skipped orders: ' . json_encode($skippedOrders));
            $logLines[] = 'Skipped orders: ' . json_encode($skippedOrders);
        }

        $this->sendReport($logLines, [
            'status' => 'success',
            'summary' => $summaryLine,
            'skipped' => $skippedOrders,
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
        if (!empty($meta['skipped'])) {
            $lines[] = 'Skipped orders: ' . json_encode($meta['skipped']);
        }
        if (!empty($meta['error'])) {
            $lines[] = 'Error: ' . $meta['error'];
        }

        $body = implode("\n", $lines);

        Mail::raw($body, function ($message) use ($email, $meta) {
            $status = $meta['status'] ?? 'result';
            $message->to($email)
                ->subject(sprintf('[Cashcow Orders Sync] %s (%s)', ucfirst($status), now()->toDateTimeString()));
        });

        Log::info('[Cashcow] orders sync report dispatched', [
            'to' => $email,
            'status' => $meta['status'] ?? 'unknown',
        ]);
    }
}
