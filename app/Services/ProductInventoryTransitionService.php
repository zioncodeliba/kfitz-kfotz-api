<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\Log;

class ProductInventoryTransitionService
{
    public function __construct(
        protected EmailTemplateService $emailTemplateService,
        protected CashcowProductPushService $cashcowProductPushService,
        protected ToylandInventorySyncService $toylandInventorySyncService
    ) {
    }

    public function handleOutOfStockTransition(Product $product, int|float|null $previousStock): void
    {
        if (!is_numeric($previousStock) || (float) $previousStock <= 0) {
            return;
        }

        $product->refresh();

        if ((float) ($product->stock_quantity ?? 0) > 0) {
            return;
        }

        $product->loadMissing([
            'category:id,name',
            'productVariations:id,product_id,sku,inventory,attributes,price',
        ]);

        $this->sendOutOfStockNotification($product);
        $this->syncExternalInventory($product);
    }

    protected function sendOutOfStockNotification(Product $product): void
    {
        try {
            $this->emailTemplateService->send(
                'product.out_of_stock',
                $this->buildProductOutOfStockPayload($product)
            );
        } catch (\Throwable $exception) {
            Log::warning('Failed to send product out of stock notification', [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    protected function syncExternalInventory(Product $product): void
    {
        try {
            $cashcowResult = $this->cashcowProductPushService->syncInventoryForProduct($product, false);
            if (($cashcowResult['errors'] ?? 0) > 0) {
                Log::warning('Cashcow out-of-stock sync completed with errors', [
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'result' => $cashcowResult,
                ]);
            }
        } catch (\Throwable $exception) {
            Log::warning('Cashcow out-of-stock sync failed', [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'error' => $exception->getMessage(),
            ]);
        }

        try {
            $toylandResult = $this->toylandInventorySyncService->syncProduct($product);
            if (($toylandResult['status'] ?? null) === 'failed') {
                Log::warning('Toyland out-of-stock sync completed with errors', [
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'result' => $toylandResult,
                ]);
            }
        } catch (\Throwable $exception) {
            Log::warning('Toyland out-of-stock sync failed', [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    protected function buildProductOutOfStockPayload(Product $product): array
    {
        return [
            'recipient' => $this->resolveStockNotificationRecipient(),
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'stock_quantity' => $product->stock_quantity,
                'min_stock_alert' => $product->min_stock_alert,
                'price' => $product->price,
            ],
            'category' => [
                'name' => optional($product->category)->name,
            ],
        ];
    }

    protected function resolveStockNotificationRecipient(): array
    {
        $email = config('cashcow.notify_email') ?: config('mail.from.address');

        return $email ? ['email' => $email] : [];
    }
}
