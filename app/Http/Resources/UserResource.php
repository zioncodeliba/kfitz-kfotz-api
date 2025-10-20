<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'email_verified_at' => $this->email_verified_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'role' => $this->role,
            'roles' => [$this->role],
            'orders_count' => $this->when(isset($this->orders_count), (int) $this->orders_count),
            'managed_merchants_count' => $this->when(
                isset($this->managed_merchants_count),
                (int) $this->managed_merchants_count
            ),
            'managed_merchants' => $this->whenLoaded('agentMerchants', function () {
                return $this->agentMerchants->map(function ($merchant) {
                    return [
                        'id' => $merchant->id,
                        'business_name' => $merchant->business_name,
                    ];
                });
            }),
            'orders' => $this->whenLoaded('orders', function () {
                return $this->orders->map(function ($order) {
                    return [
                        'id' => $order->id,
                        'order_number' => $order->order_number,
                        'status' => $order->status,
                        'payment_status' => $order->payment_status,
                        'total' => $order->total,
                        'created_at' => $order->created_at,
                        'updated_at' => $order->updated_at,
                        'shipping_address' => $order->shipping_address,
                        'billing_address' => $order->billing_address,
                        'items' => $order->relationLoaded('items')
                            ? $order->items->map(function ($item) {
                                return [
                                    'id' => $item->id,
                                    'quantity' => $item->quantity,
                                    'unit_price' => $item->unit_price,
                                    'total_price' => $item->total_price,
                                ];
                            })
                            : [],
                    ];
                });
            }),
            'merchant' => $this->whenLoaded('merchant', function () {
                if (!$this->merchant) {
                    return null;
                }

                return [
                    'id' => $this->merchant->id,
                    'business_name' => $this->merchant->business_name,
                    'contact_name' => $this->merchant->contact_name,
                    'agent' => $this->merchant->agent ? [
                        'id' => $this->merchant->agent->id,
                        'name' => $this->merchant->agent->name,
                        'email' => $this->merchant->agent->email,
                    ] : null,
                    'plugin_sites' => $this->merchant->relationLoaded('pluginSites')
                        ? $this->merchant->pluginSites->map(function ($site) {
                            return [
                                'id' => $site->id,
                                'user_id' => $site->user_id,
                                'site_url' => $site->site_url,
                                'name' => $site->name,
                                'contact_name' => $site->contact_name,
                                'contact_phone' => $site->contact_phone,
                                'platform' => $site->platform,
                                'plugin_installed_at' => $site->plugin_installed_at,
                                'status' => $site->status,
                                'balance' => $site->balance,
                                'credit_limit' => $site->credit_limit,
                            ];
                        })
                        : [],
                ];
            }),
        ];
    }
}
