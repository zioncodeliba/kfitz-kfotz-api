<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ToylandInventorySyncService
{
    public function syncProduct(Product $product): array
    {
        $product->loadMissing(['productVariations:id,product_id,sku,inventory']);

        return $this->sync($this->buildProductItems($product));
    }

    public function sync(array $items): array
    {
        $url = trim((string) config('services.toyland.inventory_sync_url'));

        if ($url === '') {
            return [
                'status' => 'skipped',
                'items_sent' => 0,
                'error' => null,
            ];
        }

        $payload = $this->normalizeItems($items);

        if (empty($payload)) {
            return [
                'status' => 'skipped',
                'items_sent' => 0,
                'error' => null,
            ];
        }

        try {
            $response = Http::asJson()
                ->timeout(30)
                ->retry(3, 1000)
                ->post($url, $payload);

            if ($response->failed()) {
                Log::warning('Toyland inventory sync failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'items_sent' => count($payload),
                ]);

                return [
                    'status' => 'failed',
                    'items_sent' => count($payload),
                    'error' => 'Toyland webhook returned HTTP ' . $response->status(),
                ];
            }
        } catch (\Throwable $exception) {
            Log::warning('Toyland inventory sync failed', [
                'error' => $exception->getMessage(),
                'items_sent' => count($payload),
            ]);

            return [
                'status' => 'failed',
                'items_sent' => count($payload),
                'error' => $exception->getMessage(),
            ];
        }

        Log::info('Toyland inventory sync completed', [
            'items_sent' => count($payload),
        ]);

        return [
            'status' => 'success',
            'items_sent' => count($payload),
            'error' => null,
        ];
    }

    private function normalizeItems(array $items): array
    {
        $normalized = [];

        foreach ($items as $item) {
            $sku = trim((string) ($item['sku'] ?? ''));
            if ($sku === '') {
                continue;
            }

            $qty = $item['qty'] ?? 0;
            if (!is_numeric($qty)) {
                $qty = 0;
            }

            $normalized[$sku] = [
                'sku' => $sku,
                'qty' => (int) round((float) $qty),
            ];
        }

        return array_values($normalized);
    }

    private function buildProductItems(Product $product): array
    {
        $items = [];
        $sku = trim((string) $product->sku);

        if ($sku !== '') {
            $items[] = [
                'sku' => $sku,
                'qty' => is_numeric($product->stock_quantity) ? (int) round((float) $product->stock_quantity) : 0,
            ];
        }

        foreach ($product->productVariations as $variation) {
            $variationSku = trim((string) $variation->sku);
            if ($variationSku === '') {
                continue;
            }

            $items[] = [
                'sku' => $variationSku,
                'qty' => is_numeric($variation->inventory) ? (int) round((float) $variation->inventory) : 0,
            ];
        }

        return $items;
    }
}
