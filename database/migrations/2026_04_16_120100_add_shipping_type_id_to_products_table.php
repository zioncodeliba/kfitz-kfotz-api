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
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'shipping_type_id')) {
                $table->foreignId('shipping_type_id')
                    ->nullable()
                    ->after('shipping_price')
                    ->constrained('shipping_types')
                    ->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'shipping_type_id')) {
                $table->dropConstrainedForeignId('shipping_type_id');
            }
        });
    }
};
