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
        Schema::table('merchant_payment_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('merchant_payment_orders', 'payment_month')) {
                $table->string('payment_month', 7)->nullable()->after('amount_applied');
                $table->index('payment_month');
            }
        });

        if (Schema::hasColumn('merchant_payment_orders', 'payment_month') && Schema::hasTable('merchant_payments')) {
            DB::table('merchant_payment_orders')
                ->select(
                    'merchant_payment_orders.id as mpo_id',
                    'merchant_payment_orders.payment_month',
                    'merchant_payment_orders.created_at',
                    'merchant_payments.paid_at',
                    'merchant_payments.payment_month as parent_month'
                )
                ->join('merchant_payments', 'merchant_payments.id', '=', 'merchant_payment_orders.payment_id')
                ->orderBy('merchant_payment_orders.id')
                ->chunkById(500, function ($rows) {
                    foreach ($rows as $row) {
                        $month = $row->payment_month;
                        if (!$month) {
                            if ($row->parent_month) {
                                $month = $row->parent_month;
                            } elseif ($row->paid_at) {
                                $month = Carbon::parse($row->paid_at)->format('Y-m');
                            } elseif ($row->created_at) {
                                $month = Carbon::parse($row->created_at)->format('Y-m');
                            }
                        }
                        if ($month) {
                            DB::table('merchant_payment_orders')
                                ->where('id', $row->mpo_id)
                                ->update(['payment_month' => $month]);
                        }
                    }
                }, 'merchant_payment_orders.id');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merchant_payment_orders', function (Blueprint $table) {
            if (Schema::hasColumn('merchant_payment_orders', 'payment_month')) {
                $table->dropIndex(['payment_month']);
                $table->dropColumn('payment_month');
            }
        });
    }
};
