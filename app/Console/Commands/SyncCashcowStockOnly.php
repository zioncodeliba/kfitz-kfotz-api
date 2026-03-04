<?php

namespace App\Console\Commands;

use App\Services\CashcowProductPushService;
use App\Services\InforuEmailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncCashcowStockOnly extends Command
{
    protected $signature = 'cashcow:sync-stock-only';

    protected $description = 'Push stock-only inventory updates (qty + visibility) to Cashcow';

    public function handle(CashcowProductPushService $service, InforuEmailService $emailService): int
    {
        $logLines = [];
        Log::info('[Cashcow] stock-only sync started');

        try {
            $result = $service->syncInventory(function (array $event) use (&$logLines) {
                $type = $event['type'] ?? '';
                if ($type === 'error') {
                    $line = sprintf(
                        'Error (%s) %s: %s',
                        $event['scope'] ?? 'unknown',
                        $event['sku'] ?? 'n/a',
                        $event['message'] ?? 'unknown error'
                    );
                    $this->error($line);
                    $logLines[] = $line;
                    Log::warning('[Cashcow] ' . $line, ['event' => $event]);
                }
            });
        } catch (Throwable $e) {
            $this->error('Stock-only sync failed: ' . $e->getMessage());
            report($e);
            Log::error('[Cashcow] stock-only sync failed', ['exception' => $e]);
            $this->sendReport($emailService, $logLines, [
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);
            return self::FAILURE;
        }

        $summary = sprintf(
            'Stock-only sync completed. Products: %d/%d, Variations: %d/%d, Skipped: %d, Errors: %d',
            $result['products_updated'] ?? 0,
            $result['products_processed'] ?? 0,
            $result['variations_updated'] ?? 0,
            $result['variations_processed'] ?? 0,
            $result['skipped'] ?? 0,
            $result['errors'] ?? 0
        );

        $this->info($summary);
        Log::info('[Cashcow] ' . $summary);

        $meta = [
            'status' => 'success',
            'summary' => $summary,
        ];

        if (!empty($result['error_samples'])) {
            $meta['errors'] = $result['error_samples'];
        }

        if (!empty($result['skipped_skus'])) {
            $meta['skipped'] = $result['skipped_skus'];
        }

        $this->sendReport($emailService, $logLines, $meta);

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
            $lines[] = 'Skipped SKUs: ' . implode(', ', $meta['skipped']);
        }
        if (!empty($meta['errors'])) {
            $lines[] = 'Error samples: ' . json_encode($meta['errors']);
        }
        if (!empty($meta['error'])) {
            $lines[] = 'Error: ' . $meta['error'];
        }

        $body = implode("\n", $lines);

        $subject = sprintf('[Cashcow Stock-Only Sync] %s (%s)', ucfirst($meta['status'] ?? 'result'), now()->toDateTimeString());
        $htmlBody = $emailService->buildBody(null, $body);

        try {
            $emailService->sendEmail([
                ['email' => $email],
            ], $subject, $htmlBody, [
                'event_key' => 'cashcow.stock_only_report',
            ]);
        } catch (Throwable $exception) {
            Log::error('[Cashcow] stock-only sync report failed', [
                'to' => $email,
                'error' => $exception->getMessage(),
            ]);
            return;
        }

        Log::info('[Cashcow] stock-only sync report dispatched', [
            'to' => $email,
            'status' => $meta['status'] ?? 'unknown',
        ]);
    }
}
