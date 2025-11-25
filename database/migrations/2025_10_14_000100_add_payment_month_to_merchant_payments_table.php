<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('merchant_payments', function (Blueprint $table) {
            if (!Schema::hasColumn('merchant_payments', 'payment_month')) {
                $table->string('payment_month', 7)->nullable()->after('currency');
                $table->index(['merchant_id', 'payment_month']);
            }
        });

        // Backfill existing rows with paid_at (fallback created_at)
        if (Schema::hasColumn('merchant_payments', 'payment_month')) {
            DB::table('merchant_payments')
                ->select('id', 'paid_at', 'created_at', 'payment_month')
                ->orderBy('id')
                ->chunkById(500, function ($payments) {
                    foreach ($payments as $payment) {
                        $month = $payment->payment_month;
                        if (!$month) {
                            $date = $payment->paid_at ?? $payment->created_at;
                            $month = $date ? Carbon::parse($date)->format('Y-m') : null;
                        }
                        if ($month) {
                            DB::table('merchant_payments')
                                ->where('id', $payment->id)
                                ->update(['payment_month' => $month]);
                        }
                    }
                });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merchant_payments', function (Blueprint $table) {
            if (Schema::hasColumn('merchant_payments', 'payment_month')) {
                $table->dropIndex(['merchant_id', 'payment_month']);
                $table->dropColumn('payment_month');
            }
        });
    }
};
