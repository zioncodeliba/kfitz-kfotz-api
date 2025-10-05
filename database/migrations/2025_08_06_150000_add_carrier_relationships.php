<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Add carrier_id foreign key
            $table->unsignedBigInteger('carrier_id')->nullable()->after('shipping_company');
            $table->foreign('carrier_id')->references('id')->on('shipping_carriers')->onDelete('set null');
            
            // Add carrier_service_type to store the specific service chosen
            $table->string('carrier_service_type')->nullable()->after('carrier_id');
        });

        Schema::table('shipments', function (Blueprint $table) {
            // Add carrier_id foreign key
            $table->unsignedBigInteger('carrier_id')->nullable()->after('carrier');
            $table->foreign('carrier_id')->references('id')->on('shipping_carriers')->onDelete('set null');
            
            // Add carrier_service_type to store the specific service chosen
            $table->string('carrier_service_type')->nullable()->after('carrier_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['carrier_id']);
            $table->dropColumn(['carrier_id', 'carrier_service_type']);
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->dropForeign(['carrier_id']);
            $table->dropColumn(['carrier_id', 'carrier_service_type']);
        });
    }
}; 