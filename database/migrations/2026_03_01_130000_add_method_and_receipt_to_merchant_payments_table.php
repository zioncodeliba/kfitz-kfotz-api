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
        Schema::table('merchant_payments', function (Blueprint $table) {
            $table->string('payment_method', 50)->nullable()->after('reference');
            $table->string('receipt_url', 500)->nullable()->after('payment_method');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merchant_payments', function (Blueprint $table) {
            $table->dropColumn(['payment_method', 'receipt_url']);
        });
    }
};
