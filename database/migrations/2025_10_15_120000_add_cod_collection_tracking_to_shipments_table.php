<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            if (!Schema::hasColumn('shipments', 'cod_collected')) {
                $table->boolean('cod_collected')->default(false)->after('cod_method');
            }

            if (!Schema::hasColumn('shipments', 'cod_collected_at')) {
                $table->timestamp('cod_collected_at')->nullable()->after('cod_collected');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            if (Schema::hasColumn('shipments', 'cod_collected_at')) {
                $table->dropColumn('cod_collected_at');
            }

            if (Schema::hasColumn('shipments', 'cod_collected')) {
                $table->dropColumn('cod_collected');
            }
        });
    }
};
