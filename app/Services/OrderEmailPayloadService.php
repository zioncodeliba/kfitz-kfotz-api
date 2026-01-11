<?php

namespace App\Services;

use App\Models\Order;

class OrderEmailPayloadService
{
    public function build(Order $order): array
    {
        $order->loadMissing([
            'items.product',
            'user',
            'merchant',
            'merchant.merchant',
            'merchant.merchant.pluginSites',
            'merchantCustomer',
            'merchantSite',
            'carrier',
        ]);

        $customerDetails = $this->resolveOrderCustomerDetails($order);
        $shippingAddress = is_array($order->shipping_address) ? $order->shipping_address : [];
        $billingAddress = is_array($order->billing_address) ? $order->billing_address : [];
        $customerAddress = !empty($shippingAddress) ? $shippingAddress : $billingAddress;

        if (empty($customerAddress) && $order->merchantCustomer && is_array($order->merchantCustomer->address)) {
            $customerAddress = $order->merchantCustomer->address;
        }

        $customerStreet = data_get($customerAddress, 'street')
            ?? data_get($customerAddress, 'address')
            ?? data_get($customerAddress, 'line1')
            ?? data_get($customerAddress, 'line_1');
        $customerCity = data_get($customerAddress, 'city') ?? data_get($customerAddress, 'town');
        $customerZip = data_get($customerAddress, 'zip')
            ?? data_get($customerAddress, 'postal_code')
            ?? data_get($customerAddress, 'postcode');
        $customerCompany = data_get($customerAddress, 'company_name') ?? data_get($customerAddress, 'company');

        $merchantUser = $order->merchant;
        $merchantProfile = $merchantUser?->merchant;
        $merchantAddress = is_array($merchantProfile?->address) ? $merchantProfile->address : [];
        $merchantCity = data_get($merchantAddress, 'city');

        $site = $order->merchantSite ?? $merchantProfile?->pluginSites?->first();
        $siteUrl = $site?->site_url ?? data_get($order->source_metadata, 'site_url');
        $siteName = $site?->name
            ?? data_get($order->source_metadata, 'site_name')
            ?? $merchantProfile?->business_name
            ?? $merchantUser?->name;
        $siteDomain = $siteUrl ? parse_url($siteUrl, PHP_URL_HOST) : null;
        $siteSupportEmail = data_get($order->source_metadata, 'support_email')
            ?? $merchantProfile?->email_for_orders
            ?? $merchantUser?->email
            ?? config('mail.from.address');

        $items = $order->items;
        $itemsCount = (int) $items->sum('quantity');
        if ($itemsCount === 0) {
            $itemsCount = $items->count();
        }
        $primaryItem = $items->first();
        $productLink = data_get($primaryItem?->product_data, 'link')
            ?? data_get($primaryItem?->product_data, 'url')
            ?? data_get($primaryItem?->product_data, 'product_url');
        if (!$productLink && $siteUrl && $primaryItem?->product_sku) {
            $productLink = rtrim($siteUrl, '/') . '/products/' . rawurlencode($primaryItem->product_sku);
        }

        $currency = data_get($order->source_metadata, 'currency')
            ?? data_get($order->source_metadata, 'order.currency')
            ?? data_get($order->source_metadata, 'pricing_summary.currency')
            ?? 'ILS';
        $itemsTable = $this->buildOrderItemsTableHtml($items, $siteUrl, $currency);

        return [
            'order' => [
                'id' => $order->id,
                'number' => $order->order_number,
                'source' => $order->source,
                'status' => $order->status,
                'payment_status' => $order->payment_status,
                'subtotal' => $order->subtotal,
                'tax' => $order->tax,
                'total' => $order->total,
                'total_amount' => $order->total,
                'currency' => $currency,
                'shipping_cost' => $order->shipping_cost,
                'discount' => $order->discount,
                'created_at' => optional($order->created_at)->toIso8601String(),
                'shipping_method' => $order->shipping_method ?? $order->carrier_service_type,
                'tracking_number' => $order->tracking_number,
                'items_count' => $itemsCount,
                'products_table' => $itemsTable,
            ],
            'customer' => [
                'name' => $customerDetails['name'],
                'email' => $customerDetails['email'],
                'phone' => data_get($order->shipping_address, 'phone')
                    ?? data_get($order->billing_address, 'phone')
                    ?? optional($order->merchantCustomer)->phone,
                'address' => [
                    'street' => $customerStreet,
                    'city' => $customerCity,
                    'zip' => $customerZip,
                ],
                'company_name' => $customerCompany,
            ],
            'shipping' => [
                'type' => $order->shipping_type,
                'method' => $order->shipping_method,
                'address' => $order->shipping_address,
            ],
            'shipment' => [
                'carrier' => $order->shipping_company ?? optional($order->carrier)->name,
                'tracking_number' => $order->tracking_number,
                'shipped_at' => optional($order->shipped_at)->toIso8601String(),
            ],
            'merchant' => [
                'name' => $merchantUser?->name ?? $merchantProfile?->contact_name,
                'business_name' => $merchantProfile?->business_name,
                'email' => $merchantProfile?->email_for_orders ?? $merchantUser?->email,
                'phone' => $merchantProfile?->phone ?? $merchantUser?->phone,
                'address' => [
                    'city' => $merchantCity,
                ],
            ],
            'site' => [
                'name' => $siteName,
                'domain' => $siteDomain,
                'url' => $siteUrl,
                'support_email' => $siteSupportEmail,
            ],
            'product' => [
                'name' => $primaryItem?->product_name,
                'sku' => $primaryItem?->product_sku,
                'price' => $primaryItem?->unit_price,
                'quantity' => $primaryItem?->quantity,
                'link' => $productLink,
            ],
            'items' => $order->items->map(function ($item) {
                return [
                    'name' => $item->product_name,
                    'sku' => $item->product_sku,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'total_price' => $item->total_price,
                ];
            })->toArray(),
        ];
    }

