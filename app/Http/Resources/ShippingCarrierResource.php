<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShippingCarrierResource extends JsonResource
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
            'code' => $this->code,
            'description' => $this->description,
            'api_url' => $this->when($request->user()?->hasRole('admin'), $this->api_url),
            'api_key' => $this->when($request->user()?->hasRole('admin'), $this->api_key),
            'api_secret' => $this->when($request->user()?->hasRole('admin'), $this->api_secret),
            'username' => $this->when($request->user()?->hasRole('admin'), $this->username),
            'password' => $this->when($request->user()?->hasRole('admin'), $this->password),
            'api_config' => $this->when($request->user()?->hasRole('admin'), $this->api_config),
            'service_types' => $this->service_types,
            'package_types' => $this->package_types,
            'base_rate' => $this->base_rate,
            'rate_per_kg' => $this->rate_per_kg,
            'is_active' => $this->is_active,
            'is_test_mode' => $this->when($request->user()?->hasRole('admin'), $this->is_test_mode),
            'last_sync_at' => $this->when($request->user()?->hasRole('admin'), $this->last_sync_at),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
} 