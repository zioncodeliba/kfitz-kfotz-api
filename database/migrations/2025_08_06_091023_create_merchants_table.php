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
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique(); // Link to User model
            $table->string('business_name');
            $table->string('business_id')->unique(); // Company registration number
            $table->string('phone');
            $table->string('website')->nullable();
            $table->text('description')->nullable();
            $table->json('address');
            $table->enum('status', ['active', 'suspended', 'pending'])->default('pending');
            $table->enum('verification_status', ['pending', 'verified', 'rejected'])->default('pending');
            $table->decimal('commission_rate', 5, 2)->default(10.00); // Commission percentage
            $table->decimal('monthly_fee', 10, 2)->default(0.00);
            $table->decimal('balance', 10, 2)->default(0.00); // Current balance
            $table->decimal('credit_limit', 10, 2)->default(0.00);
            $table->json('payment_methods')->nullable(); // Accepted payment methods
            $table->json('shipping_settings')->nullable(); // Shipping preferences
            $table->json('banner_settings')->nullable(); // Banner configuration
            $table->json('popup_settings')->nullable(); // Popup configuration
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_payment_at')->nullable();
            $table->timestamps();
            
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchants');
    }
};
