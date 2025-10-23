<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('merchant_site_id')
                ->nullable()
                ->after('merchant_customer_id')
                ->constrained('merchant_sites')
                ->nullOnDelete();
        });

        $pluginOrders = DB::table('orders')
            ->where('source', 'plugin')
            ->whereNull('merchant_site_id')
            ->whereNotNull('source_metadata')
            ->select('id', 'source_metadata')
            ->get();

        foreach ($pluginOrders as $order) {
            $metadata = json_decode($order->source_metadata, true);
            if (!is_array($metadata) || empty($metadata)) {
                continue;
            }

            $siteId = null;

            if (isset($metadata['plugin_site']) && is_array($metadata['plugin_site'])) {
                $candidate = $metadata['plugin_site']['id'] ?? null;
                if ($candidate !== null && is_numeric($candidate)) {
                    $siteId = (int) $candidate;
                }
            }

            if ($siteId === null && isset($metadata['site_url'])) {
                $site = DB::table('merchant_sites')
                    ->where('site_url', $metadata['site_url'])
                    ->select('id')
                    ->first();
                $siteId = $site?->id;
            }

            if ($siteId === null && isset($metadata['plugin_site']['site_url'])) {
                $site = DB::table('merchant_sites')
                    ->where('site_url', $metadata['plugin_site']['site_url'])
                    ->select('id')
                    ->first();
                $siteId = $site?->id;
            }

            if ($siteId !== null) {
                DB::table('orders')
                    ->where('id', $order->id)
                    ->update(['merchant_site_id' => $siteId]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['merchant_site_id']);
            $table->dropColumn('merchant_site_id');
        });
    }
};

