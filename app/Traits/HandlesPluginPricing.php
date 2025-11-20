<?php

namespace App\Traits;

use App\Models\Product;
use App\Models\ProductVariation;

trait HandlesPluginPricing
{
    /**
     * Pick a unit price dedicated to a specific plugin site when available.
     */
    protected function resolvePluginSiteUnitPrice(Product $product, int $siteId): ?float
    {
        if ($siteId <= 0) {
            return null;
        }

        $prices = $product->plugin_site_prices;
        if (!is_array($prices) || empty($prices)) {
            return null;
        }

        foreach ($prices as $entry) {
            if (is_object($entry)) {
                $entry = (array) $entry;
            }
            if (!is_array($entry)) {
                continue;
            }

            $entrySiteId = $entry['site_id'] ?? null;
            if ($entrySiteId === null) {
                continue;
            }

            if ((int) $entrySiteId !== $siteId) {
                continue;
            }

            if (array_key_exists('is_enabled', $entry) && !$entry['is_enabled']) {
                return null;
            }

            $rawPrice = $entry['price'] ?? null;
            if (is_numeric($rawPrice)) {
                $price = (float) $rawPrice;
                return $price >= 0 ? $price : null;
            }
        }

        return null;
    }

    /**
     * Determine whether a product is available for orders coming from a plugin site.
     */
    protected function isProductAvailableForPluginSite(Product $product, int $siteId): bool
    {
        if ($siteId <= 0) {
            return true;
        }

        $prices = $product->plugin_site_prices;
        if (!is_array($prices) || empty($prices)) {
            return true;
        }

        foreach ($prices as $entry) {
            if (is_object($entry)) {
                $entry = (array) $entry;
            }
            if (!is_array($entry)) {
                continue;
            }

            $entrySiteId = $entry['site_id'] ?? null;
            if ($entrySiteId === null) {
                continue;
            }

            if ((int) $entrySiteId !== $siteId) {
                continue;
            }

            if (!array_key_exists('is_enabled', $entry)) {
                return true;
            }

            return filter_var($entry['is_enabled'], FILTER_VALIDATE_BOOLEAN) !== false;
        }

        return true;
    }

    protected function resolveMerchantUnitPrice(Product $product, int $merchantUserId): ?float
    {
        $prices = $product->merchant_prices;
        if (!is_array($prices) || empty($prices)) {
            return null;
        }

        foreach ($prices as $entry) {
            if (is_object($entry)) {
                $entry = (array) $entry;
            }
            if (!is_array($entry)) {
                continue;
            }

            $entryMerchantId = $entry['merchant_id'] ?? null;
            if ($entryMerchantId === null) {
                continue;
            }

            if ((int) $entryMerchantId !== $merchantUserId) {
                continue;
            }

            $rawPrice = $entry['price'] ?? null;
            if (is_numeric($rawPrice)) {
                $price = (float) $rawPrice;
                return $price >= 0 ? $price : null;
            }
        }

        return null;
    }

    protected function determinePluginUnitPrice(
        Product $product,
        ?ProductVariation $variation,
        ?float $pluginPrice,
        ?float $merchantPrice
    ): array {
        $productPrice = max(0, (float) $product->getCurrentPrice());

        if ($pluginPrice !== null && $pluginPrice < 0) {
            $pluginPrice = null;
        }

        if ($merchantPrice !== null && $merchantPrice < 0) {
            $merchantPrice = null;
        }

        $basePrice = $pluginPrice ?? $merchantPrice ?? $productPrice;

        $variationPrice = null;
        if ($variation && $variation->price !== null && is_numeric($variation->price)) {
            $candidate = (float) $variation->price;
            if ($candidate >= 0) {
                $variationPrice = $candidate;
            }
        }

        $unitPrice = $basePrice;
        if ($variationPrice !== null && $variationPrice < $basePrice) {
            $unitPrice = $variationPrice;
        }

        $unitPrice = round($unitPrice, 2);
        $productPrice = round($productPrice, 2);
        if ($pluginPrice !== null) {
            $pluginPrice = round($pluginPrice, 2);
        }
        if ($merchantPrice !== null) {
            $merchantPrice = round($merchantPrice, 2);
        }
        if ($variationPrice !== null) {
            $variationPrice = round($variationPrice, 2);
        }

        return [
            'unit_price' => $unitPrice,
            'product_price' => $productPrice,
            'plugin_price' => $pluginPrice,
            'merchant_price' => $merchantPrice,
            'variation_price' => $variationPrice,
        ];
    }
}
