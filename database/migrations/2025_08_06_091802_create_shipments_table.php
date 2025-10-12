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
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('tracking_number')->unique();
            $table->enum('status', [
                'pending',      // ממתין לטיפול
                'picked_up',    // נאסף
                'in_transit',   // בדרך
                'out_for_delivery', // יצא למסירה
                'delivered',    // נמסר
                'failed',       // נכשל
                'returned'      // הוחזר
            ])->default('pending');
            $table->string('carrier'); // דואר ישראל, בלדר, דוד הובלות, צ'יטה
            $table->enum('service_type', ['regular', 'express', 'pickup'])->default('regular');
            $table->enum('package_type', ['regular', 'oversized', 'pallet', 'box'])->default('regular');
            $table->decimal('weight', 8, 2)->nullable();
            $table->decimal('length', 8, 2)->nullable();
            $table->decimal('width', 8, 2)->nullable();
            $table->decimal('height', 8, 2)->nullable();
            $table->json('origin_address');
            $table->json('destination_address');
            $table->decimal('shipping_cost', 10, 2);
            $table->boolean('cod_payment')->default(false);
            $table->decimal('cod_amount', 10, 2)->nullable();
            $table->string('cod_method', 32)->nullable();
            $table->text('notes')->nullable();
            $table->json('tracking_events')->nullable(); // Tracking history
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('in_transit_at')->nullable();
            $table->timestamp('out_for_delivery_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamps();
            
            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
