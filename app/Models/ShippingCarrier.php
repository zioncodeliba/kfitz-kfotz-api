<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShippingCarrier extends Model
{
    protected $fillable = [
        'name',
        'code',
        'description',
        'api_url',
        'api_key',
        'api_secret',
        'username',
        'password',
        'api_config',
        'service_types',
        'package_types',
        'base_rate',
        'rate_per_kg',
        'is_active',
        'is_test_mode',
        'last_sync_at',
    ];

    protected $casts = [
        'api_config' => 'array',
        'service_types' => 'array',
        'package_types' => 'array',
        'base_rate' => 'decimal:2',
        'rate_per_kg' => 'decimal:2',
        'is_active' => 'boolean',
        'is_test_mode' => 'boolean',
        'last_sync_at' => 'datetime',
    ];

    // Relationships
    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class, 'carrier', 'code');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function shipmentsByCarrier(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    // Status methods
    public function isActive(): bool
    {
        return $this->is_active;
    }

    public function isTestMode(): bool
    {
        return $this->is_test_mode;
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeTestMode($query)
    {
        return $query->where('is_test_mode', true);
    }

    // Calculate shipping cost
    public function calculateShippingCost($weight = 0, $serviceType = 'regular'): float
    {
        $cost = $this->base_rate;
        
        if ($weight > 0) {
            $cost += ($weight * $this->rate_per_kg);
        }

        // Add service type multiplier
        $serviceMultipliers = [
            'regular' => 1.0,
            'express' => 1.5,
            'pickup' => 0.0,
        ];

        $multiplier = $serviceMultipliers[$serviceType] ?? 1.0;
        return $cost * $multiplier;
    }

    // Test API connection
    public function testConnection(): array
    {
        try {
            $response = $this->makeApiCall('test', []);
            return [
                'success' => true,
                'message' => 'Connection successful',
                'data' => $response
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    // Make API call to carrier
    public function makeApiCall($endpoint, $data = []): array
    {
        if (!$this->api_url) {
            throw new \Exception('API URL not configured');
        }

        $url = rtrim($this->api_url, '/') . '/' . ltrim($endpoint, '/');
        
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        // Add authentication headers based on carrier type
        if ($this->api_key) {
            $headers[] = 'Authorization: Bearer ' . $this->api_key;
        }

        if ($this->username && $this->password) {
            $headers[] = 'Authorization: Basic ' . base64_encode($this->username . ':' . $this->password);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception('cURL Error: ' . $error);
        }

        if ($httpCode >= 400) {
            throw new \Exception('API Error: HTTP ' . $httpCode . ' - ' . $response);
        }

        return json_decode($response, true) ?: [];
    }

    // Create shipment with carrier
    public function createShipment($shipmentData): array
    {
        return $this->makeApiCall('create-shipment', $shipmentData);
    }

    // Get tracking info from carrier
    public function getTrackingInfo($trackingNumber): array
    {
        return $this->makeApiCall('track', ['tracking_number' => $trackingNumber]);
    }

    // Update shipment status with carrier
    public function updateShipmentStatus($trackingNumber, $status): array
    {
        return $this->makeApiCall('update-status', [
            'tracking_number' => $trackingNumber,
            'status' => $status
        ]);
    }
}
