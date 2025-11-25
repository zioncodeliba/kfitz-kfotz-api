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
        Schema::create('merchant_payment_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payment_id');
            $table->unsignedBigInteger('order_id');
            $table->decimal('amount_applied', 12, 2);
            $table->string('payment_month', 7); // YYYY-MM
            $table->timestamps();

            $table->foreign('payment_id')->references('id')->on('merchant_payments')->onDelete('cascade');
            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
            $table->unique(['payment_id', 'order_id']);
            $table->index(['payment_month']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_payment_orders');
    }
};
