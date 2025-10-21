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
        Schema::create('merchant_customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_user_id');
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('notes')->nullable();
            $table->json('address')->nullable();
            $table->timestamps();

            $table->foreign('merchant_user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->index(['merchant_user_id', 'email']);
            $table->index(['merchant_user_id', 'phone']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('merchant_customer_id')
                ->nullable()
                ->after('merchant_id');

            $table->foreign('merchant_customer_id')
                ->references('id')
                ->on('merchant_customers')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['merchant_customer_id']);
            $table->dropColumn('merchant_customer_id');
        });

        Schema::dropIfExists('merchant_customers');
    }
};

