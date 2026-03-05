<?php

namespace App\Console\Commands;

use App\Services\CashcowInventorySyncService;
use App\Services\CashcowProductPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class AnalyzeCashcowInventoryDiff extends Command
{
    protected $signature = 'cashcow:analyze-inventory-diff
                            {--page-size=20 : Cashcow page size for GetQty API}
                            {--max-pages= : Optional max pages to scan (for testing)}
                            {--apply-changes : Push only changed SKUs from local DB to Cashcow}
                            {--with-retry : Use retry while pushing changed SKUs to Cashcow}';

    protected $description = 'Analyze Cashcow inventory against local DB, with optional apply for changed SKUs only';

    public function handle(CashcowInventorySyncService $syncService, CashcowProductPushService $pushService): int
    {
        $pageSize = $this->parsePositiveIntOption('page-size', true);
        if ($pageSize === false) {
            return self::FAILURE;
        }

        $maxPages = $this->parsePositiveIntOption('max-pages', false);
        if ($maxPages === false) {
            return self::FAILURE;
        }

        Log::info('[Cashcow] inventory diff analyze started', [
            'page_size' => $pageSize,
            'max_pages' => $maxPages,
        ]);

        try {
            $result = $syncService->analyzeInventoryDiff(function (array $event) {
                switch ($event['type'] ?? '') {
                    case 'page':
                        $line = sprintf(
                            'Fetched page %d (%d items, page_size=%d, total_records=%s)',
                            (int) ($event['page'] ?? 0),
                            (int) ($event['items'] ?? 0),
                            (int) ($event['page_size'] ?? 0),
                            $event['total_records'] === null ? 'n/a' : (string) $event['total_records']
                        );
                        $this->line($line);
                        Log::info('[Cashcow] ' . $line);
                        break;
                    case 'page_summary':
                        $line = sprintf(
                            'Page %d summary: scanned=%d, unchanged=%d, new=%d, qty_changes=%d',
                            (int) ($event['page'] ?? 0),
                            (int) ($event['scanned'] ?? 0),
                            (int) ($event['unchanged'] ?? 0),
                            (int) ($event['new_count'] ?? 0),
                            (int) ($event['changes_count'] ?? 0)
                        );
                        $this->line($line);
                        Log::info('[Cashcow] ' . $line);
                        break;
                }
            }, $pageSize, $maxPages);
        } catch (Throwable $e) {
            $this->error('Analyze failed: ' . $e->getMessage());
            report($e);
            Log::error('[Cashcow] inventory diff analyze failed', ['exception' => $e]);
            return self::FAILURE;
        }

        $summary = sprintf(
            'Analyze completed. Pages: %d, Scanned SKUs: %d, Unchanged: %d, New from Cashcow: %d, Qty changes: %d, Duplicates: %d',
            (int) ($result['pages'] ?? 0),
            (int) ($result['scanned'] ?? 0),
            (int) ($result['unchanged'] ?? 0),
            count($result['new_from_cashcow'] ?? []),
            count($result['inventory_changes'] ?? []),
            (int) ($result['duplicates'] ?? 0)
        );

        $this->info($summary);
        Log::info('[Cashcow] ' . $summary);

        $newFromCashcow = $result['new_from_cashcow'] ?? [];
        $inventoryChanges = $result['inventory_changes'] ?? [];

        $this->line('');
        $this->line('New products from Cashcow (missing in local DB):');
        $this->line($this->toJson($newFromCashcow));

        $this->line('');
        $this->line('Inventory changes needed (local vs cashcow):');
        $this->line($this->toJson($inventoryChanges));

        $this->line('');
        $this->line('Array counts:');
        $this->line('new_from_cashcow: ' . count($newFromCashcow));
        $this->line('inventory_changes: ' . count($inventoryChanges));

        if ($this->option('apply-changes')) {
            $changedSkus = $this->extractChangedSkus($inventoryChanges);
            $this->line('');
            $this->info(sprintf(
                'Applying inventory changes to Cashcow for changed SKUs only (count=%d, with_retry=%s)',
                count($changedSkus),
                $this->option('with-retry') ? 'yes' : 'no'
            ));
            Log::info('[Cashcow] inventory diff apply started', [
                'changed_skus_count' => count($changedSkus),
                'with_retry' => (bool) $this->option('with-retry'),
            ]);

            try {
                $pushResult = $pushService->syncInventoryBySkus(
                    $changedSkus,
                    function (array $event): void {
                        $type = $event['type'] ?? '';
                        if ($type === 'updated') {
                            $line = sprintf(
                                'Applied SKU %s qty=%d (%d/%d) [request=%s]',
                                (string) ($event['sku'] ?? 'n/a'),
                                (int) ($event['qty'] ?? 0),
                                (int) ($event['position'] ?? 0),
                                (int) ($event['target_total'] ?? 0),
                                $this->formatDuration($event['request_duration_ms'] ?? null)
                            );
                            $this->line($line);
                            Log::info('[Cashcow] ' . $line);
                            return;
                        }

                        if ($type === 'missing_local') {
                            $line = sprintf(
                                'Skipped missing local SKU %s (%d/%d)',
                                (string) ($event['sku'] ?? 'n/a'),
                                (int) ($event['position'] ?? 0),
                                (int) ($event['target_total'] ?? 0)
                            );
                            $this->warn($line);
                            Log::warning('[Cashcow] ' . $line, ['event' => $event]);
                            return;
                        }

                        if ($type === 'error') {
                            $line = sprintf(
                                'Apply error SKU %s: %s (%d/%d) [request=%s]',
                                (string) ($event['sku'] ?? 'n/a'),
                                (string) ($event['message'] ?? 'unknown error'),
                                (int) ($event['position'] ?? 0),
                                (int) ($event['target_total'] ?? 0),
                                $this->formatDuration($event['request_duration_ms'] ?? null)
                            );
                            $this->error($line);
                            Log::warning('[Cashcow] ' . $line, ['event' => $event]);
                        }
                    },
                    (bool) $this->option('with-retry')
                );
            } catch (Throwable $e) {
                $this->error('Apply failed: ' . $e->getMessage());
                report($e);
                Log::error('[Cashcow] inventory diff apply failed', ['exception' => $e]);
                return self::FAILURE;
            }

            $applySummary = sprintf(
                'Apply completed. Requested: %d, Found: %d, Updated: %d, Missing local: %d, Errors: %d',
                (int) ($pushResult['requested'] ?? 0),
                (int) ($pushResult['found'] ?? 0),
                (int) ($pushResult['updated'] ?? 0),
                (int) ($pushResult['missing_local'] ?? 0),
                (int) ($pushResult['errors'] ?? 0)
            );
            $this->info($applySummary);
            Log::info('[Cashcow] ' . $applySummary);

            if (!empty($pushResult['missing_skus'])) {
                $this->line('Missing local SKUs (sample): ' . implode(', ', $pushResult['missing_skus']));
            }
            if (!empty($pushResult['error_samples'])) {
                $this->line('Apply error samples: ' . $this->toJson($pushResult['error_samples']));
            }
        }

        return self::SUCCESS;
    }

    private function parsePositiveIntOption(string $name, bool $required): int|null|false
    {
        $raw = $this->option($name);

        if ($raw === null || $raw === '') {
            if ($required) {
                $this->error("Missing --{$name} option.");
                return false;
            }

            return null;
        }

        if (!is_numeric($raw)) {
            $this->error("Invalid --{$name} value. It must be a positive integer.");
            return false;
        }

        $value = (int) $raw;
        if ($value <= 0) {
            $this->error("Invalid --{$name} value. It must be greater than 0.");
            return false;
        }

        return $value;
    }

    private function toJson(array $payload): string
    {
        return (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<int, array{sku?:mixed}> $inventoryChanges
     * @return array<int, string>
     */
    private function extractChangedSkus(array $inventoryChanges): array
    {
        $skus = [];
        $seen = [];
        foreach ($inventoryChanges as $row) {
            $sku = trim((string) ($row['sku'] ?? ''));
            if ($sku === '' || isset($seen[$sku])) {
                continue;
            }

            $skus[] = $sku;
            $seen[$sku] = true;
        }

        return $skus;
    }

    private function formatDuration(mixed $value): string
    {
        if (!is_numeric($value)) {
            return 'n/a';
        }

        return (string) ((int) $value) . 'ms';
    }
}
