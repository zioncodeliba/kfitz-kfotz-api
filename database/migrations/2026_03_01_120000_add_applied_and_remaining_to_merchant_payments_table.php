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
            $table->decimal('applied_amount', 12, 2)->default(0)->after('amount');
            $table->decimal('remaining_credit', 12, 2)->default(0)->after('applied_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merchant_payments', function (Blueprint $table) {
            $table->dropColumn(['applied_amount', 'remaining_credit']);
        });
    }
};
