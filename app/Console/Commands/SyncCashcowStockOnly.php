<?php

namespace App\Console\Commands;

use App\Services\CashcowProductPushService;
use App\Services\InforuEmailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncCashcowStockOnly extends Command
{
    private const MAX_SUCCESS_SAMPLES = 100;

    protected $signature = 'cashcow:sync-stock-only
                            {--limit-products= : Process only the first N products (ordered by id)}';

    protected $description = 'Push stock-only inventory updates (qty + visibility) to Cashcow';

    public function handle(CashcowProductPushService $service, InforuEmailService $emailService): int
    {
        $limitProducts = $this->parseProductsLimit();
        if ($limitProducts === false) {
            return self::FAILURE;
        }

        $logLines = [];
        $successfulSkus = [];
        $updatedTotalLive = 0;
        Log::info('[Cashcow] stock-only sync started', [
            'limit_products' => $limitProducts,
        ]);

        try {
            $result = $service->syncInventory(function (array $event) use (&$logLines, &$successfulSkus, &$updatedTotalLive) {
                $type = $event['type'] ?? '';
                if ($type === 'updated') {
                    $updatedTotal = (int) ($event['updated_total'] ?? 0);
                    $scope = (string) ($event['scope'] ?? 'unknown');
                    $sku = (string) ($event['sku'] ?? 'n/a');
                    $qty = (int) ($event['qty'] ?? 0);
                    $line = sprintf(
                        'Synced (%s) SKU %s qty=%d [total synced=%d]',
                        $scope,
                        $sku,
                        $qty,
                        $updatedTotal
                    );

                    if ($updatedTotal <= 10 || $updatedTotal % 100 === 0) {
                        $this->line($line);
                        $logLines[] = $line;
                        Log::info('[Cashcow] ' . $line);
                    }

                    if (count($successfulSkus) < self::MAX_SUCCESS_SAMPLES) {
                        $successfulSkus[] = sprintf('%s:%s(qty=%d)', $scope, $sku, $qty);
                    }

                    $updatedTotalLive = $updatedTotal;
                    return;
                }

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
            }, $limitProducts, false, false);
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

        $syncedTotal = (int) ($result['synced_total'] ?? (($result['products_updated'] ?? 0) + ($result['variations_updated'] ?? 0)));
        if ($updatedTotalLive > $syncedTotal) {
            $syncedTotal = $updatedTotalLive;
        }

        $summary = sprintf(
            'Stock-only sync completed. Products Limit: %s, Synced Total: %d, Products: %d/%d, Variations: %d/%d, Skipped: %d, Errors: %d',
            $limitProducts ?? 'all',
            $syncedTotal,
            $result['products_updated'] ?? 0,
            $result['products_processed'] ?? 0,
            $result['variations_updated'] ?? 0,
            $result['variations_processed'] ?? 0,
            $result['skipped'] ?? 0,
            $result['errors'] ?? 0
        );

        $this->info($summary);
        Log::info('[Cashcow] ' . $summary);

        if (!empty($successfulSkus)) {
            $successLine = 'Successful SKUs (sample): ' . implode(', ', $successfulSkus);
            $this->line($successLine);
            Log::info('[Cashcow] ' . $successLine);
            $logLines[] = $successLine;
        }

        $meta = [
            'status' => 'success',
            'summary' => $summary,
            'synced_total' => $syncedTotal,
            'products_limit' => $limitProducts,
        ];

        if (!empty($result['error_samples'])) {
            $meta['errors'] = $result['error_samples'];
        }

        if (!empty($result['skipped_skus'])) {
            $meta['skipped'] = $result['skipped_skus'];
        }

        if (!empty($successfulSkus)) {
            $meta['successful_skus'] = $successfulSkus;
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
        if (!empty($meta['synced_total'])) {
            $lines[] = 'Synced total: ' . (int) $meta['synced_total'];
        }
        if (array_key_exists('products_limit', $meta)) {
            $lines[] = 'Products limit: ' . ($meta['products_limit'] ?? 'all');
        }
        if (!empty($meta['successful_skus'])) {
            $lines[] = 'Successful SKUs (sample): ' . implode(', ', $meta['successful_skus']);
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

    private function parseProductsLimit(): int|null|false
    {
        $raw = $this->option('limit-products');
        if ($raw === null || $raw === '') {
            return null;
        }

        if (!is_numeric($raw)) {
            $this->error('Invalid --limit-products value. It must be a positive integer.');
            return false;
        }

        $limit = (int) $raw;
        if ($limit <= 0) {
            $this->error('Invalid --limit-products value. It must be greater than 0.');
            return false;
        }

        return $limit;
    }
}
