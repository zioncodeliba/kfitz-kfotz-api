<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImportCashcowImages extends Command
{
    protected $signature = 'images:import-cashcow
                            {--chunk=100 : Number of products per chunk}
                            {--host=cdn.cashcow.co.il : Only download images from this host (suffix match)}
                            {--limit=0 : Max number of products to process}
                            {--sleep=0 : Sleep milliseconds between downloads}
                            {--dry-run : Do not write files or update database}';

    protected $description = 'Download Cashcow product images to local storage while keeping original URLs';

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $hostFilter = trim((string) $this->option('host'));
        $limit = max(0, (int) $this->option('limit'));
        $sleepMs = max(0, (int) $this->option('sleep'));
        $dryRun = (bool) $this->option('dry-run');

        $this->info(sprintf(
            'Importing images (host=%s, chunk=%d, dry-run=%s)',
            $hostFilter !== '' ? $hostFilter : 'any',
            $chunk,
            $dryRun ? 'yes' : 'no'
        ));

        $stats = [
            'products_scanned' => 0,
            'products_updated' => 0,
            'images_seen' => 0,
            'images_downloaded' => 0,
            'images_skipped' => 0,
            'images_failed' => 0,
        ];

        $query = Product::query()
            ->select(['id', 'sku', 'images'])
            ->orderBy('id');

        $query->chunkById($chunk, function ($products) use (
            &$stats,
            $hostFilter,
            $limit,
            $sleepMs,
            $dryRun
        ) {
            foreach ($products as $product) {
                if ($limit > 0 && $stats['products_scanned'] >= $limit) {
                    return false;
                }

                $stats['products_scanned']++;
                $images = $product->images;
                if (!is_array($images) || empty($images)) {
                    continue;
                }

                $result = $this->buildImagesPayload($images, $hostFilter, $dryRun, $sleepMs, $product);
                $stats['images_seen'] += $result['images_seen'];
                $stats['images_downloaded'] += $result['downloaded'];
                $stats['images_skipped'] += $result['skipped'];
                $stats['images_failed'] += $result['failed'];

                if ($result['changed']) {
                    $stats['products_updated']++;
                    if (!$dryRun) {
                        $product->forceFill(['images' => $result['images']])->save();
                    }
                }
            }

            return true;
        });

        $summary = sprintf(
            'Done. Products scanned: %d, updated: %d, images: %d, downloaded: %d, skipped: %d, failed: %d',
            $stats['products_scanned'],
            $stats['products_updated'],
            $stats['images_seen'],
            $stats['images_downloaded'],
            $stats['images_skipped'],
            $stats['images_failed']
        );

        $this->info($summary);
        Log::info('[Images] Cashcow import finished', array_merge($stats, [
            'host' => $hostFilter,
            'dry_run' => $dryRun,
        ]));

        return self::SUCCESS;
    }

    private function buildImagesPayload(
        array $images,
        string $hostFilter,
        bool $dryRun,
        int $sleepMs,
        Product $product
    ): array {
        $next = [];
        $seen = [];
        $downloaded = 0;
        $skipped = 0;
        $failed = 0;
        $imagesSeen = 0;

        foreach ($images as $image) {
            if (!is_string($image)) {
                continue;
            }

            $trimmed = trim($image);
            if ($trimmed === '') {
                continue;
            }

            $imagesSeen++;

            if ($this->isRemoteImage($trimmed, $hostFilter)) {
                $localPath = $this->ensureLocalCopy(
                    $trimmed,
                    $dryRun,
                    $sleepMs,
                    $product,
                    $downloaded,
                    $skipped,
                    $failed
                );

                if ($localPath !== null) {
                    $this->pushUnique($next, $seen, $localPath);
                }

                $this->pushUnique($next, $seen, $trimmed);
                continue;
            }

            $this->pushUnique($next, $seen, $trimmed);
        }

        return [
            'images' => $next,
            'changed' => $next !== $images,
            'images_seen' => $imagesSeen,
            'downloaded' => $downloaded,
            'skipped' => $skipped,
            'failed' => $failed,
        ];
    }

    private function ensureLocalCopy(
        string $url,
        bool $dryRun,
        int $sleepMs,
        Product $product,
        int &$downloaded,
        int &$skipped,
        int &$failed
    ): ?string {
        $extension = $this->resolveExtensionFromUrl($url);
        $relativePath = $this->buildLocalPath($url, $extension);

        if (Storage::disk('local')->exists($relativePath)) {
            $skipped++;
            return $relativePath;
        }

        if ($dryRun) {
            $skipped++;
            return $relativePath;
        }

        try {
            $response = Http::timeout(20)
                ->retry(2, 500)
                ->get($url);
        } catch (\Throwable $exception) {
            $failed++;
            Log::warning('[Images] Download failed', [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'url' => $url,
                'error' => $exception->getMessage(),
            ]);
            return null;
        }

        if (!$response->ok()) {
            $failed++;
            Log::warning('[Images] Download response failed', [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'url' => $url,
                'status' => $response->status(),
            ]);
            return null;
        }

        $body = $response->body();
        if ($body === '') {
            $failed++;
            Log::warning('[Images] Empty image body', [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'url' => $url,
            ]);
            return null;
        }

        Storage::disk('local')->put($relativePath, $body);
        $downloaded++;

        if ($sleepMs > 0) {
            usleep($sleepMs * 1000);
        }

        return $relativePath;
    }

    private function isRemoteImage(string $value, string $hostFilter): bool
    {
        if (!Str::startsWith($value, ['http://', 'https://'])) {
            return false;
        }

        if ($hostFilter === '') {
            return true;
        }

        $host = parse_url($value, PHP_URL_HOST);
        if (!$host || !is_string($host)) {
            return false;
        }

        return Str::endsWith(strtolower($host), strtolower($hostFilter));
    }

    private function resolveExtensionFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $extension = $path ? strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) : '';

        if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'], true)) {
            return $extension === 'jpeg' ? 'jpg' : $extension;
        }

        return 'jpg';
    }

    private function buildLocalPath(string $url, string $extension): string
    {
        $hash = hash('sha256', $url);
        $extension = $extension !== '' ? $extension : 'jpg';
        return 'images/cashcow/' . $hash . '.' . $extension;
    }

    private function pushUnique(array &$list, array &$seen, string $value): void
    {
        if ($value === '') {
            return;
        }

        if (isset($seen[$value])) {
            return;
        }

        $list[] = $value;
        $seen[$value] = true;
    }
}
