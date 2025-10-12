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
        Schema::create('system_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('message')->nullable();
            $table->string('severity', 20)->default('info'); // info, warning, danger, success
            $table->string('category', 50)->nullable();
            $table->string('icon', 50)->nullable();
            $table->string('action_label', 100)->nullable();
            $table->string('action_url')->nullable();
            $table->string('audience', 20)->default('admin'); // admin, merchant, user, all
            $table->string('status', 20)->default('active'); // active, archived, resolved
            $table->boolean('is_sticky')->default(false);
            $table->boolean('is_dismissible')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_alerts');
    }
};
