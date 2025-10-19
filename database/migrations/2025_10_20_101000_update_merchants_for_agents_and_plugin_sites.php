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
        Schema::table('merchants', function (Blueprint $table) {
            $table->foreignId('agent_id')
                ->nullable()
                ->after('user_id')
                ->constrained('users')
                ->nullOnDelete();
        });

        Schema::create('merchant_sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('site_url');
            $table->string('platform')->nullable();
            $table->timestamp('plugin_installed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['merchant_id', 'site_url']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_sites');

        Schema::table('merchants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agent_id');
        });
    }
};
