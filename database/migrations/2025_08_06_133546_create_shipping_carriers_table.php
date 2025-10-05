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
        Schema::create('shipping_carriers', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // דואר ישראל, בלדר, דוד הובלות, צ'יטה
            $table->string('code')->unique(); // israel_post, balder, david_transport, cheetah
            $table->text('description')->nullable();
            $table->string('api_url')->nullable();
            $table->string('api_key')->nullable();
            $table->string('api_secret')->nullable();
            $table->string('username')->nullable();
            $table->string('password')->nullable();
            $table->json('api_config')->nullable(); // Additional API configuration
            $table->json('service_types')->nullable(); // Available service types
            $table->json('package_types')->nullable(); // Available package types
            $table->decimal('base_rate', 10, 2)->default(0);
            $table->decimal('rate_per_kg', 10, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_test_mode')->default(false);
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipping_carriers');
    }
};
