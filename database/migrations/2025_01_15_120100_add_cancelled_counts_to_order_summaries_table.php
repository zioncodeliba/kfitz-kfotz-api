<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('order_summaries')) {
            Schema::table('order_summaries', function (Blueprint $table) {
                if (!Schema::hasColumn('order_summaries', 'cancelled_orders_count')) {
                    $table->unsignedInteger('cancelled_orders_count')->default(0);
                }

                if (!Schema::hasColumn('order_summaries', 'cancelled_orders_total')) {
                    $table->decimal('cancelled_orders_total', 12, 2)->default(0);
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('order_summaries')) {
            Schema::table('order_summaries', function (Blueprint $table) {
                if (Schema::hasColumn('order_summaries', 'cancelled_orders_count')) {
                    $table->dropColumn('cancelled_orders_count');
                }
                if (Schema::hasColumn('order_summaries', 'cancelled_orders_total')) {
                    $table->dropColumn('cancelled_orders_total');
                }
            });
        }
    }
};
