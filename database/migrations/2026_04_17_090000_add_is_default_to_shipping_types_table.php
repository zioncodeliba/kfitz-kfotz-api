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
        Schema::table('shipping_types', function (Blueprint $table) {
            if (!Schema::hasColumn('shipping_types', 'is_default')) {
                $table->boolean('is_default')->default(false)->after('price');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shipping_types', function (Blueprint $table) {
            if (Schema::hasColumn('shipping_types', 'is_default')) {
                $table->dropColumn('is_default');
            }
        });
    }
};
