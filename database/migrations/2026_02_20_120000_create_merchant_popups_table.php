<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('merchant_popups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(false);
            $table->boolean('display_once')->default(true);
            $table->string('title')->nullable();
            $table->text('content')->nullable();
            $table->string('image_url', 2048)->nullable();
            $table->string('button_text', 100)->nullable();
            $table->timestamp('start_date')->nullable();
            $table->timestamp('end_date')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_popups');
    }
};
