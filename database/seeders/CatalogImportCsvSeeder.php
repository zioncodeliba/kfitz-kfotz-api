<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use SplFileObject;

class CatalogImportCsvSeeder extends Seeder
{
    private string $categoriesPath = 'import/categories.csv';
    private string $productsPath = 'import/products.csv';
    private int $chunkSize = 50;
    private bool $truncateBeforeImport = false;
    private bool $logEachProduct = true;
    private array $tempFiles = [];

    public function run(): void
    {
        ini_set('memory_limit', '512M');

        $categoriesFile = storage_path('app/' . $this->categoriesPath);
        $productsFile = storage_path('app/' . $this->productsPath);

        if (!file_exists($categoriesFile)) {
            $this->command?->error("Missing categories file at {$categoriesFile}");
            return;
        }

        if (!file_exists($productsFile)) {
            $this->command?->error("Missing products file at {$productsFile}");
            return;
        }

        try {
            $categoriesFilePrepared = $this->prepareFile($categoriesFile);
            $productsFilePrepared = $this->prepareFile($productsFile);

            if ($this->truncateBeforeImport) {
                Schema::disableForeignKeyConstraints();
                Product::truncate();
                Category::truncate();
                Schema::enableForeignKeyConstraints();
            }

            [$categoryLookup, $categoryStats, $sortOrder] = $this->importCategories($categoriesFilePrepared);
            $productStats = $this->importProducts($productsFilePrepared, $categoryLookup, $sortOrder);

            $this->command?->info("Categories imported: {$categoryStats['main']} mains, {$categoryStats['sub']} subs (total {$categoryStats['total']})");
            $this->command?->info(
                "Products processed: {$productStats['processed']}, inserted: {$productStats['inserted']}, skipped: {$productStats['skipped']}, existing: {$productStats['skipped_existing']}, duplicates: {$productStats['skipped_duplicate']}"
            );

            if (!empty($productStats['missing_categories'])) {
                $missing = implode(', ', $productStats['missing_categories']);
                $this->command?->warn("Missing categories encountered (created as roots): {$missing}");
            }
        } finally {
            foreach ($this->tempFiles as $tempFile) {
                @unlink($tempFile);
            }
        }
    }

    private function importCategories(string $filePath): array
    {
        $delimiter = $this->detectDelimiter($filePath);
        $file = new SplFileObject($filePath);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
        $file->setCsvControl($delimiter);

        $lookup = [];
        $createdMain = 0;
        $createdSub = 0;
        $sortOrder = 1;

        foreach ($this->iterateCsvRows($file) as $row) {
            $main = trim((string) ($row[1] ?? ''));
            if ($main === '') {
                continue;
            }

            $parent = Category::firstOrCreate(
                ['name' => $main, 'parent_id' => null],
                [
                    'description' => null,
                    'is_active' => true,
                    'sort_order' => $sortOrder++,
                ]
            );

            if ($parent->wasRecentlyCreated) {
                $createdMain++;
            }

            $lookup[$this->normalizeKey($main)] = $parent->id;

            $subCategories = array_slice($row, 4);
            foreach ($subCategories as $sub) {
                $sub = trim((string) $sub);
                if ($sub === '') {
                    continue;
                }

                $child = Category::firstOrCreate(
                    ['name' => $sub, 'parent_id' => $parent->id],
                    [
                        'description' => null,
                        'is_active' => true,
                        'sort_order' => $sortOrder++,
                    ]
                );

                if ($child->wasRecentlyCreated) {
                    $createdSub++;
                }

                $lookup[$this->normalizeKey($sub)] = $child->id;
            }
        }

        return [
            $lookup,
            [
                'main' => $createdMain,
                'sub' => $createdSub,
                'total' => $createdMain + $createdSub,
            ],
            $sortOrder,
        ];
    }

