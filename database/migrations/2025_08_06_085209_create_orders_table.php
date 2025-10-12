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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();
            $table->unsignedBigInteger('user_id'); // Customer
            $table->unsignedBigInteger('merchant_id')->nullable(); // Merchant who created the order
            $table->unsignedBigInteger('agent_id')->nullable(); // Agent who created the order
            $table->enum('status', [
                'pending',      // הזמנה חדשה
                'confirmed',    // אושרה
                'processing',   // בטיפול
                'shipped',      // נשלחה
                'delivered',    // נמסרה
                'cancelled',    // בוטלה
                'refunded'      // הוחזרה
            ])->default('pending');
            $table->enum('payment_status', [
                'pending',      // ממתין לתשלום
                'paid',         // שולם
                'failed',       // נכשל
                'refunded'      // הוחזר
            ])->default('pending');
            $table->decimal('subtotal', 10, 2);
            $table->decimal('tax', 10, 2)->default(0);
            $table->decimal('shipping_cost', 10, 2)->default(0);
            $table->decimal('discount', 10, 2)->default(0);
            $table->decimal('total', 10, 2);
            $table->text('notes')->nullable();
            $table->json('shipping_address')->nullable();
            $table->json('billing_address')->nullable();
            $table->string('tracking_number')->nullable();
            $table->string('shipping_company')->nullable();
            $table->enum('shipping_type', ['delivery', 'pickup'])->default('delivery');
            $table->enum('shipping_method', ['regular', 'express', 'pickup'])->default('regular');
            $table->boolean('cod_payment')->default(false);
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('merchant_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('agent_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
