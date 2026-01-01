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
