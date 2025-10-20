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
        Schema::table('merchant_sites', function (Blueprint $table) {
            $table->string('name')->nullable()->after('site_url');
            $table->string('contact_name')->nullable()->after('name');
            $table->string('contact_phone')->nullable()->after('contact_name');
            $table->string('status')->default('active')->after('metadata');
            $table->decimal('balance', 12, 2)->default(0)->after('status');
            $table->decimal('credit_limit', 12, 2)->default(0)->after('balance');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merchant_sites', function (Blueprint $table) {
            $table->dropColumn([
                'name',
                'contact_name',
                'contact_phone',
                'status',
                'balance',
                'credit_limit',
            ]);
        });
    }
};
