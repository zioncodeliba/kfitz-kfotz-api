<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('order_limit', 12, 2)->default(0)->after('role');
            $table->decimal('order_balance', 12, 2)->default(0)->after('order_limit');
        });

        // Sync existing merchant credit limit & balance data to the new user fields.
        DB::table('users')
            ->join('merchants', 'merchants.user_id', '=', 'users.id')
            ->update([
                'users.order_limit' => DB::raw('merchants.credit_limit'),
                'users.order_balance' => DB::raw('merchants.balance'),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore merchant credit limits from the user table before dropping the new columns.
        DB::table('merchants')
            ->join('users', 'users.id', '=', 'merchants.user_id')
            ->update([
                'merchants.credit_limit' => DB::raw('users.order_limit'),
                'merchants.balance' => DB::raw('users.order_balance'),
            ]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['order_limit', 'order_balance']);
        });
    }
};