    private function buildOrderItemsTableHtml(iterable $items, ?string $siteUrl, string $currency): string
    {
        $rows = '';
        $currencyLabel = e($currency);
        $cellStyle = 'border:1px solid #e5e7eb;padding:8px;text-align:right;';
        $headerStyle = $cellStyle . 'background-color:#f9fafb;font-weight:600;';

        foreach ($items as $item) {
            $name = trim((string) $item->product_name);
            $sku = trim((string) $item->product_sku);
            $displayName = $name !== '' ? $name : $sku;
            if ($displayName === '') {
                $displayName = 'Item';
            }

            $displayName = e($displayName);
            $link = $this->resolveItemLink($item, $siteUrl);
            if ($link !== null) {
                $displayName = sprintf(
                    '<a href="%s" style="color:#2563eb;text-decoration:none;">%s</a>',
                    e($link),
                    $displayName
                );
            }

            $quantity = (int) $item->quantity;
            $unitPrice = $this->formatMoney($item->unit_price);
            $totalPrice = $this->formatMoney($item->total_price);

            $rows .= sprintf(
                '<tr><td style="%s">%s</td><td style="%s">%s</td><td style="%s">%d</td><td style="%s">%s %s</td><td style="%s">%s %s</td></tr>',
                $cellStyle,
                $displayName,
                $cellStyle,
                e($sku),
                $cellStyle,
                $quantity,
                $cellStyle,
                $unitPrice,
                $currencyLabel,
                $cellStyle,
                $totalPrice,
                $currencyLabel
            );
        }

        if ($rows === '') {
            return '';
        }

        return sprintf(
            '<table dir="rtl" style="width:100%%;border-collapse:collapse;font-family:Arial, sans-serif;font-size:13px;line-height:1.5;">%s<tbody>%s</tbody></table>',
            sprintf(
                '<thead><tr><th style="%s">Product</th><th style="%s">SKU</th><th style="%s">Qty</th><th style="%s">Unit price</th><th style="%s">Total</th></tr></thead>',
                $headerStyle,
                $headerStyle,
                $headerStyle,
                $headerStyle,
                $headerStyle
            ),
            $rows
        );
    }

    private function resolveItemLink(object $item, ?string $siteUrl): ?string
    {
        $productData = is_array($item->product_data) ? $item->product_data : [];
        $link = data_get($productData, 'link')
            ?? data_get($productData, 'url')
            ?? data_get($productData, 'product_url');

        if (!$link && $siteUrl && $item->product_sku) {
            $link = rtrim($siteUrl, '/') . '/products/' . rawurlencode($item->product_sku);
        }

        $link = is_string($link) ? trim($link) : '';

        return $link !== '' ? $link : null;
    }

    private function formatMoney(mixed $value): string
    {
        if (!is_numeric($value)) {
            return '0.00';
        }

        return number_format((float) $value, 2, '.', ',');
    }

    private function resolveOrderCustomerDetails(Order $order): array
    {
        $name = data_get($order->shipping_address, 'name')
            ?? data_get($order->shipping_address, 'contact_name')
            ?? data_get($order->billing_address, 'name')
            ?? optional($order->merchantCustomer)->name
            ?? optional($order->user)->name;

        $email = data_get($order->shipping_address, 'email')
            ?? data_get($order->billing_address, 'email')
            ?? optional($order->merchantCustomer)->email
            ?? optional($order->user)->email;

        return [
            'name' => $name,
            'email' => $email,
        ];
    }
}
