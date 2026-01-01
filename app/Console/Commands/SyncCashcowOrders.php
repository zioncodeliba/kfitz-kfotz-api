<?php

namespace App\Console\Commands;

use App\Services\CashcowOrderSyncService;
use App\Services\InforuEmailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncCashcowOrders extends Command
{
    protected $signature = 'cashcow:sync-orders {--max-pages=20 : Maximum pages to fetch in one run}';

    protected $description = 'Sync orders from Cashcow API into the system';

    public function handle(CashcowOrderSyncService $service, InforuEmailService $emailService): int
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
            $this->sendReport($emailService, $logLines, [
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

        $this->sendReport($emailService, $logLines, [
            'status' => 'success',
            'summary' => $summaryLine,
            'skipped' => $skippedOrders,
        ]);

        return self::SUCCESS;
    }

    private function sendReport(InforuEmailService $emailService, array $logLines, array $meta = []): void
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

        $subject = sprintf('[Cashcow Orders Sync] %s (%s)', ucfirst($meta['status'] ?? 'result'), now()->toDateTimeString());
        $htmlBody = $emailService->buildBody(null, $body);

        try {
            $emailService->sendEmail([
                ['email' => $email],
            ], $subject, $htmlBody, [
                'event_key' => 'cashcow.orders_report',
            ]);
        } catch (Throwable $exception) {
            Log::error('[Cashcow] orders sync report failed', [
                'to' => $email,
                'error' => $exception->getMessage(),
            ]);
            return;
        }

        Log::info('[Cashcow] orders sync report dispatched', [
            'to' => $email,
            'status' => $meta['status'] ?? 'unknown',
        ]);
    }
}
