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
        Schema::table('merchant_payment_submissions', function (Blueprint $table) {
            if (!Schema::hasColumn('merchant_payment_submissions', 'payment_month')) {
                $table->string('payment_month', 7)->nullable()->after('currency');
                $table->index(['merchant_id', 'payment_month']);
            }
        });

        if (Schema::hasColumn('merchant_payment_submissions', 'payment_month')) {
            DB::table('merchant_payment_submissions')
                ->select('id', 'payment_month', 'submitted_at', 'created_at')
                ->orderBy('id')
                ->chunkById(500, function ($rows) {
                    foreach ($rows as $row) {
                        $month = $row->payment_month;
                        if (!$month) {
                            $date = $row->submitted_at ?? $row->created_at;
                            $month = $date ? Carbon::parse($date)->format('Y-m') : null;
                        }
                        if ($month) {
                            DB::table('merchant_payment_submissions')
                                ->where('id', $row->id)
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
        Schema::table('merchant_payment_submissions', function (Blueprint $table) {
            if (Schema::hasColumn('merchant_payment_submissions', 'payment_month')) {
                $table->dropIndex(['merchant_id', 'payment_month']);
                $table->dropColumn('payment_month');
            }
        });
    }
};
