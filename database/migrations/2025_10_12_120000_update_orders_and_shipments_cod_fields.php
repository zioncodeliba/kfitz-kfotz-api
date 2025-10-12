<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'cod_payment')) {
                $table->dropColumn('cod_payment');
            }
        });

        Schema::table('shipments', function (Blueprint $table) {
            if (!Schema::hasColumn('shipments', 'cod_method')) {
                $table->string('cod_method', 32)->nullable()->after('cod_amount');
            }

            if (!Schema::hasColumn('shipments', 'shipping_units')) {
                $table->json('shipping_units')->nullable()->after('cod_method');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'cod_payment')) {
                $table->boolean('cod_payment')->default(false)->after('shipping_method');
            }
        });

        Schema::table('shipments', function (Blueprint $table) {
            if (Schema::hasColumn('shipments', 'shipping_units')) {
                $table->dropColumn('shipping_units');
            }

            if (Schema::hasColumn('shipments', 'cod_method')) {
                $table->dropColumn('cod_method');
            }
        });
    }
};
