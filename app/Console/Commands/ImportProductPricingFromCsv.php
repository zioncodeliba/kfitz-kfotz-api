<?php

namespace App\Console\Commands;

use App\Models\MerchantSite;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use SplFileObject;

class ImportProductPricingFromCsv extends Command
{
    protected $signature = 'products:import-pricing
                            {--file= : CSV path (defaults to storage/app/import/KKproductLive.csv)}
                            {--delimiter= : CSV delimiter override}
                            {--site-id=1 : Plugin site id for cashcow_price}
                            {--site-url= : Plugin site URL for lookup (optional)}
                            {--site-name= : Plugin site label override (optional)}
                            {--dry-run : Report changes without updating}';

    protected $description = 'Import product price and inventory updates from a CSV file';

    public function handle(): int
    {
        $filePath = $this->resolveFilePath((string) $this->option('file'));
        if ($filePath === null) {
            $this->error('CSV file not found.');
            return self::FAILURE;
        }

        $preparedPath = $this->prepareFile($filePath);
        $delimiter = $this->resolveDelimiter($preparedPath, (string) $this->option('delimiter'));
        $dryRun = (bool) $this->option('dry-run');

        $siteId = (int) $this->option('site-id');
        $siteUrl = trim((string) $this->option('site-url'));
        $siteName = trim((string) $this->option('site-name'));
        $site = $this->resolvePluginSite($siteId, $siteUrl);
        if ($site && $siteId <= 0) {
            $siteId = $site->id;
        }
        if ($siteName === '' && $site) {
            $siteName = $this->formatSiteLabel($site);
        }

        $allowCostPrice = Schema::hasColumn('products', 'cost_price');
        if (!$allowCostPrice) {
            $this->warn('Missing cost_price column; skipping cost_price updates.');
        }

        $allowPluginPrices = Schema::hasColumn('products', 'plugin_site_prices');
        if (!$allowPluginPrices) {
            $this->warn('Missing plugin_site_prices column; skipping cashcow_price updates.');
        }

        $file = new SplFileObject($preparedPath);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
        $file->setCsvControl($delimiter);

        $stats = [
            'rows' => 0,
            'updated' => 0,
            'skipped' => 0,
            'missing' => 0,
            'invalid' => 0,
            'plugin_price_skipped' => 0,
        ];
        $missingSkus = [];

        $headers = null;

        foreach ($file as $row) {
            if (!is_array($row) || $this->rowIsEmpty($row)) {
                continue;
            }

            if ($headers === null) {
                $headers = $this->normalizeHeaders($row);
                continue;
            }

            $stats['rows']++;

            $row = array_slice($row, 0, count($headers));
            $row = array_pad($row, count($headers), null);
            $data = array_combine($headers, $row);
            if ($data === false) {
                $stats['invalid']++;
                continue;
            }

            $data = array_change_key_case($data, CASE_LOWER);
            $sku = trim((string) ($data['sku'] ?? ''));
            if ($sku === '') {
                $stats['invalid']++;
                continue;
            }

            $product = Product::where('sku', $sku)->first();
            if (!$product) {
                $stats['missing']++;
                $missingSkus[$sku] = true;
                continue;
            }

            $updates = [];

            $price = $this->parseDecimal($data['price'] ?? null);
            if ($price !== null && (float) $product->price !== $price) {
                $updates['price'] = $price;
            }

            if ($allowCostPrice) {
                $costPrice = $this->parseDecimal($data['cost_price'] ?? null);
                if ($costPrice !== null && (float) $product->cost_price !== $costPrice) {
                    $updates['cost_price'] = $costPrice;
                }
            }

            $qty = $this->parseQuantity($data['qty'] ?? null);
            if ($qty !== null && (int) $product->stock_quantity !== $qty) {
                $updates['stock_quantity'] = $qty;
            }

            $cashcowPrice = $this->parseDecimal($data['cashcow_price'] ?? null);
            if ($cashcowPrice !== null) {
                if ($allowPluginPrices && $siteId > 0) {
                    $currentPrices = is_array($product->plugin_site_prices)
                        ? $product->plugin_site_prices
                        : [];
                    $nextPrices = $this->mergePluginSitePrice(
                        $currentPrices,
                        $siteId,
                        $cashcowPrice,
                        $siteName !== '' ? $siteName : null
                    );

                    if ($this->arraySignature($currentPrices) !== $this->arraySignature($nextPrices)) {
                        $updates['plugin_site_prices'] = $nextPrices;
                    }
                } else {
                    $stats['plugin_price_skipped']++;
                }
            }

            if (empty($updates)) {
                $stats['skipped']++;
                continue;
            }

            $stats['updated']++;
            if (!$dryRun) {
                $product->forceFill($updates)->save();
            }
        }

        $summary = sprintf(
            'Rows: %d, Updated: %d, Skipped: %d, Missing: %d, Invalid: %d, Plugin price skipped: %d',
            $stats['rows'],
            $stats['updated'],
            $stats['skipped'],
            $stats['missing'],
            $stats['invalid'],
            $stats['plugin_price_skipped']
        );

        $this->info($summary);
        if (!empty($missingSkus)) {
            $missingList = array_keys($missingSkus);
            sort($missingList, SORT_NATURAL | SORT_FLAG_CASE);
            $this->warn('Missing SKUs: ' . implode(', ', $missingList));
        }
        Log::info('[Products] Pricing import finished', array_merge($stats, [
            'file' => $filePath,
            'delimiter' => $delimiter,
            'dry_run' => $dryRun,
            'site_id' => $siteId,
            'missing_skus' => array_keys($missingSkus),
        ]));

        if ($preparedPath !== $filePath && file_exists($preparedPath)) {
            @unlink($preparedPath);
        }

        return self::SUCCESS;
    }

