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
        Schema::create('merchant_payment_submissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('ILS');
            $table->string('payment_month', 7); // YYYY-MM
            $table->string('status', 20)->default('pending'); // pending, approved, rejected
            $table->string('reference')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->text('admin_note')->nullable();
            $table->timestamps();

            $table->foreign('merchant_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('approved_by')->references('id')->on('users')->onDelete('set null');
            $table->index(['merchant_id', 'status']);
            $table->index(['merchant_id', 'payment_month']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_payment_submissions');
    }
};
