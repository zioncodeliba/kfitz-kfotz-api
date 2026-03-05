<?php

namespace App\Console\Commands;

use App\Services\CashcowInventorySyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class AnalyzeCashcowInventoryDiff extends Command
{
    protected $signature = 'cashcow:analyze-inventory-diff
                            {--page-size=20 : Cashcow page size for GetQty API}
                            {--max-pages= : Optional max pages to scan (for testing)}';

    protected $description = 'Analyze Cashcow inventory against local DB without applying any updates';

    public function handle(CashcowInventorySyncService $syncService): int
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
}