    private function resolveFilePath(string $input): ?string
    {
        $defaultPath = storage_path('app/import/KKproductLive.csv');
        $candidate = $input !== '' ? $input : $defaultPath;

        if (file_exists($candidate)) {
            return $candidate;
        }

        $storageCandidate = storage_path('app/' . ltrim($candidate, '/'));
        if (file_exists($storageCandidate)) {
            return $storageCandidate;
        }

        $baseCandidate = base_path($candidate);
        if (file_exists($baseCandidate)) {
            return $baseCandidate;
        }

        return null;
    }

    private function resolveDelimiter(string $path, string $override): string
    {
        if ($override !== '') {
            return $override;
        }

        $sample = fopen($path, 'rb');
        if (!$sample) {
            return ',';
        }

        $lines = [];
        for ($i = 0; $i < 5 && !feof($sample); $i++) {
            $line = fgets($sample);
            if ($line !== false && trim($line) !== '') {
                $lines[] = $line;
            }
        }
        fclose($sample);

        if (empty($lines)) {
            return ',';
        }

        $delimiters = [',', ';', "\t", '|'];
        $scores = array_fill_keys($delimiters, 0);

        foreach ($lines as $line) {
            foreach ($delimiters as $delimiter) {
                $scores[$delimiter] += substr_count($line, $delimiter);
            }
        }

        arsort($scores);
        $best = array_key_first($scores);

        return $best ?: ',';
    }

    private function normalizeHeaders(array $headers): array
    {
        return array_map(static function ($header) {
            $value = (string) $header;
            $value = ltrim($value, "\xEF\xBB\xBF");
            return trim($value);
        }, $headers);
    }

    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }
        return true;
    }

    private function parseDecimal(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $normalized = str_replace([',', '₪', ' '], '', trim((string) $value));
        if ($normalized === '') {
            return null;
        }

        if (!is_numeric($normalized)) {
            return null;
        }

        return round((float) $normalized, 2);
    }

    private function parseQuantity(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        if (!is_numeric($normalized)) {
            return null;
        }

        return (int) round((float) $normalized);
    }

    private function mergePluginSitePrice(
        array $prices,
        int $siteId,
        float $price,
        ?string $siteName
    ): array {
        $next = [];
        $found = false;

        foreach ($prices as $entry) {
            if (is_object($entry)) {
                $entry = (array) $entry;
            }
            if (!is_array($entry)) {
                $next[] = $entry;
                continue;
            }

            $entrySiteId = (int) ($entry['site_id'] ?? 0);
            if ($entrySiteId === $siteId) {
                if ($found) {
                    continue;
                }
                $entry['site_id'] = $siteId;
                $entry['price'] = $price;
                $entry['is_enabled'] = true;
                if ($siteName !== null && $siteName !== '') {
                    $entry['site_name'] = $siteName;
                }
                $next[] = $entry;
                $found = true;
                continue;
            }

            $next[] = $entry;
        }

        if (!$found) {
            $payload = [
                'site_id' => $siteId,
                'price' => $price,
                'is_enabled' => true,
            ];
            if ($siteName !== null && $siteName !== '') {
                $payload['site_name'] = $siteName;
            }
            $next[] = $payload;
        }

        return $next;
    }

    private function arraySignature(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    private function resolvePluginSite(int $siteId, string $siteUrl): ?MerchantSite
    {
        if ($siteId > 0) {
            return MerchantSite::find($siteId);
        }

        if ($siteUrl !== '') {
            return MerchantSite::where('site_url', $siteUrl)->first();
        }

        return null;
    }

    private function formatSiteLabel(MerchantSite $site): string
    {
        $parts = [];
        if ($site->name) {
            $parts[] = trim($site->name);
        }
        if ($site->site_url) {
            $parts[] = trim($site->site_url);
        }
        return implode(' · ', array_filter($parts));
    }

    private function prepareFile(string $path): string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            return $path;
        }

        $hasNulls = strpos($contents, "\x00") !== false;
        $bom = substr($contents, 0, 2);
        $isUtf16Le = $bom === "\xFF\xFE";
        $isUtf16Be = $bom === "\xFE\xFF";

        if ($hasNulls || $isUtf16Le || $isUtf16Be) {
            $sourceEncoding = $isUtf16Be ? 'UTF-16BE' : 'UTF-16LE';
            $converted = mb_convert_encoding($contents, 'UTF-8', $sourceEncoding);
            $tempPath = storage_path('app/import/_tmp_' . uniqid('', true) . '_' . basename($path));
            file_put_contents($tempPath, $converted);
            return $tempPath;
        }

        return $path;
    }
}
