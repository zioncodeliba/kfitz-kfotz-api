<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->unsignedInteger('cancelled_orders_count')->default(0);
            $table->decimal('cancelled_orders_total', 12, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn(['cancelled_orders_count', 'cancelled_orders_total']);
        });
    }
};