    private function importProducts(string $filePath, array $categoryLookup, int $startingSortOrder): array
    {
        $delimiter = $this->detectDelimiter($filePath);
        $file = new SplFileObject($filePath);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
        $file->setCsvControl($delimiter);

        $headers = null;
        $batch = [];
        $processed = 0;
        $skipped = 0;
        $inserted = 0;
        $skippedExisting = 0;
        $skippedDuplicate = 0;
        $missingCategories = [];
        $sortOrder = $startingSortOrder;
        $seenSkus = [];

        foreach ($file as $row) {
            if ($row === null || $row === false) {
                continue;
            }

            if ($headers === null) {
                $headers = $this->normalizeHeaders($row);
                continue;
            }

            if ($this->rowIsEmpty($row)) {
                continue;
            }

            $row = array_slice($row, 0, count($headers));
            $row = array_pad($row, count($headers), null);
            $data = array_combine($headers, $row);

            if ($data === false) {
                $skipped++;
                $this->logProductAction('Skipped row: header mismatch');
                continue;
            }

            $data = array_change_key_case($data, CASE_LOWER);

            $sku = trim((string) ($this->value($data, 'sku') ?? ''));
            if ($sku === '') {
                $skipped++;
                $this->logProductAction('Skipped row: missing SKU');
                continue;
            }

            if (isset($seenSkus[$sku])) {
                $skippedDuplicate++;
                $this->logProductAction("Skipped duplicate SKU in CSV: {$sku}");
                continue;
            }

            $seenSkus[$sku] = true;

            $categoryName = trim((string) ($this->value($data, 'categoryname') ?? ''));
            $categoryId = $categoryName !== ''
                ? ($categoryLookup[$this->normalizeKey($categoryName)] ?? null)
                : null;

            if ($categoryId === null && $categoryName !== '') {
                $category = Category::firstOrCreate(
                    ['name' => $categoryName, 'parent_id' => null],
                    ['is_active' => true, 'sort_order' => $sortOrder++]
                );

                $categoryId = $category->id;
                $categoryLookup[$this->normalizeKey($categoryName)] = $categoryId;
                $missingCategories[$categoryName] = $categoryName;
            }

            if ($categoryId === null) {
                $missingCategories['(empty)'] = '(empty)';
                $skipped++;
                $this->logProductAction("Skipped SKU {$sku}: missing category");
                continue;
            }

            $batch[] = [
                'sku' => $sku,
                'name' => $this->cleanScalar($this->value($data, 'title')) ?: $sku,
                'description' => $this->buildDescription($data),
                'price' => $this->floatOrZero($this->value($data, 'retailprice')),
                'sale_price' => $this->floatOrNull($this->value($data, 'sellprice')),
                'cost_price' => $this->floatOrZero($this->value($data, 'costprice', 'retailprice')),
                'shipping_price' => $this->floatOrZero($this->value($data, 'shipingprice', 'shippingprice')),
                'stock_quantity' => (int) ($this->value($data, 'stock') ?? 0),
                'min_stock_alert' => 5,
                'category_id' => $categoryId,
                'is_active' => $this->toBool($this->value($data, 'isactive') ?? true),
                'is_featured' => false,
                'images' => $this->collectImages($data),
                'variations' => null,
                'weight' => $this->cleanScalar($this->value($data, 'weight')),
                'dimensions' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $processed++;

            if (count($batch) >= $this->chunkSize) {
                $batchStats = $this->insertNewProducts($batch);
                $inserted += $batchStats['inserted'];
                $skippedExisting += $batchStats['skipped_existing'];
                $batch = [];
            }
        }

        if (!empty($batch)) {
            $batchStats = $this->insertNewProducts($batch);
            $inserted += $batchStats['inserted'];
            $skippedExisting += $batchStats['skipped_existing'];
        }

        return [
            'processed' => $processed,
            'inserted' => $inserted,
            'skipped' => $skipped,
            'skipped_existing' => $skippedExisting,
            'skipped_duplicate' => $skippedDuplicate,
            'missing_categories' => array_values($missingCategories),
        ];
    }

    private function detectDelimiter(string $filePath): string
    {
        $candidates = [',', ';', "\t"];
        $best = ',';
        $bestCount = 0;

        $handle = fopen($filePath, 'r');
        if ($handle) {
            $firstLine = fgets($handle) ?: '';
            fclose($handle);

            foreach ($candidates as $candidate) {
                $count = substr_count($firstLine, $candidate);
                if ($count > $bestCount) {
                    $bestCount = $count;
                    $best = $candidate;
                }
            }
        }

        return $best;
    }

    private function iterateCsvRows(SplFileObject $file): \Generator
    {
        foreach ($file as $row) {
            if ($row === null || $row === false || $this->rowIsEmpty($row)) {
                continue;
            }

            yield $row;
        }
    }

    private function normalizeHeaders(array $headers): array
    {
        return array_map(
            static function ($header) {
                $value = (string) $header;
                // Strip BOM if exists
                $value = ltrim($value, "\xEF\xBB\xBF");

                return trim($value);
            },
            $headers
        );
    }

    private function normalizeKey(string $value): string
    {
        return mb_strtolower(trim($value));
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

    private function collectImages(array $data): ?array
    {
        $keys = ['FileName', 'FileName_1', 'FileName_2', 'FileName_3'];
        $images = [];

        foreach ($keys as $key) {
            $value = $this->cleanScalar($this->value($data, strtolower($key), $key));
            if ($value !== null) {
                $images[] = $value;
            }
        }

        return $images ?: null;
    }

    private function buildDescription(array $data): ?string
    {
        $candidates = [
            $this->value($data, 'longdescription'),
            $this->value($data, 'productshortdescription'),
            $this->value($data, 'shortdescription'),
        ];

        foreach ($candidates as $candidate) {
            $candidate = $this->cleanScalar($candidate);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = mb_strtolower(trim((string) $value));

        return !in_array($normalized, ['false', '0', 'no', ''], true);
    }

    private function floatOrZero(mixed $value): float
    {
        $number = $this->floatOrNull($value);

        return $number ?? 0.0;
    }

    private function floatOrNull(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $normalized = str_replace([',', '₪', ' '], '', (string) $value);
        if ($normalized === '') {
            return null;
        }

        $float = (float) $normalized;

        return $float > 0 ? $float : null;
    }

    private function cleanScalar(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function value(array $data, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            $normalized = mb_strtolower($key);
            if (array_key_exists($normalized, $data)) {
                return $data[$normalized];
            }
        }

        return null;
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
            $this->tempFiles[] = $tempPath;

            return $tempPath;
        }

        return $path;
    }

    private function insertNewProducts(array $batch): array
    {
        $normalizedBatch = array_map(function (array $item) {
            $item['images'] = $item['images'] !== null ? json_encode($item['images']) : null;
            $item['variations'] = $item['variations'] !== null ? json_encode($item['variations']) : null;

            return $item;
        }, $batch);

        $skus = array_values(array_unique(array_map(
            static fn (array $item) => $item['sku'],
            $normalizedBatch
        )));

        if ($skus === []) {
            return [
                'inserted' => 0,
                'skipped_existing' => 0,
            ];
        }

        $existingSkus = Product::whereIn('sku', $skus)
            ->pluck('sku')
            ->all();

        $existingLookup = array_fill_keys($existingSkus, true);
        $newBatch = [];
        $insertedSkus = [];
        $skippedExistingSkus = [];

        foreach ($normalizedBatch as $item) {
            if (isset($existingLookup[$item['sku']])) {
                $skippedExistingSkus[] = $item['sku'];
                continue;
            }

            $newBatch[] = $item;
            $insertedSkus[] = $item['sku'];
        }

        if (!empty($newBatch)) {
            Product::insert($newBatch);
        }

        foreach ($insertedSkus as $sku) {
            $this->logProductAction("Created product: {$sku}");
        }

        foreach ($skippedExistingSkus as $sku) {
            $this->logProductAction("Skipped existing product: {$sku}");
        }

        return [
            'inserted' => count($insertedSkus),
            'skipped_existing' => count($skippedExistingSkus),
        ];
    }

    private function logProductAction(string $message): void
    {
        if ($this->command && $this->logEachProduct) {
            $this->command->line($message);
        }
    }
}
